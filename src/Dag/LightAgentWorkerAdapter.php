<?php

declare(strict_types=1);

namespace LightAgent\Dag;

/**
 * Require explicit structured TaskOutcome output from a LightAgent-like
 * object (worker.py LightAgentWorkerAdapter).
 */
final class LightAgentWorkerAdapter
{
    /** @var \Closure(TaskContext):string */
    private readonly \Closure $promptBuilder;

    public function __construct(
        private readonly mixed $agent,
        ?\Closure $promptBuilder = null,
    ) {
        $this->promptBuilder = $promptBuilder ?? static fn (TaskContext $ctx): string => self::defaultPrompt($ctx);
    }

    public function execute(TaskContext $context): TaskOutcome
    {
        $query = ($this->promptBuilder)($context);
        $kwargs = [
            'user_id' => $context->securityContext?->userId ?? 'dag-worker',
            'cancellation_token' => $context->cancellationToken,
            'idempotency_key' => "{$context->run->runId}:{$context->task->spec->taskId}:{$context->attempt->attemptNo}",
        ];
        $raw = $this->agent->run($query, $kwargs);
        if (is_object($raw) && isset($raw->error) && $raw->error !== null && $raw->error !== '') {
            throw new DAGWorkerProtocolError('agent returned a failed RunResult');
        }
        $content = is_object($raw) && isset($raw->content) ? $raw->content : $raw;
        if (is_string($content)) {
            $decoded = json_decode($content, true);
            if (!is_array($decoded)) {
                throw new DAGWorkerProtocolError('agent output must be an explicit JSON TaskOutcome');
            }
            $content = $decoded;
        }
        return TaskOutcomes::normalize($content, $context);
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultPrompt(TaskContext $context): array
    {
        $dependencies = [];
        foreach ($context->dependencyArtifacts as $taskId => $artifacts) {
            $dependencies[$taskId] = array_map(static fn (ArtifactManifest $a): array => $a->toDict(), $artifacts);
        }
        return [
            'instruction' => 'Return a JSON TaskOutcome with kind candidate, decomposition, or blocked.',
            'goal' => $context->task->spec->goal,
            'acceptance_contract' => $context->task->spec->acceptanceContract,
            'phase' => $context->attempt->phase,
            'dependencies' => $dependencies,
            'last_diagnostic' => $context->lastDiagnostic,
        ];
    }
}
