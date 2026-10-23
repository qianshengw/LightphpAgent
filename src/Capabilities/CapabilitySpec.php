<?php

declare(strict_types=1);

namespace LightAgent\Capabilities;

/**
 * Immutable capability descriptor (capabilities.py CapabilitySpec).
 *
 * @param array<string, mixed> $metadata
 */
final class CapabilitySpec
{
    public function __construct(
        public readonly string $name,
        public readonly string $description = '',
        public readonly CapabilityRisk $risk = CapabilityRisk::READ_ONLY,
        public readonly bool $read = false,
        public readonly bool $write = false,
        public readonly bool $network = false,
        public readonly bool $execute = false,
        public readonly bool $persistent = false,
        public readonly bool $cancellable = false,
        public readonly bool $resumable = false,
        public readonly ?float $timeout = null,
        public readonly ?int $outputLimit = null,
        public readonly bool $requiresSandbox = false,
        public readonly bool $requiresApproval = false,
        public readonly ?string $uiType = null,
        public readonly array $metadata = [],
    ) {
        if ($this->name === '') {
            throw new \InvalidArgumentException('CapabilitySpec.name must not be empty');
        }
        if ($this->timeout !== null && $this->timeout <= 0) {
            throw new \InvalidArgumentException('CapabilitySpec.timeout must be positive');
        }
        if ($this->outputLimit !== null && $this->outputLimit < 1) {
            throw new \InvalidArgumentException('CapabilitySpec.output_limit must be at least 1');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'risk' => $this->risk->value,
            'read' => $this->read,
            'write' => $this->write,
            'network' => $this->network,
            'execute' => $this->execute,
            'persistent' => $this->persistent,
            'cancellable' => $this->cancellable,
            'resumable' => $this->resumable,
            'timeout' => $this->timeout,
            'output_limit' => $this->outputLimit,
            'requires_sandbox' => $this->requiresSandbox,
            'requires_approval' => $this->requiresApproval,
            'ui_type' => $this->uiType,
            'metadata' => $this->metadata,
        ];
    }
}
