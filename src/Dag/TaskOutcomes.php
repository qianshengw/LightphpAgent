<?php

declare(strict_types=1);

namespace LightAgent\Dag;

/**
 * Raw worker output → TaskOutcome (worker.py normalize_task_outcome).
 */
final class TaskOutcomes
{
    public static function normalize(mixed $raw, ?TaskContext $context = null): TaskOutcome
    {
        if ($raw instanceof TaskOutcome) {
            return $raw;
        }
        if (!is_array($raw)) {
            throw new DAGWorkerProtocolError('worker must return TaskOutcome or an explicit mapping');
        }
        $kind = TaskOutcomeKind::from((string) ($raw['kind'] ?? ''));
        $metadata = is_array($raw['metadata'] ?? null) ? $raw['metadata'] : [];
        if ($kind === TaskOutcomeKind::CANDIDATE) {
            if (!array_key_exists('content', $raw)) {
                throw new DAGWorkerProtocolError('candidate outcome requires content');
            }
            return TaskOutcome::candidate(
                $raw['content'],
                (string) ($raw['media_type'] ?? 'text/plain'),
                $metadata,
            );
        }
        if ($kind === TaskOutcomeKind::BLOCKED) {
            return TaskOutcome::blocked((string) ($raw['blocker'] ?? ''), $metadata);
        }
        $proposalValue = $raw['decomposition'] ?? null;
        if ($proposalValue instanceof DecompositionProposal) {
            $proposal = $proposalValue;
        } elseif (is_array($proposalValue)) {
            if ($context === null) {
                throw new DAGWorkerProtocolError('decomposition mapping requires TaskContext');
            }
            $newTasks = [];
            foreach (($proposalValue['new_tasks'] ?? []) as $item) {
                $newTasks[] = TaskSpec::fromDict(is_array($item) ? $item : []);
            }
            $proposal = new DecompositionProposal(
                parentTaskId: (string) ($proposalValue['parent_task_id'] ?? $context->task->spec->taskId),
                newTasks: $newTasks,
                reuseTaskIds: is_array($proposalValue['reuse_task_ids'] ?? null) ? $proposalValue['reuse_task_ids'] : [],
                rationale: (string) ($proposalValue['rationale'] ?? ''),
                compositionContract: $proposalValue['composition_contract'] ?? null,
                expectedGraphRevision: (int) ($proposalValue['expected_graph_revision'] ?? $context->run->graphRevision),
                proposalId: isset($proposalValue['proposal_id']) ? (string) $proposalValue['proposal_id'] : '',
            );
        } else {
            throw new DAGWorkerProtocolError('decomposition outcome requires a proposal');
        }
        return TaskOutcome::decompose($proposal, $metadata);
    }
}
