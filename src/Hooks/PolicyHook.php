<?php

declare(strict_types=1);

namespace LightAgent\Hooks;

/**
 * Explicit hook policy for failures that must block protected operations
 * (hooks.py PolicyHook, frozen dataclass).
 */
final class PolicyHook
{
    /** @var list<string>|null */
    public readonly ?array $phases;

    /**
     * @param callable $handler fn(HookContext): mixed
     * @param list<string>|null $phases
     */
    public function __construct(
        public readonly mixed $handler,
        ?array $phases = null,
        public readonly string $failureMode = 'block',
        public readonly ?float $timeout = null,
        public readonly ?string $name = null,
    ) {
        if ($this->handler === null) {
            throw new \InvalidArgumentException('PolicyHook handler is required');
        }
        if (!in_array($this->failureMode, ['block', 'continue'], true)) {
            throw new \InvalidArgumentException("PolicyHook failure_mode must be 'block' or 'continue'");
        }
        if ($this->timeout !== null) {
            if (is_bool($this->timeout) || !is_numeric($this->timeout) || $this->timeout <= 0) {
                throw new \InvalidArgumentException('PolicyHook timeout must be a number greater than 0');
            }
        }
        $this->phases = $phases !== null ? array_map('strval', array_values($phases)) : null;
    }
}
