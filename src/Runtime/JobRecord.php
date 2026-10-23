<?php

declare(strict_types=1);

namespace LightAgent\Runtime;

use LightAgent\Async\CancellationToken;

/**
 * Job enums + record (runtime.py JobStatus / JobRecord).
 */
enum JobStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case SUCCESS = 'success';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case INTERRUPTED = 'interrupted';
}

final class JobRecord
{
    /**
     * @param array<string, mixed> $metadata
     * @param list<mixed> $output
     */
    public function __construct(
        public string $name,
        public string $jobId = '',
        public JobStatus $status = JobStatus::PENDING,
        public ?string $ownerAgentId = null,
        public string $createdAt = '',
        public ?string $startedAt = null,
        public ?string $completedAt = null,
        public mixed $result = null,
        public ?string $error = null,
        public ?string $idempotencyKey = null,
        public ?string $cancellationTokenId = null,
        public ?string $leaseOwner = null,
        public int $leaseEpoch = 0,
        public ?string $leaseExpiresAt = null,
        public array $metadata = [],
        public array $output = [],
    ) {
        if ($this->jobId === '') {
            $this->jobId = bin2hex(random_bytes(16));
        }
        if ($this->createdAt === '') {
            $this->createdAt = \LightAgent\Session\SessionConstants::utcNow();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'name' => $this->name,
            'job_id' => $this->jobId,
            'status' => $this->status->value,
            'owner_agent_id' => $this->ownerAgentId,
            'created_at' => $this->createdAt,
            'started_at' => $this->startedAt,
            'completed_at' => $this->completedAt,
            'result' => $this->result,
            'error' => $this->error,
            'idempotency_key' => $this->idempotencyKey,
            'cancellation_token_id' => $this->cancellationTokenId,
            'lease_owner' => $this->leaseOwner,
            'lease_epoch' => $this->leaseEpoch,
            'lease_expires_at' => $this->leaseExpiresAt,
            'metadata' => $this->metadata,
            'output' => $this->output,
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromDict(array $value): self
    {
        return new self(
            name: (string) ($value['name'] ?? ''),
            jobId: (string) ($value['job_id'] ?? '') ?: bin2hex(random_bytes(16)),
            status: JobStatus::from((string) ($value['status'] ?? 'pending')),
            ownerAgentId: isset($value['owner_agent_id']) ? (string) $value['owner_agent_id'] : null,
            createdAt: (string) ($value['created_at'] ?? '') ?: \LightAgent\Session\SessionConstants::utcNow(),
            startedAt: isset($value['started_at']) ? (string) $value['started_at'] : null,
            completedAt: isset($value['completed_at']) ? (string) $value['completed_at'] : null,
            result: $value['result'] ?? null,
            error: isset($value['error']) ? (string) $value['error'] : null,
            idempotencyKey: isset($value['idempotency_key']) ? (string) $value['idempotency_key'] : null,
            cancellationTokenId: isset($value['cancellation_token_id']) ? (string) $value['cancellation_token_id'] : null,
            leaseOwner: isset($value['lease_owner']) ? (string) $value['lease_owner'] : null,
            leaseEpoch: (int) ($value['lease_epoch'] ?? 0),
            leaseExpiresAt: isset($value['lease_expires_at']) ? (string) $value['lease_expires_at'] : null,
            metadata: is_array($value['metadata'] ?? null) ? $value['metadata'] : [],
            output: is_array($value['output'] ?? null) ? $value['output'] : [],
        );
    }

    public function isLeaseExpired(): bool
    {
        if ($this->leaseExpiresAt === null) {
            return false;
        }
        try {
            $expires = new \DateTimeImmutable($this->leaseExpiresAt);
        } catch (\Exception) {
            return false;
        }
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC')) >= $expires;
    }
}
