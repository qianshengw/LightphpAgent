<?php

declare(strict_types=1);

namespace LightAgent\Hooks;

/**
 * Context passed to runtime hooks (hooks.py HookContext).
 */
final class HookContext
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $phase,
        public array $payload = [],
        public ?string $traceId = null,
        public ?string $parentTraceId = null,
        public ?string $runId = null,
        public ?string $runGroupId = null,
        public ?string $userId = null,
        public ?string $agentName = null,
        public ?string $flowId = null,
        public ?string $stepName = null,
        public array $metadata = [],
        /**
         * A STABLE correlation id for the logical operation the hook runs
         * in (a session id, a workflow id, a conversation id, ...).
         *
         * Unlike `runId` — which the Agent regenerates for every `run()`
         * call — a correlation id survives across runs. It is therefore
         * the id an approval can be bound to when the caller wants a
         * stricter binding than "the same tool with the same arguments"
         * (M-41). The same value may also be supplied through
         * `metadata['correlation_id']`, which is the channel the Agent
         * runtime can pass through.
         */
        public ?string $correlationId = null,
    ) {
    }
}
