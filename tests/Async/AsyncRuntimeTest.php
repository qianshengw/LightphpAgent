<?php

declare(strict_types=1);

namespace LightAgent\Tests\Async;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredFuture;
use LightAgent\Async\AsyncMutex;
use LightAgent\Async\AsyncRuntime;
use LightAgent\Async\AsyncTimeoutException;
use PHPUnit\Framework\TestCase;
use function Amp\async;
use function Amp\delay;

final class AsyncRuntimeTest extends TestCase
{
    public function test_run_executes_closure_and_returns_value(): void
    {
        $result = AsyncRuntime::run(static fn (): int => 42);
        self::assertSame(42, $result);
    }

    public function test_run_propagates_exception(): void
    {
        $this->expectException(\LogicException::class);
        AsyncRuntime::run(static function (): never {
            throw new \LogicException('boom');
        });
    }

    public function test_gather_returns_results_in_order(): void
    {
        $results = AsyncRuntime::run(static function (): array {
            return AsyncRuntime::gather([
                static function (): int {
                    delay(0.03);
                    return 1;
                },
                static function (): int {
                    delay(0.01);
                    return 2;
                },
                static function (): int {
                    return 3;
                },
            ]);
        });

        self::assertSame([1, 2, 3], $results);
    }

    public function test_gather_runs_concurrently(): void
    {
        $elapsed = AsyncRuntime::run(static function (): float {
            $started = AsyncRuntime::now();
            // 3 x 50ms delays run concurrently -> total ~50ms, not 150ms
            AsyncRuntime::gather([
                static function (): void { delay(0.05); },
                static function (): void { delay(0.05); },
                static function (): void { delay(0.05); },
            ]);
            return AsyncRuntime::now() - $started;
        });

        self::assertLessThan(0.14, $elapsed, 'gather should run closures concurrently, got ' . $elapsed . 's');
        self::assertGreaterThanOrEqual(0.045, $elapsed);
    }

    public function test_gather_propagates_first_exception(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('inner failure');

        AsyncRuntime::run(static function (): void {
            AsyncRuntime::gather([
                static function (): never {
                    throw new \RuntimeException('inner failure');
                },
                static function (): int {
                    delay(0.5);
                    return 1;
                },
            ]);
        });
    }

    public function test_wait_for_returns_value_before_timeout(): void
    {
        $result = AsyncRuntime::run(static fn () => AsyncRuntime::waitFor(
            static function (): string {
                delay(0.01);
                return 'ok';
            },
            1.0,
        ));
        self::assertSame('ok', $result);
    }

    public function test_wait_for_times_out(): void
    {
        $result = AsyncRuntime::run(static function (): string {
            try {
                AsyncRuntime::waitFor(static function (): never {
                    delay(5);
                }, 0.05);
            } catch (AsyncTimeoutException $exc) {
                self::assertStringContainsString('timed out after 0.05s', $exc->getMessage());
                return 'timeout-caught';
            }
            return 'no-timeout';
        });

        self::assertSame('timeout-caught', $result);
    }

    public function test_wait_for_null_timeout_runs_inline(): void
    {
        $result = AsyncRuntime::waitFor(static fn (): int => 7);
        self::assertSame(7, $result);
    }

    /**
     * M-20/V5-24: asyncio.wait_for cancels the inner operation, not only
     * the wait. The closure receives an Amp\Cancellation and must not run
     * its side effect after the deadline.
     */
    public function test_wait_for_cancels_the_inner_operation(): void
    {
        $sideEffect = false;

        AsyncRuntime::run(static function () use (&$sideEffect): void {
            try {
                AsyncRuntime::waitFor(static function (Cancellation $cancellation) use (&$sideEffect): void {
                    delay(0.4, cancellation: $cancellation);
                    $sideEffect = true;
                }, 0.05);
                self::fail('expected an AsyncTimeoutException');
            } catch (AsyncTimeoutException) {
                // expected
            }
            // Give the (cancelled) inner operation the time it would have
            // needed to fire.
            delay(0.6);
        });

        self::assertFalse($sideEffect, 'the timed out operation must be cancelled, not left running');
    }

