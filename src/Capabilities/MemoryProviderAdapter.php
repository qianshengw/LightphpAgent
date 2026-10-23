<?php

declare(strict_types=1);

namespace LightAgent\Capabilities;

/**
 * Capability adapter over the Agent's own memory backend
 * (port of capabilities.py MemoryProviderAdapter).
 *
 * The caller's identity is taken from the INVOCATION CONTEXT, never from
 * the model-supplied arguments. `memory.retrieve` / `memory.store` used to
 * read `user_id` straight from the arguments, so any principal that was
 * allowed to use the memory capability could read or write ANOTHER user's
 * memory simply by naming them (M-40).
 */
final class MemoryProviderAdapter extends BaseCapabilityProvider
{
    public function __construct(private readonly mixed $backend)
    {
        $this->name = 'memory';
        $this->version = '1';
        parent::__construct([
            new CapabilitySpec('memory.retrieve', read: true),
            new CapabilitySpec(
                'memory.store',
                write: true,
                persistent: true,
                risk: CapabilityRisk::ISOLATED_WRITE,
            ),
        ]);
    }

    public function invoke(string $capability, mixed ...$arguments): mixed
    {
        // The identity is resolved BEFORE the backend is touched, and the
        // `user_id` argument is deliberately ignored.
        $userId = $this->resolveUserId();
        return match ($capability) {
            'memory.retrieve' => $this->backend->retrieve(
                (string) ($arguments['query'] ?? ''),
                $userId,
            ),
            'memory.store' => $this->backend->store(
                (string) ($arguments['data'] ?? ''),
                $userId,
            ),
            default => throw new \RuntimeException($capability),
        };
    }

    /**
     * The identity of the caller, as recorded by the runtime.
     *
     * The context is bound by the registry right before the provider runs
     * (`CapabilityRegistry::invoke()` → `bindContext()`), so it is the
     * only trustworthy source of "who is calling". When it carries no
     * identity the call FAILS CLOSED: trusting the model-supplied
     * `user_id` argument would hand the caller somebody else's memory.
     */
    private function resolveUserId(): string
    {
        $context = $this->invocationContext ?? $this->context;
        $userId = $context?->userId;
        if ($userId === null || $userId === '') {
            $security = $context?->securityContext;
            if ($security instanceof \LightAgent\Security\SecurityContext) {
                $userId = $security->userId;
            }
        }
        if ($userId === null || $userId === '') {
            throw new \RuntimeException(
                'memory capability requires an identified invocation context; '
                . 'the caller identity is never taken from the request arguments',
            );
        }
        return $userId;
    }
}
