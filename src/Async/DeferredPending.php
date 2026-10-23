<?php

declare(strict_types=1);

namespace LightAgent\Async;

/**
 * A queued waiter for AsyncMutex.
 *
 * The waiter carries the ownership TOKEN it is entitled to receive: the
 * handoff in AsyncMutex::release() transfers ownership before the waiter is
 * woken up, so the token has to travel with the waiter (H-14).
 *
 * @internal
 */
final class DeferredPending
{
    /**
     * @param \Amp\DeferredFuture $future Future completed when the waiter's turn arrives.
     * @param object|null $token Ownership token the waiter will hold once it is woken.
     */
    public function __construct(
        public readonly \Amp\DeferredFuture $future,
        public readonly ?object $token = null,
    ) {
    }
}
