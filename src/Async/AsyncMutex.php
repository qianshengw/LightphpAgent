<?php

declare(strict_types=1);

namespace LightAgent\Async;

use Amp\Future;
use Throwable;
use function Amp\async;

/**
 * Await-aware mutex (port surface for threading.RLock used across await
 * points in session.py / security.py / dag/store.py).
 *
 * Python semantics preserved:
 *  - reentrant: the owning fiber may re-acquire without deadlocking
 *  - `with lock:`          -> synchronized($closure)
 *  - acquire()/release()   -> acquire()/release()
 *
 * The lock is NOT thread-safe (PHP is single-threaded); it arbitrates
 * access between fibers across suspension points.
 *
 * Ownership is tracked with an OBJECT token (the owning Fiber, or a {main}
 * sentinel) instead of `spl_object_id()`: object ids are unique only among
 * LIVE objects and PHP reuses them immediately after GC, so a terminated
 * fiber and its successor could share an id and the successor would be
 * mistaken for the reentrant owner (M-21).
 */
final class AsyncMutex
{
    /**
     * Sentinel token for the "{main}" context (no Fiber object available).
     * A dedicated object keeps the comparison an identity check.
     */
    private static ?object $mainToken = null;

    /**
     * The current owner: the owning Fiber object, the {main} sentinel, or
     * null when the lock is free.
     */
    private ?object $owner = null;

    private int $depth = 0;

    /** @var list<DeferredPending> */
    private array $waiters = [];

    /**
     * Run the closure while holding the lock.
     */
    public function synchronized(\Closure $closure): mixed
    {
        $acquired = $this->acquire();
        try {
            return $closure();
        } finally {
            if ($acquired) {
                $this->release();
            }
        }
    }

    /**
     * Acquire the lock, suspending the current fiber until available.
     * Reentrant for the owning context (fiber or {main}).
     */
    public function acquire(): bool
    {
        $token = self::currentToken();

        if ($this->owner !== null) {
            if ($this->owner === $token) {
                $this->depth++;
                // Reentrant acquire still hands back true so each
                // synchronized() nests release one level (RLock semantics);
                // returning false here leaked depth on nested calls (H14).
                return true;
            }
            // Another context holds it — wait for our turn.
            $this->waitTurn($token);
            // The releasing context transferred ownership to us BEFORE it
            // woke us up, so the lock is already ours. Never overwrite the
            // owner here: a third fiber could otherwise steal the lock in
            // the window between the handoff and this resumption (H-14).
            if ($this->owner !== $token) {
                throw new \LogicException('AsyncMutex: lock ownership was lost while waiting');
            }
            $this->depth = 1;
            return true;
        }

        $this->owner = $token;
        $this->depth = 1;
        return true;
    }

    /**
     * Release one level of ownership.
     */
    public function release(): void
    {
        if ($this->owner === null) {
            throw new \LogicException('AsyncMutex::release() without ownership');
        }

        if ($this->owner !== self::currentToken()) {
            throw new \LogicException('AsyncMutex::release() from a different fiber');
        }

        $this->depth--;
        if ($this->depth > 0) {
            return;
        }

        // Hand the lock to the next waiter, if any.
        $waiter = array_shift($this->waiters);
        if ($waiter !== null) {
            // ATOMIC handoff: the ownership token moves to the waiter
            // BEFORE its future is completed. Completing the future first
            // left a window in which the lock was unowned, so a third fiber
            // could enter the critical section while the woken waiter
            // believed it still owned the lock (H-14).
            $this->owner = $waiter->token;
            $this->depth = 1;
            $waiter->future->complete(true);
            return;
        }

        $this->owner = null;
        $this->depth = 0;
    }

    /**
     * Whether the lock is currently held.
     */
    public function isLocked(): bool
    {
        return $this->owner !== null;
    }

    /**
     * Whether the current context (fiber or {main}) owns this lock.
     */
    public function isOwnedByCurrentFiber(): bool
    {
        return $this->owner !== null && $this->owner === self::currentToken();
    }

    /**
     * Queue the current context and suspend until it is its turn.
     */
    private function waitTurn(object $token): void
    {
        $deferred = new \Amp\DeferredFuture();
        $waiter = new DeferredPending($deferred, $token);
        $this->waiters[] = $waiter;
        $deferred->getFuture()->await();
    }

    /**
     * The identity of the calling context: the Fiber object itself, or the
     * {main} sentinel when there is no enclosing fiber.
     */
    private static function currentToken(): object
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber !== null) {
            return $fiber;
        }
        return self::$mainToken ??= new \stdClass();
    }
}
