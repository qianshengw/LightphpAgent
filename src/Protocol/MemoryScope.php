<?php

declare(strict_types=1);

namespace LightAgent\Protocol;

/**
 * Recommended metadata shape for memory provenance and retrieval policy
 * (protocol.py MemoryScope, frozen dataclass).
 */
final class MemoryScope
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $source = 'user',
        public readonly string $scope = 'user',
        public readonly ?string $agentName = null,
        public readonly ?string $traceId = null,
        public readonly ?string $parentTraceId = null,
        public readonly ?float $confidence = null,
        public readonly ?string $trustLevel = null,
        public readonly array $metadata = [],
    ) {
    }

    /**
     * Export scope fields as a memory adapter metadata dictionary.
     *
     * @return array<string, mixed>
     */
    public function toMetadata(): array
    {
        $data = $this->metadata;
        $data['source'] = $this->source;
        $data['scope'] = $this->scope;
        if ($this->agentName !== null) {
            $data['agent_name'] = $this->agentName;
        }
        if ($this->traceId !== null) {
            $data['trace_id'] = $this->traceId;
        }
        if ($this->parentTraceId !== null) {
            $data['parent_trace_id'] = $this->parentTraceId;
        }
        if ($this->confidence !== null) {
            $data['confidence'] = $this->confidence;
        }
        if ($this->trustLevel !== null) {
            $data['trust_level'] = $this->trustLevel;
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function user(
        ?string $agentName = null,
        ?string $traceId = null,
        array $metadata = [],
    ): self {
        return new self(
            source: 'user',
            scope: 'user',
            agentName: $agentName,
            traceId: $traceId,
            metadata: $metadata,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function reflection(
        ?string $agentName = null,
        ?string $traceId = null,
        ?string $parentTraceId = null,
        array $metadata = [],
    ): self {
        return new self(
            source: 'reflection',
            scope: 'agent',
            agentName: $agentName,
            traceId: $traceId,
            parentTraceId: $parentTraceId,
            metadata: $metadata,
        );
    }
}
