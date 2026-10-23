<?php

declare(strict_types=1);

namespace LightAgent\Dag;

/**
 * Task dependency edge (dag/models.py TaskDependency, frozen).
 */
final class TaskDependency
{
    public function __construct(
        public readonly string $runId,
        public readonly string $taskId,
        public readonly string $dependencyId,
        public readonly string $decompositionId = 'initial',
    ) {
    }
}

/**
 * Worker attempt on a task (dag/models.py TaskAttempt).
 */
final class TaskAttempt
{
    public function __construct(
        public string $runId,
        public string $taskId,
        public int $attemptNo,
        public string $workerId,
        public string $inputSnapshotHash,
        public string $attemptId = '',
        public string $phase = 'solve',
        public ?string $sessionId = null,
        public ?string $workspaceRef = null,
        public int $leaseEpoch = 1,
        public ?string $leaseExpiresAt = null,
        public DAGAttemptStatus $status = DAGAttemptStatus::CLAIMED,
        public string $createdAt = '',
        public string $updatedAt = '',
        public ?string $error = null,
    ) {
        if ($this->attemptId === '') {
            $this->attemptId = bin2hex(random_bytes(16));
        }
        $now = DAGConstants::utcNow();
        if ($this->createdAt === '') {
            $this->createdAt = $now;
        }
        if ($this->updatedAt === '') {
            $this->updatedAt = $now;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'run_id' => $this->runId,
            'task_id' => $this->taskId,
            'attempt_no' => $this->attemptNo,
            'worker_id' => $this->workerId,
            'input_snapshot_hash' => $this->inputSnapshotHash,
            'attempt_id' => $this->attemptId,
            'phase' => $this->phase,
            'session_id' => $this->sessionId,
            'workspace_ref' => $this->workspaceRef,
            'lease_epoch' => $this->leaseEpoch,
            'lease_expires_at' => $this->leaseExpiresAt,
            'status' => $this->status->value,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'error' => $this->error,
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromDict(array $value): self
    {
        return new self(
            runId: (string) ($value['run_id'] ?? ''),
            taskId: (string) ($value['task_id'] ?? ''),
            attemptNo: (int) ($value['attempt_no'] ?? 0),
            workerId: (string) ($value['worker_id'] ?? ''),
            inputSnapshotHash: (string) ($value['input_snapshot_hash'] ?? ''),
            attemptId: (string) ($value['attempt_id'] ?? '') ?: bin2hex(random_bytes(16)),
            phase: (string) ($value['phase'] ?? 'solve'),
            sessionId: isset($value['session_id']) ? (string) $value['session_id'] : null,
            workspaceRef: isset($value['workspace_ref']) ? (string) $value['workspace_ref'] : null,
            leaseEpoch: (int) ($value['lease_epoch'] ?? 1),
            leaseExpiresAt: isset($value['lease_expires_at']) ? (string) $value['lease_expires_at'] : null,
            status: DAGAttemptStatus::from((string) ($value['status'] ?? DAGAttemptStatus::CLAIMED->value)),
            createdAt: (string) ($value['created_at'] ?? '') ?: DAGConstants::utcNow(),
            updatedAt: (string) ($value['updated_at'] ?? '') ?: DAGConstants::utcNow(),
            error: isset($value['error']) ? (string) $value['error'] : null,
        );
    }
}
