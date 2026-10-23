<?php

declare(strict_types=1);

namespace LightAgent\Dag;

use LightAgent\Security\SecurityUtils;

/**
 * Run-level configuration (dag/models.py DAGConfig, frozen dataclass).
 *
 * Every option is enforced by the runtime:
 *  - max_decomposition_depth: the deepest nesting level a decomposition
 *    tree may reach (the tasks of the initial graph are at depth 0).
 *  - verification_concurrency: how many verifiers may run at the same
 *    time.
 *  - max_pending_verifications: how many staged artifacts may await their
 *    verification at the same time.
 *
 * @param array<string, mixed> $toDictExtras internal use
 */
final class DAGConfig
{
    public function __construct(
        public readonly int $maxTasks = 1000,
        public readonly int $maxEdges = 3000,
        public readonly int $maxDecompositionDepth = 8,
        public readonly int $maxAttemptsPerTask = 3,
        public readonly int $maxDecompositionsPerTask = 1,
        public readonly int $maxConcurrency = 4,
        public readonly int $verificationConcurrency = 4,
        public readonly int $maxPendingVerifications = 16,
        public readonly int $maxArtifactBytes = 10 * 1024 * 1024,
        public readonly float $schedulerLeaseSeconds = 60.0,
        public readonly float $taskLeaseSeconds = 60.0,
        public readonly string $failurePolicy = 'continue_independent',
        public readonly int $schemaVersion = DAGConstants::SCHEMA_VERSION,
    ) {
        foreach ([
            'max_tasks' => $this->maxTasks,
            'max_edges' => $this->maxEdges,
            'max_decomposition_depth' => $this->maxDecompositionDepth,
            'max_attempts_per_task' => $this->maxAttemptsPerTask,
            'max_decompositions_per_task' => $this->maxDecompositionsPerTask,
            'max_concurrency' => $this->maxConcurrency,
            'verification_concurrency' => $this->verificationConcurrency,
            'max_pending_verifications' => $this->maxPendingVerifications,
            'max_artifact_bytes' => $this->maxArtifactBytes,
            'scheduler_lease_seconds' => $this->schedulerLeaseSeconds,
            'task_lease_seconds' => $this->taskLeaseSeconds,
        ] as $name => $value) {
            if ($value <= 0) {
                throw new \InvalidArgumentException("{$name} must be positive");
            }
        }
        if (!in_array($this->failurePolicy, ['continue_independent', 'fail_fast'], true)) {
            throw new \InvalidArgumentException('failure_policy must be continue_independent or fail_fast');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'max_tasks' => $this->maxTasks,
            'max_edges' => $this->maxEdges,
            'max_decomposition_depth' => $this->maxDecompositionDepth,
            'max_attempts_per_task' => $this->maxAttemptsPerTask,
            'max_decompositions_per_task' => $this->maxDecompositionsPerTask,
            'max_concurrency' => $this->maxConcurrency,
            'verification_concurrency' => $this->verificationConcurrency,
            'max_pending_verifications' => $this->maxPendingVerifications,
            'max_artifact_bytes' => $this->maxArtifactBytes,
            'scheduler_lease_seconds' => $this->schedulerLeaseSeconds,
            'task_lease_seconds' => $this->taskLeaseSeconds,
            'failure_policy' => $this->failurePolicy,
            'schema_version' => $this->schemaVersion,
        ];
    }
}
