<?php

declare(strict_types=1);

namespace LightAgent\Runtime;

/**
 * Budget usage counters (runtime.py BudgetUsage).
 */
final class BudgetUsage
{
    public function __construct(
        public int $modelCalls = 0,
        public int $toolCalls = 0,
        public int $tokens = 0,
        public float $seconds = 0.0,
        public float $cost = 0.0,
    ) {
    }

    /**
     * @return array<string, int|float>
     */
    public function toDict(): array
    {
        return [
            'model_calls' => $this->modelCalls,
            'tool_calls' => $this->toolCalls,
            'tokens' => $this->tokens,
            'seconds' => $this->seconds,
            'cost' => $this->cost,
        ];
    }

    /**
     * @param array<string, mixed> $d
     */
    public static function fromDict(array $d): self
    {
        return new self(
            modelCalls: (int) ($d['model_calls'] ?? 0),
            toolCalls: (int) ($d['tool_calls'] ?? 0),
            tokens: (int) ($d['tokens'] ?? 0),
            seconds: (float) ($d['seconds'] ?? 0),
            cost: (float) ($d['cost'] ?? 0),
        );
    }
}
