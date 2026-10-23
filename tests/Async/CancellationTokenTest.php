<?php

declare(strict_types=1);

namespace LightAgent\Tests\Async;

use LightAgent\Async\CancellationToken;
use PHPUnit\Framework\TestCase;

final class CancellationTokenTest extends TestCase
{
    public function test_token_has_generated_id(): void
    {
        $t1 = new CancellationToken();
        $t2 = new CancellationToken();

        self::assertNotSame('', $t1->tokenId);
        self::assertNotSame($t1->tokenId, $t2->tokenId);
        self::assertNull($t1->runId);
        self::assertNull($t1->parent);
    }

    public function test_explicit_id_and_run_id(): void
    {
        $t = new CancellationToken(tokenId: 'custom', runId: 'run-1');
        self::assertSame('custom', $t->tokenId);
        self::assertSame('run-1', $t->runId);
    }

    public function test_cancel_is_once_only(): void
    {
        $t = new CancellationToken();
        self::assertFalse($t->isCancelled());
        self::assertNull($t->getReason());

        self::assertTrue($t->cancel('user abort'));
        self::assertFalse($t->cancel('second call'));

        self::assertTrue($t->isCancelled());
        self::assertSame('user abort', $t->getReason());
    }

    public function test_cancel_default_reason(): void
    {
        $t = new CancellationToken();
        $t->cancel();
        self::assertSame('cancelled', $t->getReason());
    }

    public function test_child_propagates_cancellation_from_parent(): void
    {
        $parent = new CancellationToken(runId: 'run-9');
        $child = $parent->child();

        self::assertSame($parent, $child->parent);
        self::assertSame('run-9', $child->runId);

        self::assertFalse($child->isCancelled());
        $parent->cancel('root stop');
        self::assertTrue($child->isCancelled());
        self::assertSame('root stop', $child->getReason());
    }

    public function test_child_cancel_does_not_affect_parent(): void
    {
        $parent = new CancellationToken();
        $child = $parent->child();

        $child->cancel('child only');
        self::assertFalse($parent->isCancelled());
        self::assertTrue($child->isCancelled());
    }

    public function test_child_created_after_parent_cancelled_is_cancelled_immediately(): void
    {
        $parent = new CancellationToken();
        $parent->cancel('already stopped');

        $child = $parent->child();
        self::assertTrue($child->isCancelled());
        self::assertSame('already stopped', $child->getReason());
    }

    public function test_grandchild_reason_propagation(): void
    {
        $a = new CancellationToken();
        $b = $a->child();
        $c = $b->child();

        $a->cancel('top-level');
        self::assertTrue($c->isCancelled());
        self::assertSame('top-level', $c->getReason());
    }

    public function test_subscribe_cancels_observers(): void
    {
        $source = new CancellationToken();
        $observer = new CancellationToken();
        $source->subscribe($observer, 'via subscription');

        $source->cancel('source reason');
        self::assertTrue($observer->isCancelled());
        self::assertSame('via subscription', $observer->getReason());
    }

    public function test_subscribe_after_cancel_cancels_observer_immediately(): void
    {
        $source = new CancellationToken();
        $source->cancel('done');
        $observer = new CancellationToken();

        $source->subscribe($observer);
        self::assertTrue($observer->isCancelled());
        self::assertSame('done', $observer->getReason());
    }

    public function test_accepts_keyword_named_parameter(): void
    {
        $fn = static function (string $query, ?string $userId = null): void {};
        self::assertTrue(CancellationToken::acceptsKeyword($fn, 'userId'));
        self::assertFalse(CancellationToken::acceptsKeyword($fn, 'sessionId'));
    }

    public function test_accepts_keyword_variadic(): void
    {
        $fn = static function (string ...$rest): void {};
        self::assertTrue(CancellationToken::acceptsKeyword($fn, 'anything'));
    }

    public function test_accepts_keyword_first_class_callable(): void
    {
        // strlen(string $string) — the named parameter IS "string"
        $fn = strlen(...);
        self::assertTrue(CancellationToken::acceptsKeyword($fn, 'string'));
        self::assertFalse(CancellationToken::acceptsKeyword($fn, 'length'));
    }
}
