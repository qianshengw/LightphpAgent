<?php

declare(strict_types=1);

namespace LightAgent\Protocol;

/**
 * Non-injectable memory evidence awaiting explicit promotion
 * (protocol.py MemoryCandidate, frozen dataclass).
 */
final class MemoryCandidate
{
    private ?string $resolvedCandidateId = null;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $data,
        public readonly string $memoryUserId,
        public readonly string $originalUserId,
        public readonly string $source,
        public readonly string $scope,
        public readonly ?string $agentName = null,
        public readonly ?string $traceId = null,
        public readonly ?string $parentTraceId = null,
        public readonly ?string $runId = null,
        public readonly ?string $runGroupId = null,
        public readonly ?float $confidence = null,
        public readonly ?string $trustLevel = null,
        public readonly string $candidateId = '',
        public readonly string $status = 'candidate',
        public readonly bool $injectable = false,
        public readonly array $metadata = [],
    ) {
    }

    /**
     * candidate_id with dataclass default_factory semantics:
     * empty string means "generate uuid4-hex-like id lazily".
     */
    public function id(): string
    {
        if ($this->resolvedCandidateId === null) {
            $this->resolvedCandidateId = $this->candidateId !== ''
                ? $this->candidateId
                : bin2hex(random_bytes(16));
        }
        return $this->resolvedCandidateId;
    }

    /**
     * Return a copy with selected fields changed (dataclasses.replace).
     *
     * @param array<string, mixed> $updates
     */
    public function withUpdates(array $updates): self
    {
        return new self(
            data: $updates['data'] ?? $this->data,
            memoryUserId: $updates['memory_user_id'] ?? $this->memoryUserId,
            originalUserId: $updates['original_user_id'] ?? $this->originalUserId,
            source: $updates['source'] ?? $this->source,
            scope: $updates['scope'] ?? $this->scope,
            agentName: $updates['agent_name'] ?? $this->agentName,
            traceId: $updates['trace_id'] ?? $this->traceId,
            parentTraceId: $updates['parent_trace_id'] ?? $this->parentTraceId,
            runId: $updates['run_id'] ?? $this->runId,
            runGroupId: $updates['run_group_id'] ?? $this->runGroupId,
            confidence: $updates['confidence'] ?? $this->confidence,
            trustLevel: $updates['trust_level'] ?? $this->trustLevel,
            candidateId: $updates['candidate_id'] ?? $this->id(),
            status: $updates['status'] ?? $this->status,
            injectable: $updates['injectable'] ?? $this->injectable,
            metadata: $updates['metadata'] ?? $this->metadata,
        );
    }

    /**
     * Export metadata for adapters that persist a promoted candidate.
     *
     * @return array<string, mixed>
     */
    public function toMetadata(): array
    {
        $data = $this->metadata;
        $data['user_id'] = (string) $this->memoryUserId;
        $data['original_user_id'] = (string) $this->originalUserId;
        $data['source'] = $this->source;
        $data['scope'] = $this->scope;
        $data['candidate_id'] = $this->id();
        $data['promotion_status'] = $this->status;
        $data['injectable'] = $this->injectable;
        if ($this->agentName !== null) {
            $data['agent_name'] = $this->agentName;
        }
        if ($this->traceId !== null) {
            $data['trace_id'] = $this->traceId;
        }
        if ($this->parentTraceId !== null) {
            $data['parent_trace_id'] = $this->parentTraceId;
        }
        if ($this->runId !== null) {
            $data['run_id'] = $this->runId;
        }
        if ($this->runGroupId !== null) {
            $data['run_group_id'] = $this->runGroupId;
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
     * Return a serializable representation for APIs, hooks, and tests.
     *
     * @return array<string, mixed>
     */
    public function toDict(bool $includeData = true): array
    {
        $data = [
            'candidate_id' => $this->id(),
            'memory_user_id' => (string) $this->memoryUserId,
            'original_user_id' => (string) $this->originalUserId,
            'source' => $this->source,
            'scope' => $this->scope,
            'agent_name' => $this->agentName,
            'trace_id' => $this->traceId,
            'parent_trace_id' => $this->parentTraceId,
            'run_id' => $this->runId,
            'run_group_id' => $this->runGroupId,
            'confidence' => $this->confidence,
            'trust_level' => $this->trustLevel,
            'status' => $this->status,
            'injectable' => $this->injectable,
            'metadata' => $this->metadata,
        ];
        if ($includeData) {
            $data['data'] = $this->data;
        }
        // {key: value for ... if value is not None}
        return array_filter($data, static fn (mixed $v): bool => $v !== null);
    }
}
