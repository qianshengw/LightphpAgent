<?php

declare(strict_types=1);

namespace LightAgent\Errors;

/**
 * Immutable error metadata (errors.py LightAgentErrorInfo dataclass).
 */
final class LightAgentErrorInfo
{
    /**
     * @param string $category coarse grouping used for dashboards and alert
     *        routing ("provider", "runtime", "policy", …). The Python original
     *        carried a fourth field here that the PHP port silently dropped.
     */
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly string $guidance,
        public readonly string $category = 'runtime',
    ) {
    }
}
