<?php

declare(strict_types=1);

namespace LightAgent\Capabilities;

/**
 * Policy evaluation decision (capabilities.py PolicyDecision).
 */
final class PolicyDecision
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason = null,
        public readonly bool $requiresApproval = false,
        public readonly ?array $arguments = null,
        public array $metadata = [],
    ) {
    }

    /**
     * @param array<string, mixed>|null $arguments
     * @param array<string, mixed> $metadata
     */
    public static function allow(?array $arguments = null, array $metadata = []): self
    {
        return new self(allowed: true, arguments: $arguments, metadata: $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function block(string $reason, array $metadata = []): self
    {
        return new self(allowed: false, reason: $reason, metadata: $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function approval(?string $reason = null, array $metadata = []): self
    {
        return new self(allowed: false, reason: $reason, requiresApproval: true, metadata: $metadata);
    }
}
