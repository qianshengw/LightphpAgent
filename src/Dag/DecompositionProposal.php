<?php

declare(strict_types=1);

namespace LightAgent\Dag;

use LightAgent\Security\SecurityUtils;

/**
 * Decomposition proposal (dag/models.py DecompositionProposal, frozen).
 */
final class DecompositionProposal
{
    /**
     * @param list<TaskSpec>|array<int, TaskSpec> $newTasks
     * @param list<string>|array<int, string> $reuseTaskIds
     */
    public function __construct(
        public readonly string $parentTaskId,
        public array $newTasks = [],
        public array $reuseTaskIds = [],
        public readonly string $rationale = '',
        public readonly mixed $compositionContract = null,
        // M32: null means "no explicit expectation" — acceptDecomposition
        // then skips the optimistic check instead of comparing against 0,
        // which could never match (the graph starts at revision 1). A worker
        // creating a proposal cannot know the live revision, so the old
        // int-0 default made the documented usage permanently VERSION-
        // CONFLICT. Explicit values still get the full optimistic check.
        public readonly ?int $expectedGraphRevision = null,
        public string $proposalId = '',
        public readonly ?string $verificationReportId = null,
        public readonly int $schemaVersion = DAGConstants::SCHEMA_VERSION,
    ) {
        $normalized = [];
        foreach ($this->newTasks as $task) {
            if ($task->createdByTaskId !== null && $task->createdByTaskId !== $this->parentTaskId) {
                throw new \InvalidArgumentException('decomposition task has a different created_by_task_id');
            }
            if ($task->createdByTaskId !== $this->parentTaskId) {
                $task = new TaskSpec(
                    goal: $task->goal,
                    acceptanceContract: $task->acceptanceContract,
                    workerKey: $task->workerKey,
                    verifierKey: $task->verifierKey,
                    taskId: $task->taskId,
                    decomposerKey: $task->decomposerKey,
                    dependsOn: $task->dependsOn,
                    resourceRequirements: $task->resourceRequirements,
                    createdByTaskId: $this->parentTaskId,
                    supersedesTaskId: $task->supersedesTaskId,
                    priority: $task->priority,
                    metadata: $task->metadata,
                    contractHash: $task->contractHash,
                    schemaVersion: $task->schemaVersion,
                );
            }
            $normalized[] = $task;
        }
        $this->newTasks = $normalized;
        $this->reuseTaskIds = array_values(array_unique($this->reuseTaskIds));
        if ($this->proposalId === '') {
            $this->proposalId = bin2hex(random_bytes(16));
        }
    }

    public function digest(): string
    {
        return SecurityUtils::canonicalDigest($this->toDict());
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'parent_task_id' => $this->parentTaskId,
            'new_tasks' => array_map(static fn (TaskSpec $t): array => $t->toDict(), $this->newTasks),
            'reuse_task_ids' => $this->reuseTaskIds,
            'rationale' => $this->rationale,
            'composition_contract' => $this->compositionContract,
            'expected_graph_revision' => $this->expectedGraphRevision,
            'proposal_id' => $this->proposalId,
            'verification_report_id' => $this->verificationReportId,
            'schema_version' => $this->schemaVersion,
        ];
    }
}

