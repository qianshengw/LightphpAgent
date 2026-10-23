<?php

declare(strict_types=1);

/**
 * LightAgent PHP — Amp/Revolt bootstrap helpers.
 *
 * Every entry point that executes async code must wrap it in Async::run().
 */

namespace LightAgent\Async;

use Revolt\EventLoop;
use Throwable;

final class Async
{
    /**
     * Run an async closure inside the event loop and block until it completes.
     *
     * Uses the Revolt Suspension pattern: registers the closure as a deferred
     * microtask, then suspends {main} while the loop drives it.
     *
     * @template T
     *
     * @param \Closure():T $closure
     *
     * @return T
     */
    public static function run(\Closure $closure): mixed
    {
        $suspension = EventLoop::getSuspension();

        EventLoop::queue(static function () use ($suspension, $closure): void {
            try {
                $suspension->resume($closure());
            } catch (Throwable $throwable) {
                $suspension->resume($throwable);
            }
        });

        $result = $suspension->suspend();

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }
}
