<?php

declare(strict_types=1);

namespace LightAgent\Dag;

/**
 * DAG run final result (dag/models.py DAGRunResult).
 *
 * @param array<string, list<ArtifactManifest>> $rootArtifacts
 * @param list<string> $failedTasks
 * @param array<string, string> $blockers
 * @param list<string> $pendingApprovals
 * @param array<string, mixed> $usage
 */
final class DAGRunResult
{
    public function __construct(
        public readonly string $runId,
        public readonly DAGRunStatus $status,
        public array $rootArtifacts = [],
        public array $failedTasks = [],
        public array $blockers = [],
        public array $pendingApprovals = [],
        public array $usage = [],
        public int $eventCursor = 0,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status === DAGRunStatus::SUCCEEDED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        $rootArtifacts = [];
        foreach ($this->rootArtifacts as $taskId => $artifacts) {
            $rootArtifacts[$taskId] = array_map(static fn (ArtifactManifest $a): array => $a->toDict(), $artifacts);
        }
        return [
            'run_id' => $this->runId,
            'status' => $this->status->value,
            'root_artifacts' => $rootArtifacts,
            'failed_tasks' => $this->failedTasks,
            'blockers' => $this->blockers,
            'pending_approvals' => $this->pendingApprovals,
            'usage' => $this->usage,
            'event_cursor' => $this->eventCursor,
            'success' => $this->isSuccess(),
        ];
    }
}
