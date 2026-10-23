<?php

declare(strict_types=1);

namespace LightAgent\Dag;

/**
 * Adapt a trusted callable to the worker contract (worker.py CallableWorker).
 */
final class CallableWorker
{
    public function __construct(
        /** @var \Closure(TaskContext):mixed */
        public readonly \Closure $function,
    ) {
    }

    public function execute(TaskContext $context): TaskOutcome
    {
        $raw = ($this->function)($context);
        return TaskOutcomes::normalize($raw, $context);
    }
}
