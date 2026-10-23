<?php

declare(strict_types=1);

namespace LightAgent\Tests\Memory;

use LightAgent\Memory\SharedMemoryPool;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the CJK recall path of the shared memory pool.
 *
 * CJK has no word boundaries, so a Chinese sentence used to be ONE token
 * and only an identical query could match it: `乌龙茶` did NOT recall
 * `用户喜欢喝乌龙茶` and the whole recall path silently returned nothing.
 *
 * Every CJK run now also contributes its character bigrams, so the two
 * texts share `乌龙` and `龙茶`. The English path is unchanged, and a
 * query that tokenises to an EMPTY set still returns nothing (M-28).
 */
final class CjkMemoryRecallTest extends TestCase
{
    public function test_chinese_memory_is_recalled_by_a_shorter_chinese_query(): void
    {
        // THE REGRESSION: this used to return 0 results.
        $pool = new SharedMemoryPool();
        $pool->store('用户喜欢喝乌龙茶', 'alice');

        $results = $pool->retrieve('乌龙茶', 'alice')['results'];

        self::assertCount(1, $results);
        self::assertSame('用户喜欢喝乌龙茶', $results[0]['memory']);
        self::assertSame('alice', $results[0]['user_id']);
        self::assertSame('user', $results[0]['metadata']['source']);
    }

    public function test_chinese_memory_is_not_recalled_by_an_unrelated_chinese_query(): void
    {
        $pool = new SharedMemoryPool();
        $pool->store('用户喜欢喝乌龙茶', 'alice');

        self::assertSame([], $pool->retrieve('明天天气如何', 'alice')['results']);
    }

    public function test_chinese_recall_stays_inside_the_user_boundary(): void
    {
        $pool = new SharedMemoryPool();
        $pool->store('用户喜欢喝乌龙茶', 'alice');
        $pool->store('用户喜欢喝乌龙茶', 'bob');

        self::assertCount(1, $pool->retrieve('乌龙茶', 'alice')['results']);
        self::assertCount(1, $pool->retrieve('乌龙茶', 'bob')['results']);
        self::assertSame([], $pool->retrieve('乌龙茶', 'carol')['results']);
    }

    public function test_the_english_path_is_unchanged(): void
    {
        $pool = new SharedMemoryPool();
        $pool->store('The user prefers peanuts.', 'alice');

        $results = $pool->retrieve('peanuts', 'alice')['results'];

        self::assertCount(1, $results);
        self::assertSame('The user prefers peanuts.', $results[0]['memory']);
    }

    public function test_a_stopword_only_query_returns_nothing(): void
    {
        // M-28: a query that tokenises to an EMPTY set (empty, or made
        // only of stopwords) carries no signal, so it recalls nothing.
        $pool = new SharedMemoryPool();
        $pool->store('first', 'alice');
        $pool->store('second', 'alice');

        self::assertSame([], $pool->retrieve('', 'alice')['results']);
        self::assertSame([], $pool->retrieve('to', 'alice')['results']);
        self::assertSame([], $pool->retrieve('the of and', 'alice')['results']);
        self::assertCount(2, $pool->listRecords(userId: 'alice'));
    }
}
