<?php

declare(strict_types=1);

namespace LightAgent\Tests\Knowledge;

use LightAgent\Capabilities\RuntimeContext;
use LightAgent\Knowledge\RetrievalDocument;
use LightAgent\Knowledge\SqliteFtsRetrievalProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the RAG findings:
 *
 *   H-7   the capability path did NO tenant isolation
 *   V4-08 the scope/owner filter ran AFTER the SQL LIMIT
 *   M-1   the store could not be used concurrently
 */
final class RagTenantIsolationTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/la_rag_iso_' . bin2hex(random_bytes(4)) . '.db';
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

    private function document(string $content, string $owner, string $tenant): RetrievalDocument
    {
        return new RetrievalDocument(
            content: $content,
            title: 'doc',
            scope: 'workspace',
            ownerId: $owner,
            tenantId: $tenant,
        );
    }

    /**
     * H-7: the capability path may not read another tenant's document.
     */
    public function test_capability_path_cannot_read_another_tenants_document(): void
    {
        $provider = $this->provider();
        $bobId = $provider->ingest($this->document('bob private knowledge', 'bob', 'tenant-b'));

        $provider->bindContext(new RuntimeContext(userId: 'alice', tenantId: 'tenant-a'));

        self::assertNull($provider->invoke('rag.read_chunk', chunk_id: $bobId . ':0'));
        self::assertFalse($provider->invoke('rag.remove', document_id: $bobId));
        self::assertCount(1, $provider->listDocuments(), 'the document must still exist');
    }

    /**
     * H-7: the model-supplied owner/tenant arguments are ignored.
     */
    public function test_model_supplied_identity_is_ignored(): void
    {
        $provider = $this->provider();
        $bobId = $provider->ingest($this->document('bob private knowledge', 'bob', 'tenant-b'));

        $provider->bindContext(new RuntimeContext(userId: 'alice', tenantId: 'tenant-a'));

        self::assertNull($provider->invoke('rag.read_chunk', chunk_id: $bobId . ':0', owner_id: 'bob'));
        self::assertFalse($provider->invoke('rag.remove', document_id: $bobId, owner_id: 'bob'));
    }

    /**
     * H-7: without an identity the read/delete FAILS CLOSED.
     */
    public function test_capability_path_fails_closed_without_an_identity(): void
    {
        $provider = $this->provider();
        $bobId = $provider->ingest($this->document('bob private knowledge', 'bob', 'tenant-b'));

        $this->expectException(\RuntimeException::class);
        $provider->invoke('rag.remove', document_id: $bobId);
    }

    /**
     * H-7: the owner can still read and delete their own document.
     */
    public function test_the_owner_can_use_their_own_document(): void
    {
        $provider = $this->provider();
        $bobId = $provider->ingest($this->document('bob private knowledge', 'bob', 'tenant-b'));

        $provider->bindContext(new RuntimeContext(userId: 'bob', tenantId: 'tenant-b'));

        $chunk = $provider->invoke('rag.read_chunk', chunk_id: $bobId . ':0');
        self::assertNotNull($chunk);
        self::assertTrue($provider->invoke('rag.remove', document_id: $bobId));
    }

    /**
     * V4-08: the ownership filter runs in SQL, so the caller's own chunk
     * is found even when the corpus is dominated by foreign chunks.
     */
    public function test_search_finds_the_callers_own_chunk_among_foreign_ones(): void
    {
        $provider = $this->provider();
        for ($i = 0; $i < 60; $i++) {
            $provider->ingest($this->document("sharedtoken foreign chunk {$i}", 'bob', 'tenant-b'));
        }
        $provider->ingest($this->document('sharedtoken alice own chunk', 'alice', 'tenant-a'));

        $hits = $provider->search('sharedtoken', 5, 'workspace', 'alice');

        self::assertCount(1, $hits);
        self::assertSame('alice', $hits[0]->ownerId);
    }

    /**
     * V4-08: through the capability path the caller's identity overrides
     * the model-supplied owner argument.
     */
    public function test_capability_search_uses_the_callers_identity(): void
    {
        $provider = $this->provider();
        for ($i = 0; $i < 60; $i++) {
            $provider->ingest($this->document("sharedtoken foreign chunk {$i}", 'bob', 'tenant-b'));
        }
        $provider->ingest($this->document('sharedtoken alice own chunk', 'alice', 'tenant-a'));

        $provider->bindContext(new RuntimeContext(userId: 'alice', tenantId: 'tenant-a'));
        $hits = $provider->invoke('rag.search', query: 'sharedtoken', limit: 5, owner_id: 'bob');

        self::assertCount(1, $hits);
        self::assertSame('alice', $hits[0]->ownerId);
    }

    /**
     * M-1: an ingest succeeds while another connection holds an open read
     * cursor.
     */
    public function test_ingest_succeeds_while_another_connection_reads(): void
    {
        $provider = $this->provider();
        $provider->ingest($this->document('seed content', 'alice', 'tenant-a'));

        $reader = new \PDO('sqlite:' . $this->dbPath, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $cursor = $reader->query('SELECT chunk_id FROM rag_chunks');
        self::assertNotFalse($cursor);
        $cursor->fetch();

        try {
            $provider->ingest($this->document('concurrent content', 'alice', 'tenant-a'));
            self::assertTrue(true);
        } finally {
            $cursor->closeCursor();
            $reader = null;
        }
    }
}
