<?php

declare(strict_types=1);

namespace LightAgent\Dag;

/**
 * Mutable task state (dag/models.py TaskState).
 */
final class TaskState
{
    /**
     * @param list<string> $verifiedArtifactIds
     */
    public function __construct(
        public readonly string $taskId,
        public DAGTaskStatus $status = DAGTaskStatus::READY,
        public int $stateVersion = 0,
        public int $dependencyRevision = 0,
        public int $attemptCount = 0,
        public int $decompositionCount = 0,
        /**
         * Nesting level of the task inside the decomposition tree: the
         * tasks of the initial graph are at depth 0, the tasks created by
         * decomposing a task at depth N are at depth N+1. It enforces
         * DAGConfig::maxDecompositionDepth.
         */
        public int $decompositionDepth = 0,
        public int $verificationFailureCount = 0,
        public ?string $nextEligibleAt = null,
        public ?string $blocker = null,
        public ?string $activeAttemptId = null,
        public array $verifiedArtifactIds = [],
        public ?string $lastDiagnostic = null,
        public string $updatedAt = '',
    ) {
        if ($this->updatedAt === '') {
            $this->updatedAt = DAGConstants::utcNow();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'task_id' => $this->taskId,
            'status' => $this->status->value,
            'state_version' => $this->stateVersion,
            'dependency_revision' => $this->dependencyRevision,
            'attempt_count' => $this->attemptCount,
            'decomposition_count' => $this->decompositionCount,
            'decomposition_depth' => $this->decompositionDepth,
            'verification_failure_count' => $this->verificationFailureCount,
            'next_eligible_at' => $this->nextEligibleAt,
            'blocker' => $this->blocker,
            'active_attempt_id' => $this->activeAttemptId,
            'verified_artifact_ids' => $this->verifiedArtifactIds,
            'last_diagnostic' => $this->lastDiagnostic,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromDict(array $value): self
    {
        return new self(
            taskId: (string) ($value['task_id'] ?? ''),
            status: DAGTaskStatus::from((string) ($value['status'] ?? DAGTaskStatus::READY->value)),
            stateVersion: (int) ($value['state_version'] ?? 0),
            dependencyRevision: (int) ($value['dependency_revision'] ?? 0),
            attemptCount: (int) ($value['attempt_count'] ?? 0),
            decompositionCount: (int) ($value['decomposition_count'] ?? 0),
            decompositionDepth: (int) ($value['decomposition_depth'] ?? 0),
            verificationFailureCount: (int) ($value['verification_failure_count'] ?? 0),
            nextEligibleAt: isset($value['next_eligible_at']) ? (string) $value['next_eligible_at'] : null,
            blocker: isset($value['blocker']) ? (string) $value['blocker'] : null,
            activeAttemptId: isset($value['active_attempt_id']) ? (string) $value['active_attempt_id'] : null,
            verifiedArtifactIds: is_array($value['verified_artifact_ids'] ?? null) ? $value['verified_artifact_ids'] : [],
            lastDiagnostic: isset($value['last_diagnostic']) ? (string) $value['last_diagnostic'] : null,
            updatedAt: (string) ($value['updated_at'] ?? '') ?: DAGConstants::utcNow(),
        );
    }
}
