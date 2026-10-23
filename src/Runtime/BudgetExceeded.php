<?php

declare(strict_types=1);

namespace LightAgent\Runtime;

/**
 * Thrown when a budget dimension exceeds its limit (runtime.py BudgetExceeded).
 */
final class BudgetExceeded extends \RuntimeException
{
    public function __construct(
        public readonly string $dimension,
        public readonly float $limit,
        public readonly float $used,
    ) {
        parent::__construct("budget exceeded for {$dimension}: used={$used}, limit={$limit}");
    }
}
