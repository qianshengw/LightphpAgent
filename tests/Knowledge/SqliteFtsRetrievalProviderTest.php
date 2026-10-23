<?php

declare(strict_types=1);

namespace LightAgent\Tests\Knowledge;

use LightAgent\Capabilities\CapabilityRegistry;
use LightAgent\Capabilities\RuntimeContext;
use LightAgent\Knowledge\RetrievalDocument;
use LightAgent\Knowledge\SqliteFtsRetrievalProvider;
use PHPUnit\Framework\TestCase;

/**
 * Functional tests for the SQLite FTS5 retrieval provider (knowledge.py).
 */
final class SqliteFtsRetrievalProviderTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/la_rag_' . bin2hex(random_bytes(4)) . '.db';
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    private function makeProvider(): SqliteFtsRetrievalProvider
    {
        return new SqliteFtsRetrievalProvider($this->dbPath);
    }

    private function makeDocument(string $content, string $title = 'doc', array $overrides = []): RetrievalDocument
    {
        return new RetrievalDocument(
            content: $content,
            title: $title,
            source: $overrides['source'] ?? 'unit-test',
            scope: $overrides['scope'] ?? 'workspace',
            ownerId: $overrides['owner_id'] ?? null,
            tenantId: $overrides['tenant_id'] ?? null,
        );
    }

    public function test_ingest_and_list_documents(): void
    {
        $provider = $this->makeProvider();
        $docId = $provider->ingest($this->makeDocument('The quick brown fox jumps.'));

        self::assertNotSame('', $docId);
        $docs = $provider->listDocuments();
        self::assertCount(1, $docs);
        self::assertSame('The quick brown fox jumps.', $docs[0]->content);
        self::assertSame('doc', $docs[0]->title);
    }

    public function test_ingest_from_dict_shape(): void
    {
        $provider = $this->makeProvider();
        $docId = $provider->ingest(['content' => 'dict payload', 'title' => 'from-dict']);
        $docs = $provider->listDocuments();
        self::assertSame($docId, $docs[0]->documentId);
        self::assertSame('from-dict', $docs[0]->title);
    }

    public function test_search_finds_terms(): void
    {
        $provider = $this->makeProvider();
        $provider->ingest($this->makeDocument('LightAgent is a lightweight agent framework'));
        $provider->ingest($this->makeDocument('Completely unrelated cooking recipes'));

        $results = $provider->search('lightweight agent');

        self::assertGreaterThanOrEqual(1, count($results));
        self::assertStringContainsString('LightAgent', $results[0]->content);
        self::assertStringStartsWith('rag:', $results[0]->citationId);
        self::assertNotNull($results[0]->score);
    }

    public function test_search_empty_query_returns_empty(): void
    {
        $provider = $this->makeProvider();
        $provider->ingest($this->makeDocument('something'));
        self::assertSame([], $provider->search('   '));
    }

    public function test_search_invalid_limit_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeProvider()->search('x', 0);
    }

    public function test_scope_owner_tenant_filters(): void
    {
        $provider = $this->makeProvider();
        $provider->ingest($this->makeDocument('scoped alpha', overrides: [
            'scope' => 'private', 'owner_id' => 'alice', 'tenant_id' => 't1',
        ]));
        $provider->ingest($this->makeDocument('scoped beta'));

        self::assertCount(1, $provider->search('scoped', scope: 'private'));
        self::assertCount(1, $provider->search('scoped', ownerId: 'alice'));
        self::assertCount(1, $provider->search('scoped', tenantId: 't1'));
        self::assertCount(2, $provider->search('scoped'));
    }

    public function test_read_chunk(): void
    {
        $provider = $this->makeProvider();
        $docId = $provider->ingest($this->makeDocument('chunked content for reading'));

        $results = $provider->search('chunked');
        self::assertGreaterThanOrEqual(1, count($results));
        $chunkId = $results[0]->chunkId;
        self::assertStringStartsWith($docId . ':', $chunkId);

        $chunk = $provider->readChunk($chunkId);
        self::assertNotNull($chunk);
        self::assertSame('chunked content for reading', $chunk->content);

        self::assertNull($provider->readChunk($docId . ':999'));
    }

    public function test_remove_deletes_document_and_chunks(): void
    {
        $provider = $this->makeProvider();
        $docId = $provider->ingest($this->makeDocument('to be removed'));

        self::assertCount(1, $provider->listDocuments());
        self::assertTrue($provider->remove($docId));
        self::assertFalse($provider->remove($docId));
        self::assertCount(0, $provider->listDocuments());
        self::assertSame([], $provider->search('removed'));
    }

    public function test_reingest_replaces_old_chunks(): void
    {
        $provider = $this->makeProvider();
        $doc = $this->makeDocument('original text');
        $docId = $provider->ingest($doc);

        // Re-ingest same document id with new content
        $replacement = new RetrievalDocument(
            content: 'replacement text',
            title: 'doc',
            documentId: $docId,
        );
        $provider->ingest($replacement);

        self::assertCount(1, $provider->listDocuments());
        $results = $provider->search('replacement');
        self::assertGreaterThanOrEqual(1, count($results));
        self::assertSame([], $provider->search('original'));
    }

    public function test_chunking_boundaries(): void
    {
        // chunk_size=100, overlap=20 -> step 80; content of repeated words
        // spans multiple chunks so FTS matches across them.
        $provider = new SqliteFtsRetrievalProvider($this->dbPath, chunkSize: 100, chunkOverlap: 20);
        $long = trim(str_repeat('alpha beta gamma delta ', 30));
        $docId = $provider->ingest($this->makeDocument($long));

        $results = $provider->search('alpha');
        self::assertGreaterThanOrEqual(2, count($results));
        self::assertSame($docId, $results[0]->documentId);
    }

    public function test_invalid_chunk_params_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SqliteFtsRetrievalProvider($this->dbPath, chunkSize: 50);
    }

    public function test_invalid_overlap_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SqliteFtsRetrievalProvider($this->dbPath, chunkSize: 100, chunkOverlap: 100);
    }

    public function test_capability_surface_and_invoke(): void
    {
        $provider = $this->makeProvider();
        $caps = array_keys($provider->capabilities());
        self::assertSame([
            'rag.ingest', 'rag.remove', 'rag.list_documents',
            'rag.search', 'rag.read_chunk', 'rag.reindex',
        ], $caps);

        // The capability path takes the caller identity from the
        // INVOCATION CONTEXT (H-7) — the registry binds it right before the
        // provider runs. The model-supplied owner/tenant arguments are
        // ignored.
        $provider->bindContext(new RuntimeContext(userId: 'alice'));

        // invoke() dispatches by capability name (Python removeprefix("rag."))
        $docId = $provider->invoke('rag.ingest', document: [
            'content' => 'invoked content',
            'owner_id' => 'alice',
        ]);
        self::assertNotSame('', $docId);
        $results = $provider->invoke('rag.search', query: 'invoked');
        self::assertGreaterThanOrEqual(1, count($results));
        self::assertTrue($provider->invoke('rag.remove', document_id: $docId));
    }

    public function test_registry_registration_and_health(): void
    {
        $provider = $this->makeProvider();
        $registry = new CapabilityRegistry();
        $registry->register($provider);

        self::assertSame('sqlite-fts5-rag', $registry->resolve('rag.search')->name);
        $health = $provider->health();
        self::assertTrue($health->healthy);
        self::assertSame('ready', $health->status);
    }
}
