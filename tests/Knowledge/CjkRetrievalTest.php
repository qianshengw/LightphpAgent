<?php

declare(strict_types=1);

namespace LightAgent\Tests\Knowledge;

use LightAgent\Knowledge\RetrievalDocument;
use LightAgent\Knowledge\RetrievalProvider;
use LightAgent\Knowledge\SqliteFtsRetrievalProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the CJK retrieval path of the SQLite FTS5 provider.
 *
 * The `unicode61` tokenizer treats a run of Chinese characters as ONE
 * token, so a Chinese query could only ever match the exact original
 * segment: `乌龙茶` did NOT find `用户喜欢喝乌龙茶` and the recall path
 * silently returned nothing.
 *
 * The provider now keeps a SECOND FTS5 index built with
 * `tokenize='trigram'` and routes every query that contains CJK to it. A
 * term shorter than three characters cannot be expressed as trigrams, so
 * such a query falls back to the LIKE substring scan — which also matches
 * Chinese. The unicode61 path for every other script is unchanged.
 */
final class CjkRetrievalTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/la_cjk_rag_' . bin2hex(random_bytes(4)) . '.db';
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

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeDocument(string $content, array $overrides = []): RetrievalDocument
    {
        return new RetrievalDocument(
            content: $content,
            title: $overrides['title'] ?? 'doc',
            source: 'unit-test',
            scope: $overrides['scope'] ?? 'workspace',
            ownerId: $overrides['owner_id'] ?? null,
            tenantId: $overrides['tenant_id'] ?? null,
        );
    }

    private function countCjkIndexRows(): int
    {
        $pdo = new \PDO('sqlite:' . $this->dbPath, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        return (int) $pdo->query('SELECT count(*) FROM rag_chunks_cjk')->fetchColumn();
    }

    public function test_chinese_query_that_is_a_substring_finds_the_document(): void
    {
        // THE REGRESSION: this used to return 0 results.
        $provider = $this->makeProvider();
        $docId = $provider->ingest($this->makeDocument('用户喜欢喝乌龙茶，也喜欢喝普洱茶。'));

        $results = $provider->search('乌龙茶');

        self::assertCount(1, $results);
        self::assertSame($docId, $results[0]->documentId);
        self::assertStringContainsString('乌龙茶', $results[0]->content);
        self::assertNotNull($results[0]->score);
    }

    public function test_short_chinese_query_finds_the_document(): void
    {
        // A term of one or two characters cannot be a trigram, so it takes
        // the LIKE substring fallback — which also matches Chinese.
        $provider = $this->makeProvider();
        $provider->ingest($this->makeDocument('用户喜欢喝乌龙茶，也喜欢喝普洱茶。'));

        self::assertCount(1, $provider->search('乌龙'));
        self::assertCount(1, $provider->search('茶'));
    }

    public function test_english_query_still_finds_an_english_document(): void
    {
        // The unicode61 path must not regress.
        $provider = $this->makeProvider();
        $docId = $provider->ingest($this->makeDocument('LightAgent is a lightweight agent framework'));

        $results = $provider->search('lightweight agent');

        self::assertCount(1, $results);
        self::assertSame($docId, $results[0]->documentId);
        self::assertNotNull($results[0]->score);
    }

    public function test_an_english_query_does_not_match_a_chinese_document_and_vice_versa(): void
    {
        $provider = $this->makeProvider();
        $chineseId = $provider->ingest($this->makeDocument('用户喜欢喝乌龙茶。'));
        $englishId = $provider->ingest($this->makeDocument('LightAgent is a lightweight agent framework'));

        $englishResults = $provider->search('LightAgent');
        self::assertCount(1, $englishResults);
        self::assertSame($englishId, $englishResults[0]->documentId);

        $chineseResults = $provider->search('乌龙茶');
        self::assertCount(1, $chineseResults);
        self::assertSame($chineseId, $chineseResults[0]->documentId);
    }

    public function test_the_owner_filter_applies_to_the_cjk_path(): void
    {
        $provider = $this->makeProvider();
        $aliceId = $provider->ingest($this->makeDocument('用户喜欢喝乌龙茶。', ['owner_id' => 'alice']));
        $provider->ingest($this->makeDocument('用户喜欢喝乌龙茶。', ['owner_id' => 'bob']));

        $results = $provider->search('乌龙茶', ownerId: 'alice');

        self::assertCount(1, $results);
        self::assertSame($aliceId, $results[0]->documentId);
        self::assertSame('alice', $results[0]->ownerId);
        // A foreign owner never sees the document.
        self::assertSame([], $provider->search('乌龙茶', ownerId: 'carol'));
    }

    public function test_the_scope_filter_applies_to_the_cjk_path(): void
    {
        $provider = $this->makeProvider();
        $provider->ingest($this->makeDocument('用户喜欢喝乌龙茶。', ['scope' => 'private']));

        self::assertSame([], $provider->search('乌龙茶', scope: 'workspace'));
        self::assertCount(1, $provider->search('乌龙茶', scope: 'private'));
    }

    public function test_remove_also_removes_the_cjk_index_entries(): void
    {
        $provider = $this->makeProvider();
        $docId = $provider->ingest($this->makeDocument('用户喜欢喝乌龙茶。'));

        self::assertCount(1, $provider->search('乌龙茶'));
        self::assertTrue($provider->remove($docId));
        self::assertSame([], $provider->search('乌龙茶'));
        self::assertSame(0, $this->countCjkIndexRows());
    }

    public function test_reindex_rebuilds_the_cjk_index(): void
    {
        $provider = $this->makeProvider();
        $provider->ingest($this->makeDocument('用户喜欢喝乌龙茶。'));
        self::assertSame(1, $this->countCjkIndexRows());

        // Simulate a lost trigram index, then rebuild it.
        $pdo = new \PDO('sqlite:' . $this->dbPath, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('DELETE FROM rag_chunks_cjk');
        self::assertSame(0, $this->countCjkIndexRows());

        $provider->reindex();

        self::assertSame(1, $this->countCjkIndexRows());
        self::assertCount(1, $provider->search('乌龙茶'));
    }

    public function test_provider_satisfies_the_retrieval_provider_interface(): void
    {
        self::assertInstanceOf(RetrievalProvider::class, $this->makeProvider());
    }
}
