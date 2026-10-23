<?php

declare(strict_types=1);

namespace LightAgent\Tests\Memory;

use LightAgent\Memory\SharedMemoryPool;
use LightAgent\Protocol\MemoryPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the shared memory findings:
 *
 *   M-28  a stopword-only query returned EVERY stored memory
 *   V5-46 the pool had no capacity limit and no eviction
 *   V5-45 the duplicate check was skipped when no fingerprints were given
 */
final class MemoryPoolHardeningTest extends TestCase
{
    /**
     * M-28: a query made only of stopwords carries no signal.
     */
    public function test_stopword_only_query_returns_no_results(): void
    {
        $pool = new SharedMemoryPool();
        $pool->store('peanuts and coffee', 'alice');
        $pool->store('tea and biscuits', 'alice');

        self::assertSame([], $pool->retrieve('to', 'alice')['results']);
        self::assertSame([], $pool->retrieve('the', 'alice')['results']);
        self::assertSame([], $pool->retrieve('the of and', 'alice')['results']);
        self::assertSame([], $pool->retrieve('', 'alice')['results']);
    }

    /**
     * M-28: the normal token-overlap behaviour is unchanged.
     */
    public function test_meaningful_query_still_matches(): void
    {
        $pool = new SharedMemoryPool();
        $pool->store('peanuts and coffee', 'alice');
        $pool->store('tea and biscuits', 'alice');

        $results = $pool->retrieve('peanuts', 'alice')['results'];

        self::assertCount(1, $results);
        self::assertSame('peanuts and coffee', $results[0]['memory']);
    }

    /**
     * V5-46: the pool is bounded and evicts the OLDEST records.
     */
    public function test_pool_is_bounded_and_evicts_the_oldest_records(): void
    {
        $pool = new SharedMemoryPool(maxRecords: 3);
        foreach (['one', 'two', 'three', 'four'] as $memory) {
            $pool->store($memory, 'alice');
        }

        self::assertSame(3, $pool->count());
        $records = $pool->listRecords();
        self::assertSame('two', $records[0]['memory']);
        self::assertSame('four', $records[2]['memory']);
    }

    public function test_invalid_max_records_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SharedMemoryPool(maxRecords: 0);
    }

    /**
     * V5-45: a configured duplicate policy must not be silently skipped.
     */
    public function test_duplicate_policy_is_not_skipped_without_fingerprints(): void
    {
        $policy = new MemoryPolicy(rejectDuplicateWrites: true);

        $decision = $policy->allowsWrite('the user prefers dark mode', ['memory_user_id' => 'alice']);

        self::assertFalse($decision->allowed);
        self::assertStringContainsString('Duplicate', (string) $decision->reason);
    }

    /**
     * V5-45: with the fingerprints the duplicate check works as before.
     */
    public function test_duplicate_policy_blocks_a_known_fingerprint(): void
    {
        $policy = new MemoryPolicy(rejectDuplicateWrites: true);
        $context = ['memory_user_id' => 'alice'];
        $fingerprints = [
            MemoryPolicy::writeFingerprint('the user prefers dark mode', $context) => true,
        ];

        $decision = $policy->allowsWrite('the user prefers dark mode', $context, recentFingerprints: $fingerprints);

        self::assertFalse($decision->allowed);
        self::assertSame('Duplicate memory write blocked.', $decision->reason);
    }

    /**
     * V5-45: without the duplicate policy the write is not blocked.
     */
    public function test_writes_are_allowed_without_the_duplicate_policy(): void
    {
        $policy = new MemoryPolicy();

        $decision = $policy->allowsWrite('the user prefers dark mode', ['memory_user_id' => 'alice']);

        self::assertTrue($decision->allowed);
    }

    /**
     * V5-14: the scoped id is what the agent uses on BOTH sides.
     */
    public function test_scoped_user_id_is_used_on_both_sides(): void
    {
        $policy = new MemoryPolicy(namespace: 'tenant-a');
        $scoped = $policy->scopedUserId('alice');

        self::assertSame('tenant-a:alice', $scoped);

        $pool = new SharedMemoryPool();
        $pool->store('the user prefers dark mode', $scoped);

        self::assertCount(1, $pool->retrieve('dark mode', $scoped)['results']);
        self::assertSame([], $pool->retrieve('dark mode', 'alice')['results']);
    }
}
