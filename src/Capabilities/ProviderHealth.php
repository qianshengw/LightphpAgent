<?php

declare(strict_types=1);

namespace LightAgent\Capabilities;

/**
 * Provider health report (capabilities.py ProviderHealth).
 */
final class ProviderHealth
{
    /**
     * @param list<string> $degradedCapabilities
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly bool $healthy = true,
        public readonly string $status = 'ready',
        public readonly ?string $message = null,
        public array $degradedCapabilities = [],
        public array $metadata = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'healthy' => $this->healthy,
            'status' => $this->status,
            'message' => $this->message,
            'degraded_capabilities' => $this->degradedCapabilities,
            'metadata' => $this->metadata,
        ];
    }
}