    /**
     * M-20/V5-24: a cancellation raised BY the operation itself must not be
     * reported as a timeout.
     */
    public function test_wait_for_does_not_misclassify_operation_cancellation(): void
    {
        $caught = null;

        AsyncRuntime::run(static function () use (&$caught): void {
            try {
                AsyncRuntime::waitFor(static function (): never {
                    throw new CancelledException();
                }, 5.0);
            } catch (\Throwable $e) {
                $caught = $e;
            }
        });

        self::assertInstanceOf(CancelledException::class, $caught);
        self::assertNotInstanceOf(AsyncTimeoutException::class, $caught);
    }

    public function test_sleep_does_not_block_loop_others_progress(): void
    {
        $result = AsyncRuntime::run(static function (): int {
            $done = 0;
            AsyncRuntime::async(static function () use (&$done): void {
                delay(0.02);
                $done++;
            });
            AsyncRuntime::sleep(0.05);
            return $done;
        });
        self::assertSame(1, $result);
    }

    public function test_to_thread_executes_inline(): void
    {
        $result = AsyncRuntime::toThread(static fn (): int => 5 * 5);
        self::assertSame(25, $result);
    }

    public function test_now_is_monotonic(): void
    {
        $a = AsyncRuntime::now();
        delay(0.01, false);
        $b = AsyncRuntime::now();
        self::assertGreaterThanOrEqual(0.005, $b - $a);
    }
}

/**
 * @internal
 */
final class AsyncMutexTest extends TestCase
{
    public function test_synchronized_runs_and_releases(): void
    {
        $mutex = new AsyncMutex();

        $result = AsyncRuntime::run(static function () use ($mutex): string {
            return $mutex->synchronized(static fn (): string => 'critical');
        });

        self::assertSame('critical', $result);
        self::assertFalse($mutex->isLocked());
    }

    public function test_mutex_serializes_fibers(): void
    {
        $mutex = new AsyncMutex();
        $order = [];

        AsyncRuntime::run(static function () use ($mutex, &$order): void {
            $f1 = async(static function () use ($mutex, &$order): void {
                $mutex->synchronized(static function () use (&$order): void {
                    $order[] = 'f1-enter';
                    delay(0.05); // long critical section
                    $order[] = 'f1-exit';
                });
            });
            delay(0.001); // ensure f1 acquires first
            $f2 = async(static function () use ($mutex, &$order): void {
                $mutex->synchronized(static function () use (&$order): void {
                    $order[] = 'f2-enter';
                    $order[] = 'f2-exit';
                });
            });

            $f1->await();
            $f2->await();
        });

        self::assertSame(['f1-enter', 'f1-exit', 'f2-enter', 'f2-exit'], $order);
    }

    public function test_reentrancy_same_fiber(): void
    {
        $mutex = new AsyncMutex();

        $result = AsyncRuntime::run(static function () use ($mutex): int {
            return $mutex->synchronized(static function () use ($mutex): int {
                return $mutex->synchronized(static fn (): int => 3) + 1;
            });
        });

        self::assertSame(4, $result);
        self::assertFalse($mutex->isLocked());
    }

    public function test_release_without_ownership_throws(): void
    {
        $mutex = new AsyncMutex();
        $this->expectException(\LogicException::class);
        $mutex->release();
    }

    public function test_exception_inside_synchronized_releases_lock(): void
    {
        $mutex = new AsyncMutex();

        AsyncRuntime::run(static function () use ($mutex): void {
            try {
                $mutex->synchronized(static function (): never {
                    throw new \RuntimeException('section failed');
                });
            } catch (\RuntimeException) {
                // swallow
            }
        });

        self::assertFalse($mutex->isLocked());

        // Lock still usable afterwards
        $ok = AsyncRuntime::run(static fn () => $mutex->synchronized(static fn (): bool => true));
        self::assertTrue($ok);
    }

