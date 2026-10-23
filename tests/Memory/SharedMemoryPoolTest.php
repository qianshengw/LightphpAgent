<?php

declare(strict_types=1);

namespace LightAgent\Tests\Memory;

use LightAgent\Memory\SharedMemoryPool;
use LightAgent\Protocol\MemoryScope;
use PHPUnit\Framework\TestCase;

/**
 * Functional tests for the shared memory pool (shared_memory.py semantics).
 */
final class SharedMemoryPoolTest extends TestCase
{
    public function test_store_returns_receipt_and_counts(): void
    {
        $pool = new SharedMemoryPool(agentName: 'agent-a');
        $receipt = $pool->store('hello world', 'alice');

        self::assertTrue($receipt['stored']);
        self::assertSame('alice', $receipt['user_id']);
        self::assertNotSame('', $receipt['memory_id']);
        self::assertSame(1, $pool->count());
    }

    public function test_scopes_records_by_user_with_default_metadata(): void
    {
        // Python: test_shared_memory_pool_scopes_records_by_user
        $pool = new SharedMemoryPool(agentName: 'writer');
        $pool->store('Alice prefers quiet beach towns', 'alice');
        $pool->store('Bob prefers crowded ski resorts', 'bob');

        $results = $pool->retrieve('quiet beach', 'alice')['results'];

        self::assertSame(['Alice prefers quiet beach towns'], array_column($results, 'memory'));
        self::assertSame('user', $results[0]['metadata']['source']);
        self::assertSame('user', $results[0]['metadata']['scope']);
        self::assertSame('writer', $results[0]['metadata']['agent_name']);
        self::assertSame('alice', $results[0]['metadata']['user_id']);
    }

    public function test_append_first_and_orders_by_overlap(): void
    {
        // Python: test_shared_memory_pool_is_append_first_and_orders_by_overlap
        $pool = new SharedMemoryPool(maxResults: 2);
        $first = $pool->store('Python graph extraction tests', 'alice');
        $second = $pool->store('Python Python graph graph extraction', 'alice');

        $results = $pool->retrieve('python graph', 'alice')['results'];

        self::assertSame(2, $pool->count());
        self::assertNotSame($first['memory_id'], $second['memory_id']);
        self::assertSame('Python Python graph graph extraction', $results[0]['memory']);
        self::assertSame('Python graph extraction tests', $results[1]['memory']);
    }

    public function test_filters_by_provenance(): void
    {
        // Python: test_shared_memory_pool_filters_by_provenance
        $pool = new SharedMemoryPool();
        $pool->store('User approved product name', 'tenant:alice', metadata: [
            'source' => 'user', 'scope' => 'user', 'agent_name' => 'writer',
        ]);
        $pool->store('Reflection draft should stay private', 'tenant:alice', metadata: [
            'source' => 'reflection', 'scope' => 'agent', 'agent_name' => 'writer',
        ]);

        $results = $pool->retrieve(
            'product name reflection',
            'tenant:alice',
            source: 'user',
            scope: 'user',
            agentName: 'writer',
        )['results'];

        self::assertSame(['User approved product name'], array_column($results, 'memory'));
    }

    public function test_retrieve_ranks_by_token_overlap(): void
    {
        $pool = new SharedMemoryPool();
        $pool->store('the user likes php programming', 'alice');
        $pool->store('weather report for tomorrow', 'alice');
        $pool->store('php frameworks are great', 'alice');

        $result = $pool->retrieve('php', 'alice');

        self::assertGreaterThanOrEqual(1, count($result['results']));
        self::assertStringContainsString('php', $result['results'][0]['memory']);
    }

    public function test_retrieve_returns_empty_for_no_match(): void
    {
        $pool = new SharedMemoryPool();
        $pool->store('unrelated content here', 'alice');

        $result = $pool->retrieve('quantum', 'alice');

        self::assertSame([], $result['results']);
    }

