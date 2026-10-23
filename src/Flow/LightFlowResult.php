<?php

declare(strict_types=1);

namespace LightAgent\Flow;

/**
 * Structured LightFlow run result (flow.py LightFlowResult).
 */
final class LightFlowResult implements \Stringable
{
    /**
     * @param list<LightFlowStepResult> $steps
     * @param list<array<string, mixed>> $trace
     */
    public function __construct(
        public readonly string $content = '',
        public array $steps = [],
        public readonly ?string $traceId = null,
        public array $trace = [],
        public readonly ?string $error = null,
        public readonly ?string $runId = null,
        public readonly string $status = FlowConstants::SUCCESS,
        public readonly bool $idempotentReplay = false,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->error === null && $this->status === FlowConstants::SUCCESS;
    }

    public function __toString(): string
    {
        return $this->content;
    }
}