    public function test_mutex_handed_to_waiting_fiber_after_release(): void
    {
        $mutex = new AsyncMutex();
        $log = [];

        AsyncRuntime::run(static function () use ($mutex, &$log): void {
            // Hold the lock from main fiber
            $mutex->acquire();

            $waiter = async(static function () use ($mutex, &$log): void {
                $mutex->synchronized(static function () use (&$log): void {
                    $log[] = 'waiter-got-lock';
                });
            });

            delay(0.01);
            $log[] = 'main-releases';
            $mutex->release();

            $waiter->await();
        });

        self::assertSame(['main-releases', 'waiter-got-lock'], $log);
    }

    /**
     * H-14: the critical section must never be entered by two contexts at
     * the same time.
     */
    public function test_mutex_excludes_concurrent_fibers(): void
    {
        $mutex = new AsyncMutex();
        $inside = 0;
        $maxInside = 0;

        AsyncRuntime::run(static function () use ($mutex, &$inside, &$maxInside): void {
            $tasks = [];
            for ($f = 0; $f < 4; $f++) {
                $tasks[] = async(static function () use ($mutex, &$inside, &$maxInside): void {
                    for ($i = 0; $i < 5; $i++) {
                        $mutex->synchronized(static function () use (&$inside, &$maxInside): void {
                            $inside++;
                            $maxInside = max($maxInside, $inside);
                            delay(0.001);
                            $inside--;
                        });
                    }
                });
            }
            foreach ($tasks as $task) {
                $task->await();
            }
        });

        self::assertSame(1, $maxInside, 'the critical section must never be entered twice');
        self::assertFalse($mutex->isLocked());
    }

    /**
     * H-14: the ownership handoff has to be ATOMIC. A non-atomic handoff
     * nulled the owner before the waiter was woken up, so the releaser
     * could steal the lock back and the woken waiter then overwrote the
     * owner — two contexts inside the critical section at the same time.
     */
    public function test_mutex_handoff_is_atomic(): void
    {
        $mutex = new AsyncMutex();
        $log = [];

        AsyncRuntime::run(static function () use ($mutex, &$log): void {
            $mutex->acquire();

            $waiter = async(static function () use ($mutex, &$log): void {
                $mutex->synchronized(static function () use (&$log): void {
                    $log[] = 'waiter-in';
                    delay(0.01);
                    $log[] = 'waiter-out';
                });
            });
            delay(0.005);

            $log[] = 'owner-out';
            $mutex->release();

            // Re-taking the lock here has to block until the woken waiter
            // released it.
            $mutex->acquire();
            $log[] = 'owner-reacquired';
            $mutex->release();

            $waiter->await();
        });

        self::assertSame(['owner-out', 'waiter-in', 'waiter-out', 'owner-reacquired'], $log);
        self::assertFalse($mutex->isLocked());
    }

    /**
     * M-21: the owner is identified by the Fiber OBJECT. `spl_object_id()`
     * is unique only among LIVE objects, so a terminated owner fiber and
     * its successor could share an id and the successor would be treated
     * as the reentrant owner.
     */
    public function test_terminated_owner_fiber_does_not_grant_ownership(): void
    {
        $mutex = new AsyncMutex();

        $owner = new \Fiber(static function () use ($mutex): void {
            $mutex->acquire(); // takes the lock and dies while holding it
        });
        $owner->start();
        $ownerId = spl_object_id($owner);
        unset($owner);
        gc_collect_cycles();

        $next = new \Fiber(static function () use ($mutex): void {
            $mutex->acquire();
        });
        $next->start();

        self::assertTrue($next->isSuspended(), 'the next fiber must not enter the critical section');
        self::assertNotSame($ownerId, spl_object_id($next), 'the owner identity must not be recycled');
    }
}
