<?php

declare(strict_types=1);

namespace LightAgent\Async;

use Amp\Cancellation;
use Amp\Future;
use Amp\TimeoutCancellation;
use Revolt\EventLoop;
use Throwable;
use function Amp\async;
use function Amp\delay;

/**
 * asyncio semantics layer over the Amp kernel (Phase 2 port surface).
 *
 * Maps the Python concurrent primitives actually used by LightAgent:
 *
 *  asyncio.run / run_async_safely  -> AsyncRuntime::run()
 *  asyncio.gather                  -> AsyncRuntime::gather()
 *  asyncio.wait_for                -> AsyncRuntime::waitFor()
 *  asyncio.to_thread               -> AsyncRuntime::toThread()
 *  asyncio.sleep                   -> AsyncRuntime::sleep()
 *  threading.RLock (await-aware)   -> AsyncRuntime / AsyncMutex
 *
 * Known porting difference: asyncio.to_thread runs the callable on a real
 * thread; PHP is single-threaded, so toThread() executes the callable inside
 * the current fiber — identical result semantics, but a CPU-bound callable
 * will stall the event loop for its duration (Python would not).
 */
final class AsyncRuntime
{
    private function __construct()
    {
    }

    /**
     * Run an async closure and block {main} until it completes.
     * Entry-point bridge (asyncio.run equivalent).
     *
     * @template T
     *
     * @param \Closure():T $closure
     *
     * @return T
     */
    public static function run(\Closure $closure): mixed
    {
        return Async::run($closure);
    }

    /**
     * Start a fiber for the closure, returning its Future immediately.
     * (asyncio.create_task / Amp\async equivalent.)
     */
    public static function async(\Closure $closure): Future
    {
        return async($closure);
    }

    /**
     * Await all closures concurrently and return their results in order.
     * (asyncio.gather equivalent; exceptions propagate like gather().)
     *
     * @param array<\Closure> $closures
     *
     * @return array<int, mixed>
     */
    public static function gather(array $closures): array
    {
        $futures = [];
        foreach ($closures as $key => $closure) {
            $futures[$key] = $closure instanceof Future ? $closure : async($closure);
        }
        // Future\await preserves keys; reindex for asyncio.gather order semantics.
        $results = Future\await($futures);
        $ordered = [];
        foreach ($closures as $key => $_) {
            $ordered[$key] = $results[$key];
        }
        return $ordered;
    }

    /**
     * Await a closure with an optional timeout, racing it against a timer.
     * (asyncio.wait_for equivalent.)
     *
     * Two behaviours of asyncio.wait_for are reproduced here (M-20/V5-24):
     *
     *  1. When the deadline elapses the INNER operation is cancelled, not
     *     only the wait. `asyncio.wait_for` cancels the task, so the side
     *     effect of the closure does not run "after" the timeout any more.
     *     The closure receives an `Amp\Cancellation` (when its signature
     *     accepts one) and that cancellation is requested on timeout.
     *  2. Only the TIMEOUT cancellation is translated into an
     *     AsyncTimeoutException. A cancellation raised BY the operation
     *     itself is re-thrown unchanged — reporting it as a timeout produced
     *     the nonsense message "operation timed out after 5s (elapsed 0.005s)".
     *
     * @template T
     *
     * @param \Closure():T $closure
     *
     * @return T
     *
     * @throws AsyncTimeoutException when the deadline elapses first
     */
    public static function waitFor(\Closure $closure, ?float $timeout = null): mixed
    {
        if ($timeout === null) {
            return $closure();
        }

        $started = \Amp\now();

        // The inner operation is cancelled when the deadline fires so it can
        // stop instead of running to completion in the background.
        $innerCancellation = new \Amp\DeferredCancellation();
        $timeoutCancellation = new TimeoutCancellation($timeout);

        $timedOut = false;
        // Subscribing BEFORE the await guarantees this callback runs before
        // the await's cancellation callback, so $timedOut is already true
        // when the exception reaches the catch block below.
        $timeoutCancellation->subscribe(static function (\Throwable $reason) use (&$timedOut, $innerCancellation): void {
            $timedOut = true;
            $innerCancellation->cancel($reason);
        });

        try {
            $future = async(static fn (): mixed => self::invokeWithCancellation(
                $closure,
                $innerCancellation->getCancellation(),
            ));
            return $future->await($timeoutCancellation);
        } catch (\Amp\CancelledException $exc) {
            $isTimeout = $timedOut || $exc->getPrevious() instanceof \Amp\TimeoutException;
            if (!$isTimeout) {
                // The operation cancelled ITSELF — not a timeout. Report it
                // as-is instead of mislabelling it (M-20).
                throw $exc;
            }
            $elapsed = \Amp\now() - $started;
            throw new AsyncTimeoutException(
                "operation timed out after " . sprintf('%g', $timeout) . "s (elapsed " . sprintf('%.3f', $elapsed) . "s)",
                0,
                $exc,
            );
        }
    }

    /**
     * Invoke the closure, handing it the cancellation when its signature
     * accepts one.
     *
     * Closures that take no argument (or a non-cancellation argument) keep
     * their original call signature.
     */
    private static function invokeWithCancellation(\Closure $closure, Cancellation $cancellation): mixed
    {
        try {
            $reflection = new \ReflectionFunction($closure);
        } catch (\ReflectionException) {
            return $closure();
        }

        $parameters = $reflection->getParameters();
        if ($parameters === [] && !$reflection->isVariadic()) {
            return $closure();
        }

        $first = $parameters[0] ?? null;
        if ($first !== null) {
            $type = $first->getType();
            if ($type instanceof \ReflectionNamedType) {
                if ($type->isBuiltin()) {
                    if (!in_array($type->getName(), ['mixed', 'object'], true)) {
                        // The first parameter is not a cancellation sink.
                        return $closure();
                    }
                } elseif (!is_a($cancellation, $type->getName())) {
                    return $closure();
                }
            }
        }

        return $closure($cancellation);
    }

    /**
     * Port surface for asyncio.to_thread: run a blocking callable inside the
     * current fiber. See class docblock for the threading difference.
     *
     * @template T
     *
     * @param \Closure():T $closure
     *
     * @return T
     */
    public static function toThread(\Closure $closure): mixed
    {
        return $closure();
    }

    /**
     * asyncio.sleep equivalent: non-blocking delay inside a fiber.
     */
    public static function sleep(float $seconds, ?Cancellation $cancellation = null): void
    {
        delay($seconds, cancellation: $cancellation);
    }

    /**
     * Current event-loop time in seconds (asyncio loop.time() equivalent).
     */
    public static function now(): float
    {
        return \Amp\now();
    }

    /**
     * Run a closure while holding an AsyncMutex guard.
     * (Python `with lock:` across await points.)
     */
    public static function synchronized(AsyncMutex $mutex, \Closure $closure): mixed
    {
        return $mutex->synchronized($closure);
    }
}
