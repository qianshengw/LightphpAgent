<?php

declare(strict_types=1);

namespace LightAgent\Runtime;

/**
 * Budget limits (runtime.py BudgetLimits).
 */
final class BudgetLimits
{
    public function __construct(
        public readonly ?int $modelCalls = null,
        public readonly ?int $toolCalls = null,
        public readonly ?int $tokens = null,
        public readonly ?float $seconds = null,
        public readonly ?float $cost = null,
    ) {
        foreach (['modelCalls' => $this->modelCalls, 'toolCalls' => $this->toolCalls, 'tokens' => $this->tokens, 'seconds' => $this->seconds, 'cost' => $this->cost] as $name => $value) {
            if ($value !== null && $value < 0) {
                throw new \InvalidArgumentException("budget limit `{$name}` must be non-negative");
            }
        }
    }

    /**
     * @return array<string, int|float|null>
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
}
