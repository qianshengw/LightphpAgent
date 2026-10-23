<?php

declare(strict_types=1);

namespace LightAgent\Capabilities;

/**
 * Policy evaluation request (capabilities.py PolicyRequest).
 */
final class PolicyRequest
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public readonly CapabilitySpec $capability,
        public readonly string $providerName,
        public array $arguments = [],
        public readonly RuntimeContext $context = new RuntimeContext(),
    ) {
    }
}
