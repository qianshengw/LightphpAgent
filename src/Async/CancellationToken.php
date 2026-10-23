<?php

declare(strict_types=1);

namespace LightAgent\Async;

/**
 * Cooperative cancellation token with parent propagation (port of cancellation.py).
 *
 * The Python original uses a threading.Event; in the PHP port cancellation is
 * poll-based: callers check `cancelled` at their natural safe boundaries
 * (matching the Python comment about cooperative parent-aware waiting).
 */
final class CancellationToken
{
    public readonly string $tokenId;
    public readonly ?CancellationToken $parent;
    public readonly ?string $runId;

    private bool $event = false;
    private ?string $reason = null;

    /** @var array<int, array{CancellationToken, string}> observers subscribed via subscribe() */
    private array $observers = [];

    public function __construct(
        ?string $tokenId = null,
        ?CancellationToken $parent = null,
        ?string $runId = null,
    ) {
        $this->tokenId = $tokenId ?? bin2hex(random_bytes(16));
        $this->parent = $parent;
        $this->runId = $runId;
    }

    public function isCancelled(): bool
    {
        return $this->event || ($this->parent !== null && $this->parent->isCancelled());
    }

    public function getReason(): ?string
    {
        if ($this->event) {
            return $this->reason;
        }
        if ($this->parent !== null && $this->parent->isCancelled()) {
            return $this->parent->getReason();
        }
        return null;
    }

    /**
     * Cancel once and return whether this call changed the token.
     */
    public function cancel(?string $reason = null): bool
    {
        if ($this->event) {
            return false;
        }
        $this->reason = $reason ?? 'cancelled';
        $this->event = true;

        $observers = $this->observers;
        $this->observers = [];
        foreach ($observers as [$observer, $observedReason]) {
            if (!$observer->isCancelled()) {
                $observer->cancel($observedReason);
            }
        }
        return true;
    }

    public function child(?string $runId = null): self
    {
        $child = new self(parent: $this, runId: $runId ?? $this->runId);
        if ($this->isCancelled()) {
            $child->cancel($this->getReason());
        }
        return $child;
    }

    /**
     * Subscribe a token to be cancelled together with this one.
     * (Replaces Python's Event.wait with the async-friendly equivalent.)
     */
    public function subscribe(self $token, ?string $reason = null): void
    {
        if ($this->isCancelled()) {
            $token->cancel($reason ?? $this->getReason());
            return;
        }
        $this->observers[] = [$token, $reason ?? $this->getReason() ?? 'cancelled'];
    }

    /**
     * Port of cancellation.py accepts_keyword: return whether a callable
     * explicitly or generically accepts a keyword argument.
     *
     * PHP has no **kwargs, so "generically accepts" means variadic or a
     * parameter with the given name. Non-function callables return false
     * (mirroring the Python TypeError/ValueError fallback).
     */
    public static function acceptsKeyword(callable $function, string $keyword): bool
    {
        if (!is_callable($function)) {
            return false;
        }
        try {
            $ref = new \ReflectionFunction($function);
        } catch (\ReflectionException) {
            return false;
        }

        if ($ref->isVariadic()) {
            return true;
        }

        foreach ($ref->getParameters() as $parameter) {
            if ($parameter->getName() === $keyword) {
                return true;
            }
        }
        return false;
    }
}