    public function test_retrieve_without_meaningful_terms_returns_nothing(): void
    {
        // M-28: a query that tokenises to an EMPTY set (empty, or made
        // only of stopwords) carries no signal. Returning every stored
        // memory for it was relevance-blind; `listRecords()` is the way to
        // enumerate the stored memories.
        $pool = new SharedMemoryPool();
        $pool->store('first', 'alice');
        $pool->store('second', 'alice');

        self::assertSame([], $pool->retrieve('', 'alice')['results']);
        self::assertSame([], $pool->retrieve('to', 'alice')['results']);
        self::assertSame([], $pool->retrieve('the of and', 'alice')['results']);
        self::assertCount(2, $pool->listRecords(userId: 'alice'));
    }

    public function test_user_id_isolation(): void
    {
        $pool = new SharedMemoryPool();
        $pool->store('alice memory', 'alice');
        $pool->store('bob memory', 'bob');

        $forAlice = $pool->retrieve('memory', 'alice');
        $forBob = $pool->retrieve('memory', 'bob');

        self::assertCount(1, $forAlice['results']);
        self::assertSame('alice memory', $forAlice['results'][0]['memory']);
        self::assertSame('bob memory', $forBob['results'][0]['memory']);
    }

    public function test_provenance_filters(): void
    {
        $pool = new SharedMemoryPool(agentName: 'writer');
        $pool->store('user note', 'alice');
        $pool->store('reflection note', 'agent', metadata: ['source' => 'reflection', 'scope' => 'agent']);

        self::assertCount(2, $pool->listRecords());
        self::assertCount(1, $pool->listRecords(source: 'reflection'));
        self::assertCount(1, $pool->listRecords(agentName: 'writer', scope: 'user'));
    }

    public function test_scope_object_overrides_defaults(): void
    {
        $pool = new SharedMemoryPool(defaultSource: 'user', defaultScope: 'user');
        $scope = MemoryScope::reflection(agentName: 'critic', traceId: 't1');
        $pool->store('internal thought', 'agent', scope: $scope);

        $records = $pool->listRecords(scope: 'agent');
        self::assertCount(1, $records);
        self::assertSame('reflection', $records[0]['metadata']['source']);
        self::assertSame('critic', $records[0]['metadata']['agent_name']);
        self::assertSame('t1', $records[0]['metadata']['trace_id']);
    }

    public function test_max_results_cap(): void
    {
        $pool = new SharedMemoryPool(maxResults: 2);
        for ($i = 0; $i < 5; $i++) {
            $pool->store("memory item {$i}", 'alice');
        }

        $result = $pool->retrieve('memory item', 'alice');

        self::assertCount(2, $result['results']);
    }

    public function test_explicit_limit_overrides_default(): void
    {
        $pool = new SharedMemoryPool(maxResults: 2);
        for ($i = 0; $i < 5; $i++) {
            $pool->store("memory item {$i}", 'alice');
        }

        $result = $pool->retrieve('memory item', 'alice', limit: 4);

        self::assertCount(4, $result['results']);
    }

    public function test_invalid_max_results_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SharedMemoryPool(maxResults: 0);
    }

    public function test_clear_all_and_by_user(): void
    {
        $pool = new SharedMemoryPool();
        $pool->store('a', 'alice');
        $pool->store('b', 'bob');

        self::assertSame(1, $pool->clear('alice'));
        self::assertSame(1, $pool->count());

        $pool->store('c', 'carol');
        // after clearing alice, pool holds bob + carol = 2
        self::assertSame(2, $pool->clear());
        self::assertSame(0, $pool->count());
    }

    public function test_record_shape_matches_memory_protocol(): void
    {
        $pool = new SharedMemoryPool();
        $pool->store('note', 'alice');

        $result = $pool->retrieve('note', 'alice');
        $record = $result['results'][0];

        self::assertArrayHasKey('memory', $record);
        self::assertArrayHasKey('user_id', $record);
        self::assertArrayHasKey('metadata', $record);
        self::assertArrayHasKey('memory_id', $record);
        self::assertArrayHasKey('created_at', $record);
        self::assertSame('note', $record['memory']);
        self::assertSame('alice', $record['user_id']);
    }
}
