<?php

declare(strict_types=1);

namespace LightAgent\Tests\Knowledge;

use LightAgent\Capabilities\RuntimeContext;
use LightAgent\Knowledge\SqliteFtsRetrievalProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the identity of an ingested document.
 *
 *  H-7  `rag.ingest` took the owner/tenant from the MODEL-SUPPLIED payload,
 *       so any principal could file a document under somebody else's
 *       identity. The identity has to come from the invocation context.
 *  C-6  the upsert replaces a document BY ID, so re-ingesting somebody
 *       else's document id silently DESTROYED that document — the other
 *       tenant's content disappeared with no error.
 */
final class RagIdentityBindingTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/la_rag_identity_' . bin2hex(random_bytes(4)) . '.db';
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    private function provider(): SqliteFtsRetrievalProvider
    {
        return new SqliteFtsRetrievalProvider($this->dbPath);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $content, string $documentId, array $extra = []): array
    {
        return array_merge([
            'content' => $content,
            'document_id' => $documentId,
            'scope' => 'workspace',
        ], $extra);
    }

    /**
     * H-7: the stored document carries the CALLER's identity, not the
     * payload's.
     */
    public function test_ingest_stamps_the_callers_identity_on_the_document(): void
    {
        $provider = $this->provider();
        $provider->bindContext(new RuntimeContext(userId: 'alice', tenantId: 'tenant-a'));

        $provider->invoke('rag.ingest', document: $this->payload('alice knowledge', 'doc-1'));

        $documents = $provider->listDocuments();
        self::assertCount(1, $documents);
        self::assertSame('alice', $documents[0]->ownerId);
        self::assertSame('tenant-a', $documents[0]->tenantId);
    }

    /**
     * H-7: a payload that names ANOTHER owner is refused, not rewritten.
     */
    public function test_ingest_refuses_to_file_a_document_under_another_owner(): void
    {
        $provider = $this->provider();
        $provider->bindContext(new RuntimeContext(userId: 'alice', tenantId: 'tenant-a'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/refusing to ingest document/');
        $provider->invoke('rag.ingest', document: $this->payload('spoofed', 'doc-2', [
            'owner_id' => 'bob',
        ]));
    }

    /**
     * H-7: the same holds for the tenant.
     */
    public function test_ingest_refuses_to_file_a_document_in_another_tenant(): void
    {
        $provider = $this->provider();
        $provider->bindContext(new RuntimeContext(userId: 'alice', tenantId: 'tenant-a'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/refusing to ingest document/');
        $provider->invoke('rag.ingest', document: $this->payload('spoofed', 'doc-3', [
            'tenant_id' => 'tenant-b',
        ]));
    }

    /**
     * H-7: the refused document must NOT be stored at all.
     */
    public function test_a_refused_ingest_stores_nothing(): void
    {
        $provider = $this->provider();
        $provider->bindContext(new RuntimeContext(userId: 'alice', tenantId: 'tenant-a'));

        try {
            $provider->invoke('rag.ingest', document: $this->payload('spoofed', 'doc-4', [
                'owner_id' => 'bob',
            ]));
            self::fail('the spoofed ingest must have been refused');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertSame([], $provider->listDocuments());
    }

    /**
     * C-6: a document may not be replaced across the owner boundary — and
     * the original content must survive the attempt.
     */
    public function test_a_document_cannot_be_overwritten_across_owners(): void
    {
        $provider = $this->provider();
        $provider->ingest(new \LightAgent\Knowledge\RetrievalDocument(
            content: 'alice original content',
            documentId: 'shared-id',
            ownerId: 'alice',
            tenantId: 'tenant-a',
        ));

        $provider->bindContext(new RuntimeContext(userId: 'bob', tenantId: 'tenant-b'));

        try {
            $provider->invoke('rag.ingest', document: $this->payload('bob replacement', 'shared-id'));
            self::fail('the cross-owner overwrite must have been refused');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('different owner/tenant', $error->getMessage());
        }

        // alice's document is still there, with her content.
        $documents = $provider->listDocuments();
        self::assertCount(1, $documents);
        self::assertSame('alice', $documents[0]->ownerId);
        self::assertSame('alice original content', $documents[0]->content);
    }

    /**
     * C-6: the same owner may replace their own document.
     */
    public function test_the_same_owner_can_replace_their_own_document(): void
    {
        $provider = $this->provider();
        $provider->ingest(new \LightAgent\Knowledge\RetrievalDocument(
            content: 'first version',
            documentId: 'own-id',
            ownerId: 'alice',
            tenantId: 'tenant-a',
        ));

        $provider->bindContext(new RuntimeContext(userId: 'alice', tenantId: 'tenant-a'));
        $provider->invoke('rag.ingest', document: $this->payload('second version', 'own-id'));

        $documents = $provider->listDocuments();
        self::assertCount(1, $documents);
        self::assertSame('second version', $documents[0]->content);
    }
}
