<?php

declare(strict_types=1);

namespace LightAgent\Flow;

/**
 * A single agent-backed workflow step (flow.py LightFlowStep).
 */
final class LightFlowStep
{
    /**
     * @param list<string> $dependsOn
     * @param list<mixed>|null $tools
     * @param array<string, mixed>|null $metadata
     * @param (callable(array<string, mixed>): mixed)|null $cancelIf
     * @param (callable(mixed, array<string, mixed>): mixed)|null $approvalHandler
     */
    public function __construct(
        public readonly string $name,
        public readonly mixed $agent,
        public readonly array $dependsOn = [],
        public readonly mixed $query = null,
        public readonly ?array $tools = null,
        public readonly int $maxRetry = 1,
        public readonly ?float $timeout = null,
        /**
         * Whether the timed-out agent may keep running next to the
         * fallback/retry. It says nothing about whether a fallback may run
         * at all — see `$fallbackOnTimeout`.
         */
        public readonly bool $allowTimeoutOverlap = false,
        public readonly mixed $fallbackAgent = null,
        public readonly ?\Closure $cancelIf = null,
        public readonly bool $requiresApproval = false,
        public readonly ?\Closure $approvalHandler = null,
        public readonly ?array $metadata = null,
        /**
         * Whether the fallback agent may take over after a TIMEOUT.
         *
         * M-31: the fallback used to be gated on `allowTimeoutOverlap`, so
         * the default (`false`) silently skipped the fallback for a timeout
         * while an ordinary agent error did use it. The two failure modes
         * now behave consistently; set this to false to opt out.
         */
        public readonly bool $fallbackOnTimeout = true,
    ) {
    }
}
