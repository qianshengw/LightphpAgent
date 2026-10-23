<?php

declare(strict_types=1);

namespace LightAgent\Flow;
use LightAgent\Async\AsyncRuntime;
use LightAgent\Async\AsyncTimeoutException;
use LightAgent\Async\CancellationToken;
use LightAgent\Errors\ErrorTaxonomy;
use LightAgent\Hooks\HookAction;
use LightAgent\Hooks\HookContext;
use LightAgent\Hooks\HookManager;
use LightAgent\Result\RunResult;
use LightAgent\Review\ApprovalDecision;
use LightAgent\Review\ApprovalRequest;
use LightAgent\Tracing\TraceRecorder;


/**
 * Deterministic workflow runner for LightAgent instances
 * (port of flow.py LightFlow).
 */
class LightFlow
{
    /** @var list<LightFlowStep> */
    protected array $steps = [];

    /** @var array<string, array<string, mixed>> */
    protected array $records = [];

    /** @var array<string, ApprovalDecision> keyed "run_id|step_name" */
    protected array $approvalDecisions = [];

    /** @var array<string, string> keyed "run_id|step_name" */
    protected array $approvalRequestIds = [];

    /** @var array<string, bool> active run ids */
    protected array $activeCancellations = [];

    public function __construct(
        public ?JsonLightFlowStore $store = null,
        ?HookManager $hooks = null,
    ) {
        $this->hooks = $hooks ?? new HookManager();
    }

    public HookManager $hooks;

    /**
     * Register a workflow step and return the flow for chaining.
     *
     * `$fallbackOnTimeout` decides whether the fallback agent may take over
     * after a TIMEOUT (default: yes). `$allowTimeoutOverlap` is a
     * different question: it says whether the timed-out agent may keep
     * running next to the fallback (M-31).
     *
     * @param list<string>|null $dependsOn
     * @param list<mixed>|null $tools
     * @param (callable(array<string, mixed>): mixed)|null $cancelIf
     * @param (callable(mixed, array<string, mixed>): mixed)|null $approvalHandler
     * @param array<string, mixed>|null $metadata
     */
    public function step(
        string $name,
        mixed $agent,
        ?array $dependsOn = null,
        mixed $query = null,
        ?array $tools = null,
        int $maxRetry = 1,
        ?float $timeout = null,
        bool $allowTimeoutOverlap = false,
        mixed $fallbackAgent = null,
        ?\Closure $cancelIf = null,
        bool $requiresApproval = false,
        ?\Closure $approvalHandler = null,
        ?array $metadata = null,
        bool $fallbackOnTimeout = true,
    ): static {
        if ($name === '') {
            throw new \InvalidArgumentException('step name must not be empty');
        }
        foreach ($this->steps as $existing) {
            if ($existing->name === $name) {
                throw new \InvalidArgumentException("step `{$name}` is already registered");
            }
        }
        if (!is_object($agent) || !method_exists($agent, 'run')) {
            throw new \InvalidArgumentException("step `{$name}` agent must provide a run() method");
        }
        if ($fallbackAgent !== null && (!is_object($fallbackAgent) || !method_exists($fallbackAgent, 'run'))) {
            throw new \InvalidArgumentException("step `{$name}` fallback_agent must provide a run() method");
        }
        if ($maxRetry < 1) {
            throw new \InvalidArgumentException('max_retry must be at least 1');
        }
        if ($timeout !== null && $timeout <= 0) {
            throw new \InvalidArgumentException('timeout must be greater than 0');
        }

        $this->steps[] = new LightFlowStep(
            name: $name,
            agent: $agent,
            dependsOn: $dependsOn ?? [],
            query: $query,
            tools: $tools,
            maxRetry: $maxRetry,
            timeout: $timeout,
            allowTimeoutOverlap: $allowTimeoutOverlap,
            fallbackAgent: $fallbackAgent,
            cancelIf: $cancelIf,
            requiresApproval: $requiresApproval,
            approvalHandler: $approvalHandler,
            metadata: $metadata,
            fallbackOnTimeout: $fallbackOnTimeout,
        );
        return $this;
    }

    /**
     * Validate DAG errors and optionally warn on isolated steps.
     *
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function validate(bool $strict = false): array
    {
        $errors = [];
        $warnings = [];
        $stepsByName = [];
        foreach ($this->steps as $step) {
            $stepsByName[$step->name] = $step;
        }

        if (count($stepsByName) !== count($this->steps)) {
            $errors[] = 'step names must be unique';
        }

        $referenced = [];
        foreach ($this->steps as $step) {
            foreach ($step->dependsOn as $dependency) {
                $referenced[$dependency] = true;
            }
        }
        foreach ($this->steps as $step) {
            foreach ($step->dependsOn as $dependency) {
                if (!isset($stepsByName[$dependency])) {
                    $errors[] = "step `{$step->name}` depends on unknown step `{$dependency}`";
                }
            }
            if ($step->dependsOn === [] && !isset($referenced[$step->name]) && count($this->steps) > 1) {
                $warnings[] = "step `{$step->name}` is isolated";
            }
        }

        $temporary = [];
        $permanent = [];
        $visit = function (LightFlowStep $step) use (&$visit, &$temporary, &$permanent, &$errors, $stepsByName): void {
            if (isset($permanent[$step->name])) {
                return;
            }
            if (isset($temporary[$step->name])) {
                $errors[] = "cycle detected at step `{$step->name}`";
                return;
            }
            $temporary[$step->name] = true;
            foreach ($step->dependsOn as $dependency) {
                if (isset($stepsByName[$dependency])) {
                    $visit($stepsByName[$dependency]);
                }
            }
            unset($temporary[$step->name]);
            $permanent[$step->name] = true;
        };

        foreach ($this->steps as $step) {
            $visit($step);
        }

        if ($strict && $warnings !== []) {
            $errors = array_merge($errors, $warnings);
        }
        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Cancel active executions, optionally limited to one workflow run.
     */
    public function cancel(?string $runId = null, ?string $reason = null): int
    {
        $cancelled = 0;
        foreach ($this->activeCancellations as $activeRunId => $_) {
            if ($runId !== null && $activeRunId !== $runId) {
                continue;
            }
            $this->activeRunTokens[$activeRunId]?->cancel($reason ?? 'workflow cancellation requested');
            $cancelled++;
        }
        return $cancelled;
    }

    /** @var array<string, CancellationToken> */
    protected array $activeRunTokens = [];

    protected function startExecution(string $runId, ?CancellationToken $cancellationToken): CancellationToken
    {
        $token = $cancellationToken ?? new CancellationToken(runId: $runId);
        if (isset($this->activeCancellations[$runId])) {
            throw new \InvalidArgumentException("run `{$runId}` is already active");
        }
        $this->activeCancellations[$runId] = true;
        $this->activeRunTokens[$runId] = $token;
        return $token;
    }

    protected function finishExecution(string $runId): void
    {
        unset($this->activeCancellations[$runId]);
        unset($this->activeRunTokens[$runId]);
    }

    /**
     * Run all registered steps once their dependencies are satisfied.
     *
     * @param array<string, mixed> $options run/user_id/trace/result_format/run_id/…
     * @return LightFlowResult|string|array<string, mixed>
     */
    public function run(string $query, array $options = []): mixed
    {
        $resultFormat = (string) ($options['result_format'] ?? 'object');
        if (!in_array($resultFormat, ['object', 'str', 'dict'], true)) {
            throw new \InvalidArgumentException('result_format must be one of: object, str, dict');
        }
        $runId = (string) ($options['run_id'] ?? '') ?: bin2hex(random_bytes(16));
        $token = $this->startExecution($runId, $options['cancellation_token'] ?? null);
        try {
            if (!empty($options['run_id'])) {
                $existing = $this->getRun($runId);
                if ($existing !== null) {
                    return $this->formatResult($this->resultFromRecord($existing), $resultFormat);
                }
            }
            $orderedSteps = $this->orderedSteps();
            return $this->execute(
                query: $query,
                orderedSteps: $orderedSteps,
                userId: (string) ($options['user_id'] ?? 'default_user'),
                trace: (bool) ($options['trace'] ?? false),
                resultFormat: $resultFormat,
                runId: $runId,
                parentTraceId: $options['parent_trace_id'] ?? null,
                runGroupId: $options['run_group_id'] ?? null,
                cancellationToken: $token,
            );
        } finally {
            $this->finishExecution($runId);
        }
    }

    /**
     * Resume a failed or incomplete run from the last checkpoint.
     */
    public function resume(string $runId, array $options = []): mixed
    {
        $token = $this->startExecution($runId, $options['cancellation_token'] ?? null);
        try {
            $record = $this->getRun($runId);
            if ($record === null) {
                throw new \InvalidArgumentException("run `{$runId}` not found");
            }
            $this->restoreApprovalDecisions($runId, $record);
            $this->runFlowHook('on_resume', ['run_id' => $runId, 'record' => $record]);
            $completed = [];
            foreach ($record['steps'] ?? [] as $step) {
                if (($step['status'] ?? null) === FlowConstants::SUCCESS) {
                    $result = LightFlowStepResult::fromDict($step);
                    $completed[$result->name] = $result;
                }
            }
            $orderedSteps = array_values(array_filter(
                $this->orderedSteps(),
                static fn (LightFlowStep $s): bool => !isset($completed[$s->name]),
            ));
            return $this->execute(
                query: (string) ($record['query'] ?? ''),
                orderedSteps: $orderedSteps,
                userId: (string) ($options['user_id'] ?? 'default_user'),
                trace: (bool) ($options['trace'] ?? false),
                resultFormat: (string) ($options['result_format'] ?? 'object'),
                runId: $runId,
                initialCompleted: $completed,
                parentTraceId: $options['parent_trace_id'] ?? null,
                runGroupId: $options['run_group_id'] ?? null,
                cancellationToken: $token,
            );
        } finally {
            $this->finishExecution($runId);
        }
    }

    /**
     * Rerun one step and all downstream steps from a checkpoint.
     */
    public function rerunStep(string $runId, string $stepName, array $options = []): mixed
    {
        $token = $this->startExecution($runId, $options['cancellation_token'] ?? null);
        try {
            $record = $this->getRun($runId);
            if ($record === null) {
                throw new \InvalidArgumentException("run `{$runId}` not found");
            }
            $this->restoreApprovalDecisions($runId, $record);
            $stepsByName = [];
            foreach ($this->orderedSteps() as $step) {
                $stepsByName[$step->name] = $step;
            }
            if (!isset($stepsByName[$stepName])) {
                throw new \InvalidArgumentException("step `{$stepName}` not found");
            }

            $this->runFlowHook('on_rerun', ['run_id' => $runId, 'step_name' => $stepName, 'record' => $record]);
            $downstream = $this->downstreamSteps($stepName);
            foreach ($downstream as $approvalStep) {
                unset($this->approvalDecisions["{$runId}|{$approvalStep}"]);
                unset($this->approvalRequestIds["{$runId}|{$approvalStep}"]);
            }
            $completed = [];
            foreach ($record['steps'] ?? [] as $step) {
                if (($step['status'] ?? null) === FlowConstants::SUCCESS && !in_array((string) $step['name'], $downstream, true)) {
                    $result = LightFlowStepResult::fromDict($step);
                    $completed[$result->name] = $result;
                }
            }
            $orderedSteps = array_values(array_filter(
                $this->orderedSteps(),
                static fn (LightFlowStep $s): bool => in_array($s->name, $downstream, true),
            ));
            return $this->execute(
                query: (string) ($record['query'] ?? ''),
                orderedSteps: $orderedSteps,
                userId: (string) ($options['user_id'] ?? 'default_user'),
                trace: (bool) ($options['trace'] ?? false),
                resultFormat: (string) ($options['result_format'] ?? 'object'),
                runId: $runId,
                initialCompleted: $completed,
                parentTraceId: $options['parent_trace_id'] ?? null,
                runGroupId: $options['run_group_id'] ?? null,
                cancellationToken: $token,
            );
        } finally {
            $this->finishExecution($runId);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRun(string $runId): ?array
    {
        if (isset($this->records[$runId])) {
            return $this->records[$runId];
        }
        if ($this->store !== null) {
            return $this->store->loadRun($runId);
        }
        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRuns(): array
    {
        if ($this->store !== null) {
            return $this->store->listRuns();
        }
        return array_values($this->records);
    }

    /**
     * Persist a human decision so a waiting flow can resume safely.
     */
    public function approve(string $runId, string $stepName, mixed $decision = true): ApprovalDecision
    {
        $record = $this->getRun($runId);
        if ($record === null) {
            throw new \InvalidArgumentException("run `{$runId}` not found");
        }
        $stepRecord = null;
        foreach ($record['steps'] ?? [] as $step) {
            if (($step['name'] ?? null) === $stepName) {
                $stepRecord = $step;
                break;
            }
        }
        if ($stepRecord === null) {
            throw new \InvalidArgumentException("step `{$stepName}` not found in run `{$runId}`");
        }
        $requestId = (string) ($stepRecord['approval_request_id'] ?? '') ?: bin2hex(random_bytes(16));
        $coerced = self::coerceApprovalDecision($decision, requestId: $requestId);
        $this->approvalDecisions["{$runId}|{$stepName}"] = $coerced;
        // Write the FULL record back first (H15): when the run came from the
        // store, $this->records[$runId] is undefined and a bare approvals
        // assignment would persist a truncated checkpoint losing
        // run_id/query/status/steps.
        $this->records[$runId] = $record;
        $this->records[$runId]['approvals'][$stepName] = $coerced->toDict();
        if ($this->store !== null) {
            $this->store->saveRun($runId, $this->records[$runId]);
        }
        return $coerced;
    }

    // ------------------------------------------------------------------
    // Execution core
    // ------------------------------------------------------------------

    /**
     * @param list<LightFlowStep> $orderedSteps
     * @param array<string, LightFlowStepResult>|null $initialCompleted
     * @return LightFlowResult|string|array<string, mixed>
     */
    protected function execute(
        string $query,
        array $orderedSteps,
        string $userId,
        bool $trace,
        string $resultFormat,
        string $runId,
        ?array $initialCompleted = null,
        ?string $parentTraceId = null,
        ?string $runGroupId = null,
        ?CancellationToken $cancellationToken = null,
    ): mixed {
        $cancellationToken ??= new CancellationToken();
        $traceId = bin2hex(random_bytes(16));
        $runGroup = $runGroupId ?? $runId;
        $recorder = new TraceRecorder(enabled: $trace, traceId: $traceId, parentTraceId: $parentTraceId, runGroupId: $runGroup);
        $allSteps = $this->orderedSteps();
        $recorder->record('flow_start', [
            'query' => $query,
            'steps' => array_map(static fn (LightFlowStep $s): string => $s->name, $allSteps),
            'run_id' => $runId,
        ]);

        /** @var array<string, mixed> $context */
        $context = ['input' => $query, 'steps' => [], 'outputs' => []];
        $stepResults = [];
        foreach (($initialCompleted ?? []) as $completed) {
            $context['steps'][$completed->name] = $completed;
            $context['outputs'][$completed->name] = $completed->content;
            $stepResults[] = $completed;
        }
        $this->checkpoint($runId, $query, status: FlowConstants::RUNNING, steps: $stepResults, error: null, allSteps: $allSteps);

        $finalContent = $stepResults !== [] ? $stepResults[count($stepResults) - 1]->content : '';
        $status = FlowConstants::SUCCESS;
        $error = null;

        foreach ($orderedSteps as $step) {
            if ($step->cancelIf !== null && self::invokeCancelIf($step->cancelIf, $context, $query)) {
                $cancellationToken->cancel("cancel_if triggered before step `{$step->name}`");
            }
            if ($cancellationToken->isCancelled()) {
                $cancellationError = ErrorTaxonomy::formatCode(
                    'LA-CANCELLED',
                    $cancellationToken->getReason() ?? 'workflow cancellation requested',
                );
                foreach ($this->pendingSkippedResults($allSteps, $stepResults, $cancellationError) as $skipped) {
                    $stepResults[] = $skipped;
                }
                $status = FlowConstants::SKIPPED;
                $error = $cancellationError;
                $this->checkpoint($runId, $query, status: $status, steps: $stepResults, error: $error, allSteps: $allSteps);
                $recorder->record('flow_cancelled', [
                    'run_id' => $runId,
                    'reason' => $cancellationToken->getReason(),
                    'token_id' => $cancellationToken->tokenId,
                ]);
                $recorder->record('flow_end', ['success' => false, 'error' => $error, 'status' => $status, 'run_id' => $runId]);
                return $this->formatResult(
                    new LightFlowResult(
                        content: $finalContent,
                        steps: $stepResults,
                        traceId: $traceId,
                        trace: $recorder->toList(),
                        error: $error,
                        runId: $runId,
                        status: $status,
                    ),
                    $resultFormat,
                );
            }

            $stepQuery = $this->buildStepQuery($step, $query, $context);
            $stepHook = $this->runFlowHook(
                'before_flow_step',
                ['query' => $stepQuery, 'context' => $context, 'run_id' => $runId],
                traceId: $traceId,
                parentTraceId: $parentTraceId,
                runGroupId: $runGroup,
                userId: $userId,
                flowId: $runId,
                stepName: $step->name,
                recorder: $recorder,
            );
            if ($stepHook->action === HookAction::BLOCK) {
                $result = $this->skippedResult($step, $stepHook->reason ?? 'blocked by before_flow_step hook');
                $stepResults[] = $result;
                // A hook-blocked step must surface in the flow result (M35),
                // not silently report overall success.
                $status = $status === FlowConstants::SUCCESS ? FlowConstants::SKIPPED : $status;
                $error = $error ?? $result->error;
                // A policy block is a HARD stop (H-6 / V4-15). Skipping only
                // the blocked step left its DEPENDENTS running: a downstream
                // step then executed against a policy-denied upstream (and
                // with an empty dependency payload), so the side effects of
                // a denied step still happened. The dependents are skipped
                // exactly like the step-FAILURE path does below, and the
                // flow stops instead of continuing.
                foreach ($this->remainingSkippedResults($step, $allSteps, $stepResults) as $skipped) {
                    $stepResults[] = $skipped;
                }
                $this->checkpoint($runId, $query, status: $status, steps: $stepResults, error: $error, allSteps: $allSteps);
                $recorder->record('flow_end', ['success' => false, 'error' => $error, 'status' => $status, 'run_id' => $runId]);
                return $this->formatResult(
                    new LightFlowResult(
                        content: $finalContent,
                        steps: $stepResults,
                        traceId: $traceId,
                        trace: $recorder->toList(),
                        error: $error,
                        runId: $runId,
                        status: $status,
                    ),
                    $resultFormat,
                );
            }
            if ($stepHook->payload !== null && array_key_exists('query', $stepHook->payload)) {
                $stepQuery = (string) $stepHook->payload['query'];
            }
            $idempotencyKey = "{$runId}:{$step->name}";

            $approvalRequest = new ApprovalRequest(
                action: 'flow_step',
                requestId: $this->approvalRequestIds["{$runId}|{$step->name}"] ?? bin2hex(random_bytes(16)),
                runId: $runId,
                traceId: $traceId,
                actionName: $step->name,
                arguments: ['query' => $stepQuery],
                sourceAgent: is_object($step->agent) ? ($step->agent->name ?? null) : null,
                reviewerMetadata: ['user_id' => $userId],
                metadata: [
                    'step' => $step->name,
                    'depends_on' => $step->dependsOn,
                    'idempotency_key' => $idempotencyKey,
                ],
                allowedDecisions: ['approve', 'reject', 'edit', 'respond'],
            );
            $approvalDecision = $this->checkApproval($step, $context, $approvalRequest, runId: $runId);
            if ($step->requiresApproval) {
                $recorder->record("approval_{$approvalDecision->action}", [
                    'request_id' => $approvalRequest->requestId,
                    'run_id' => $runId,
                    'step' => $step->name,
                    'reason' => $approvalDecision->reason,
                    'reviewer_id' => $approvalDecision->reviewerId,
                ]);
            }
            if ($approvalDecision->action === ApprovalRequest::ACTION_PENDING) {
                $this->approvalRequestIds["{$runId}|{$step->name}"] = $approvalRequest->requestId;
                $this->runFlowHook(
                    'on_approval_required',
                    [
                        'run_id' => $runId,
                        'step' => $step->name,
                        'reason' => $approvalDecision->reason,
                        'request' => $approvalRequest->toDict(),
                    ],
                    traceId: $traceId,
                    parentTraceId: $parentTraceId,
                    runGroupId: $runGroup,
                    userId: $userId,
                    flowId: $runId,
                    stepName: $step->name,
                    recorder: $recorder,
                );
                $result = $this->skippedResult(
                    $step,
                    $approvalDecision->reason ?? 'approval required',
                    status: FlowConstants::WAITING_APPROVAL,
                );
                $result->approvalRequestId = $approvalRequest->requestId;
                $result->approvalDecision = $approvalDecision->action;
                $stepResults[] = $result;
                $this->checkpoint($runId, $query, status: FlowConstants::WAITING_APPROVAL, steps: $stepResults, error: $result->error, allSteps: $allSteps);
                $status = FlowConstants::WAITING_APPROVAL;
                $error = $result->error;
                break;
            }
            if ($approvalDecision->action === ApprovalRequest::ACTION_REJECT) {
                $result = $this->skippedResult($step, $approvalDecision->reason ?? 'approval rejected');
                $result->approvalRequestId = $approvalRequest->requestId;
                $result->approvalDecision = $approvalDecision->action;
                $stepResults[] = $result;
                $status = FlowConstants::FAILED;
                $error = $result->error;
                $this->checkpoint($runId, $query, status: $status, steps: $stepResults, error: $error, allSteps: $allSteps);
                break;
            }
            if ($approvalDecision->action === ApprovalRequest::ACTION_EDIT) {
                $stepQuery = (string) (($approvalDecision->arguments ?? [])['query'] ?? $stepQuery);
            }
            if ($approvalDecision->action === ApprovalRequest::ACTION_RESPOND) {
                $content = $approvalDecision->response ?? '';
                $now = microtime(true);
                $result = new LightFlowStepResult(
                    name: $step->name,
                    content: $content,
                    status: FlowConstants::SUCCESS,
                    startedAt: $now,
                    endedAt: $now,
                    durationMs: 0.0,
                    inputSummary: self::summarize($stepQuery),
                    outputSummary: self::summarize($content),
                    idempotencyKey: $idempotencyKey,
                    approvalRequestId: $approvalRequest->requestId,
                    approvalDecision: $approvalDecision->action,
                );
                $stepResults[] = $result;
                $context['steps'][$step->name] = $result;
                $context['outputs'][$step->name] = $result->content;
                $finalContent = $result->content;
                $recorder->record('step_end', [
                    'step' => $step->name,
                    'status' => FlowConstants::SUCCESS,
                    'success' => true,
                    'duration_ms' => 0.0,
                    'human_response' => true,
                    'idempotency_key' => $idempotencyKey,
                ]);
                $this->checkpoint($runId, $query, status: FlowConstants::SUCCESS, steps: $stepResults, error: null, allSteps: $allSteps);
                continue;
            }

            $recorder->record('step_start', [
                'step' => $step->name,
                'agent' => is_object($step->agent) ? ($step->agent->name ?? null) : null,
                'depends_on' => $step->dependsOn,
                'status' => FlowConstants::RUNNING,
                'input_summary' => self::summarize($stepQuery),
            ]);

            $stepResult = $this->runStep(
                $step,
                $stepQuery,
                userId: $userId,
                trace: $trace,
                parentTraceId: $traceId,
                runGroupId: $runGroup,
                runId: $runId,
                cancellationToken: $cancellationToken,
                idempotencyKey: $idempotencyKey,
            );
            if ($step->requiresApproval) {
                $stepResult->approvalRequestId = $approvalRequest->requestId;
                $stepResult->approvalDecision = $approvalDecision->action;
            }
            $stepResults[] = $stepResult;
            $this->runFlowHook(
                'after_flow_step',
                ['run_id' => $runId, 'step_result' => $stepResult->toDict()],
                traceId: $traceId,
                parentTraceId: $parentTraceId,
                runGroupId: $runGroup,
                userId: $userId,
                flowId: $runId,
                stepName: $step->name,
                recorder: $recorder,
            );
            $context['steps'][$step->name] = $stepResult;
            $context['outputs'][$step->name] = $stepResult->content;
            $finalContent = $stepResult->content;

            $recorder->record('step_end', [
                'step' => $step->name,
                'status' => $stepResult->status,
                'success' => $stepResult->error === null,
                'error' => $stepResult->error,
                'attempts' => $stepResult->attempts,
                'retry_count' => $stepResult->retryCount,
                'duration_ms' => $stepResult->durationMs,
                'input_summary' => $stepResult->inputSummary,
                'output_summary' => $stepResult->outputSummary,
                'used_fallback' => $stepResult->usedFallback,
                'timed_out' => $stepResult->timedOut,
                'timeout_mode' => $stepResult->timeoutMode,
                'idempotency_key' => $stepResult->idempotencyKey,
            ]);

            $this->checkpoint($runId, $query, status: $stepResult->status, steps: $stepResults, error: $stepResult->error, allSteps: $allSteps);
            if ($stepResult->error !== null) {
                $status = $stepResult->status === FlowConstants::SKIPPED ? FlowConstants::SKIPPED : FlowConstants::FAILED;
                $error = $stepResult->error;
                if ($stepResult->status === FlowConstants::SKIPPED) {
                    foreach ($this->pendingSkippedResults($allSteps, $stepResults, $error) as $skipped) {
                        $stepResults[] = $skipped;
                    }
                } else {
                    foreach ($this->remainingSkippedResults($step, $allSteps, $stepResults) as $skipped) {
                        $stepResults[] = $skipped;
                    }
                }
                $this->checkpoint($runId, $query, status: $status, steps: $stepResults, error: $error, allSteps: $allSteps);
                $recorder->record('flow_end', ['success' => false, 'error' => $error, 'status' => $status, 'run_id' => $runId]);
                return $this->formatResult(
                    new LightFlowResult(
                        content: $stepResult->content,
                        steps: $stepResults,
                        traceId: $traceId,
                        trace: $recorder->toList(),
                        error: $error,
                        runId: $runId,
                        status: $status,
                    ),
                    $resultFormat,
                );
            }
        }

        $recorder->record('flow_end', ['success' => $error === null, 'status' => $status, 'run_id' => $runId]);
        return $this->formatResult(
            new LightFlowResult(
                content: $finalContent,
                steps: $stepResults,
                traceId: $traceId,
                trace: $recorder->toList(),
                error: $error,
                runId: $runId,
                status: $status,
            ),
            $resultFormat,
        );
    }

    /**
     * Run one step with retry and optional fallback (flow.py _run_step).
     */
    protected function runStep(
        LightFlowStep $step,
        string $query,
        string $userId,
        bool $trace,
        ?string $parentTraceId,
        ?string $runGroupId,
        string $runId,
        CancellationToken $cancellationToken,
        string $idempotencyKey,
    ): LightFlowStepResult {
        $started = microtime(true);
        $lastResult = null;
        $lastTimedOut = false;

        for ($attempt = 1; $attempt <= $step->maxRetry; $attempt++) {
            if ($cancellationToken->isCancelled()) {
                $result = $this->skippedResult(
                    $step,
                    ErrorTaxonomy::formatCode('LA-CANCELLED', $cancellationToken->getReason() ?? 'workflow cancellation requested'),
                );
                return $result;
            }
            $raisedError = null;
            $rawResult = null;
            $timedOut = false;
            try {
                [$rawResult, $timedOut] = $this->callAgent(
                    $step->agent,
                    $step,
                    $query,
                    userId: $userId,
                    trace: $trace,
                    parentTraceId: $parentTraceId,
                    runGroupId: $runGroupId,
                    cancellationToken: $cancellationToken,
                    idempotencyKey: $idempotencyKey,
                );
            } catch (\Throwable $exc) {
                $raisedError = $exc;
            }
            if ($raisedError !== null) {
                $content = '[LA-FLOW-AGENT-ERROR] step `' . $step->name . '` agent raised ' . $raisedError::class;
                $error = $content;
                $stepTrace = [];
            } else {
                [$content, $error, $stepTrace] = self::normalizeAgentResult($rawResult);
                if ($timedOut) {
                    $error = "step `{$step->name}` timed out after {$step->timeout} seconds";
                    $content = "[LA-FLOW-TIMEOUT] {$error}";
                }
            }
            $lastTimedOut = $timedOut;
            $ended = microtime(true);
            $lastResult = new LightFlowStepResult(
                name: $step->name,
                content: $content,
                error: $error,
                attempts: $attempt,
                trace: $stepTrace,
                status: $error !== null ? FlowConstants::FAILED : FlowConstants::SUCCESS,
                startedAt: $started,
                endedAt: $ended,
                durationMs: round(($ended - $started) * 1000, 3),
                inputSummary: self::summarize($query),
                outputSummary: self::summarize($content),
                retryCount: $attempt - 1,
                timedOut: $timedOut,
                timeoutMode: $timedOut ? 'soft' : null,
                idempotencyKey: $idempotencyKey,
            );
            if ($error === null) {
                return $lastResult;
            }
            if ($timedOut && !$step->allowTimeoutOverlap) {
                break;
            }
        }

        if (
            $lastResult !== null
            && $lastResult->error !== null
            && $step->fallbackAgent !== null
            // M-31: `allowTimeoutOverlap` answers "may the timed-out agent
            // keep running next to the fallback", NOT "may the fallback
            // run at all". Conflating the two meant the fallback agent was
            // NEVER used for a timeout (the default allowTimeoutOverlap =
            // false made the gate false), while an ordinary agent error
            // did use it. `fallbackOnTimeout` decides the fallback
            // explicitly; the overlap behaviour is unchanged.
            && (!$lastTimedOut || $step->fallbackOnTimeout)
        ) {
            if ($cancellationToken->isCancelled()) {
                $result = $this->skippedResult(
                    $step,
                    ErrorTaxonomy::formatCode('LA-CANCELLED', $cancellationToken->getReason() ?? 'workflow cancellation requested'),
                );
                return $result;
            }
            $fallbackRaised = null;
            $fallbackResult = null;
            $timedOut = false;
            try {
                [$fallbackResult, $timedOut] = $this->callAgent(
                    $step->fallbackAgent,
                    $step,
                    $query,
                    userId: $userId,
                    trace: $trace,
                    parentTraceId: $parentTraceId,
                    runGroupId: $runGroupId,
                    cancellationToken: $cancellationToken,
                    idempotencyKey: $idempotencyKey,
                );
            } catch (\Throwable $exc) {
                $fallbackRaised = $exc;
            }
            if ($fallbackRaised !== null) {
                $content = '[LA-FLOW-AGENT-ERROR] fallback for step `' . $step->name . '` agent raised ' . $fallbackRaised::class;
                $error = $content;
                $stepTrace = [];
            } else {
                [$content, $error, $stepTrace] = self::normalizeAgentResult($fallbackResult);
                if ($timedOut) {
                    $error = "fallback for step `{$step->name}` timed out after {$step->timeout} seconds";
                    $content = "[LA-FLOW-TIMEOUT] {$error}";
                }
            }
            $ended = microtime(true);
            return new LightFlowStepResult(
                name: $step->name,
                content: $content,
                error: $error,
                attempts: $lastResult->attempts + 1,
                trace: $stepTrace,
                status: $error !== null ? FlowConstants::FAILED : FlowConstants::SUCCESS,
                startedAt: $started,
                endedAt: $ended,
                durationMs: round(($ended - $started) * 1000, 3),
                inputSummary: self::summarize($query),
                outputSummary: self::summarize($content),
                retryCount: $lastResult->attempts - 1,
                usedFallback: true,
                timedOut: $timedOut,
                timeoutMode: $timedOut ? 'soft' : null,
                idempotencyKey: $idempotencyKey,
            );
        }
        return $lastResult ?? new LightFlowStepResult(name: $step->name, content: '', error: 'step did not run', status: FlowConstants::FAILED);
    }

    /**
     * @return array{0: mixed, 1: bool} result + timedOut
     */
    protected function callAgent(
        mixed $agent,
        LightFlowStep $step,
        string $query,
        string $userId,
        bool $trace,
        ?string $parentTraceId,
        ?string $runGroupId,
        CancellationToken $cancellationToken,
        string $idempotencyKey,
    ): array {
        // A per-STEP cancellation token (V5-24): the FLOW token stays
        // untouched — a step timeout must not kill the workflow, the
        // fallback/retry path and the later steps must still run — but the
        // timed-out agent gets a token it can be signalled through.
        $stepToken = $cancellationToken->child();
        $kwargs = [
            'tools' => $step->tools,
            'stream' => false,
            'user_id' => $userId,
            'metadata' => $step->metadata,
            'result_format' => 'object',
            'trace' => $trace,
            'parent_trace_id' => $parentTraceId,
            'run_group_id' => $runGroupId,
            'cancellation_token' => $stepToken,
            'idempotency_key' => $idempotencyKey,
        ];
        if ($step->timeout === null) {
            return [$agent->run($query, $kwargs), false];
        }
        // Timeout via Future racing (asyncio.wait_for semantics; PORTING-DIFF:
        // Python uses a ThreadPoolExecutor; PHP runs inline inside a fiber).
        //
        // Two complementary paths (H12 + its regression):
        //  - suspending agents (fiber suspension points) raise
        //    AsyncTimeoutException from waitFor() — catch and mark timedOut;
        //  - blocking agents (curl_exec) never suspend, so the timer cannot
        //    fire — the wall-clock check below catches those.
        // NOTE: the flow-level cancellation token is NOT cancelled here — a
        // per-step timeout does not kill the workflow; fallback/retry paths
        // (and later steps) must still run. Python only cancelled the step's
        // own future, never the flow token.
        $startedAt = microtime(true);
        try {
            $result = AsyncRuntime::waitFor(
                static fn (): mixed => $agent->run($query, $kwargs),
                $step->timeout,
            );
        } catch (AsyncTimeoutException) {
            // Signal the timed-out agent so it can stop at its next
            // cooperative boundary (V5-24). The Python original cancels the
            // inner task (asyncio.wait_for), so the side effect of the
            // closure does not run "after" the timeout any more. PHP cannot
            // preempt a running callable, so the signal is COOPERATIVE: an
            // agent that never polls its cancellation token keeps running to
            // completion in the background — its result is then discarded.
            $stepToken->cancel("step `{$step->name}` timed out after {$step->timeout} seconds");
            return [null, true];
        }
        $elapsed = microtime(true) - $startedAt;
        if ($elapsed > $step->timeout) {
            // The agent blocked the event loop for longer than its deadline
            // (e.g. a synchronous HTTP call): it already finished, so there
            // is nothing left to signal — only the result is discarded.
            return [$result, true];
        }
        return [$result, false];
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function runFlowHook(
        string $phase,
        array $payload,
        ?string $traceId = null,
        ?string $parentTraceId = null,
        ?string $runGroupId = null,
        ?string $userId = null,
        ?string $flowId = null,
        ?string $stepName = null,
        ?TraceRecorder $recorder = null,
    ): \LightAgent\Hooks\HookDecision {
        $context = new HookContext(
            phase: $phase,
            payload: $payload,
            traceId: $traceId,
            parentTraceId: $parentTraceId,
            runGroupId: $runGroupId,
            userId: $userId,
            flowId: $flowId,
            stepName: $stepName,
        );
        $decision = $this->hooks->run($context);
        if ($recorder !== null) {
            foreach ($decision->metadata['hook_events'] ?? [] as $event) {
                $recorder->record('hook_decision', $event);
            }
            foreach ($decision->metadata['review_events'] ?? [] as $event) {
                $eventType = (string) ($event['type'] ?? 'approval_decision');
                unset($event['type']);
                $recorder->record($eventType, $event);
            }
            if ($decision->action === HookAction::BLOCK) {
                $recorder->record('hook_block', ['phase' => $phase, 'reason' => $decision->reason, 'step' => $stepName]);
            }
        }
        return $decision;
    }

    /**
     * @return list<LightFlowStep>
     */
    protected function orderedSteps(): array
    {
        $validation = $this->validate();
        if ($validation['errors'] !== []) {
            throw new \InvalidArgumentException($validation['errors'][0]);
        }
        $stepsByName = [];
        foreach ($this->steps as $step) {
            $stepsByName[$step->name] = $step;
        }
        $ordered = [];
        $permanent = [];

        $visit = function (LightFlowStep $step) use (&$visit, &$permanent, &$ordered, $stepsByName): void {
            if (isset($permanent[$step->name])) {
                return;
            }
            foreach ($step->dependsOn as $dependency) {
                $visit($stepsByName[$dependency]);
            }
            $permanent[$step->name] = true;
            $ordered[] = $step;
        };

        foreach ($this->steps as $step) {
            $visit($step);
        }
        return $ordered;
    }

    /**
     * @param array<string, mixed> $context
     */
    protected static function buildStepQuery(LightFlowStep $step, string $originalQuery, array $context): string
    {
        if ($step->query instanceof \Closure) {
            return (string) self::invokeStepCallback($step->query, $context, $originalQuery);
        }
        if ($step->query !== null) {
            return (string) $step->query;
        }
        if ($step->dependsOn === []) {
            return $originalQuery;
        }
        $dependencyOutputs = [];
        foreach ($step->dependsOn as $name) {
            $dependencyOutputs[] = "{$name}: " . (string) ($context['outputs'][$name] ?? '');
        }
        return $originalQuery . "\n\nPrevious step outputs:\n" . implode("\n", $dependencyOutputs);
    }

    /**
     * Invoke a step callback that supports the one-argument and the
     * two-argument convention.
     *
     * The calling convention is decided by the SIGNATURE of the closure:
     *   - a closure that REQUIRES two parameters receives
     *     `(string $query, array $context)`;
     *   - a closure that requires one parameter receives the context.
     *
     * The signature is inspected instead of probing with a call: a
     * two-argument closure with a typed first parameter raises a TypeError
     * (not an ArgumentCountError) when it is called with the context
     * array, and that error used to escape `run()` (V5-24).
     *
     * @param array<string, mixed> $context
     */
    protected static function invokeStepCallback(\Closure $callback, array $context, string $originalQuery): mixed
    {
        $required = self::requiredParameterCount($callback);
        if ($required !== null) {
            if ($required >= 2) {
                return $callback($originalQuery, $context);
            }
            if ($required === 1) {
                return $callback($context);
            }
            return $callback();
        }
        // The signature could not be determined (e.g. a variadic closure):
        // try the one-argument convention first and fall back to the
        // two-argument one.
        try {
            return $callback($context);
        } catch (\ArgumentCountError) {
            return $callback($originalQuery, $context);
        }
    }

    /**
     * The number of REQUIRED parameters of a closure, or null when it
     * cannot be determined.
     */
    private static function requiredParameterCount(\Closure $callback): ?int
    {
        try {
            $reflection = new \ReflectionFunction($callback);
        } catch (\ReflectionException) {
            return null;
        }
        if ($reflection->isVariadic()) {
            return null;
        }
        return $reflection->getNumberOfRequiredParameters();
    }

    /**
     * Invoke a step's `cancelIf` predicate.
     *
     * Both spellings are supported, exactly like `buildStepQuery()`:
     *   - `fn(array $context): bool`
     *   - `fn(string $query, array $context): bool`
     *
     * V5-24: the predicate used to be invoked with exactly ONE argument, so
     * a two-argument closure raised an uncaught `ArgumentCountError` (or a
     * `TypeError` for a typed first parameter) that escaped `run()` and
     * killed the whole flow.
     *
     * @param array<string, mixed> $context
     */
    protected static function invokeCancelIf(\Closure $cancelIf, array $context, string $originalQuery): bool
    {
        return (bool) self::invokeStepCallback($cancelIf, $context, $originalQuery);
    }

    /**
     * @return array{0: string, 1: string|null, 2: list<array<string, mixed>>}
     */
    protected static function normalizeAgentResult(mixed $result): array
    {
        if ($result instanceof RunResult) {
            return [$result->content, $result->error, $result->trace];
        }
        $text = $result === null ? '' : (string) $result;
        $error = str_starts_with($text, '[LA-') ? $text : null;
        return [$text, $error, []];
    }

    /**
     * @return LightFlowResult|string|array<string, mixed>
     */
    protected static function formatResult(LightFlowResult $result, string $resultFormat): mixed
    {
        if ($resultFormat === 'str') {
            return $result->content;
        }
        if ($resultFormat === 'dict') {
            return [
                'content' => $result->content,
                'steps' => array_map(static fn (LightFlowStepResult $s): array => $s->toDict(), $result->steps),
                'trace_id' => $result->traceId,
                'trace' => $result->trace,
                'error' => $result->error,
                'success' => $result->isSuccess(),
                'run_id' => $result->runId,
                'status' => $result->status,
                'idempotent_replay' => $result->idempotentReplay,
            ];
        }
        return $result;
    }

    /**
     * @return LightFlowResult
     */
    protected function resultFromRecord(array $record): LightFlowResult
    {
        $steps = [];
        foreach ($record['steps'] ?? [] as $step) {
            $steps[] = LightFlowStepResult::fromDict($step);
        }
        $completedContent = '';
        for ($i = count($steps) - 1; $i >= 0; $i--) {
            if ($steps[$i]->content !== '') {
                $completedContent = $steps[$i]->content;
                break;
            }
        }
        return new LightFlowResult(
            content: $completedContent,
            steps: $steps,
            error: isset($record['error']) ? (string) $record['error'] : null,
            runId: isset($record['run_id']) ? (string) $record['run_id'] : null,
            status: (string) ($record['status'] ?? FlowConstants::FAILED),
            idempotentReplay: true,
        );
    }

    /**
     * @param list<LightFlowStepResult> $steps
     * @param list<LightFlowStep>|null $allSteps
     */
    protected function checkpoint(
        string $runId,
        string $query,
        string $status,
        array $steps,
        ?string $error,
        ?array $allSteps = null,
    ): void {
        $recordSteps = $steps;
        if ($allSteps !== null) {
            $seen = [];
            foreach ($recordSteps as $result) {
                $seen[$result->name] = true;
            }
            foreach ($allSteps as $step) {
                if (!isset($seen[$step->name])) {
                    $recordSteps[] = new LightFlowStepResult(name: $step->name, content: '', attempts: 0, status: FlowConstants::PENDING);
                }
            }
        }
        $approvals = [];
        foreach ($this->approvalDecisions as $key => $decision) {
            [$decisionRunId, $decisionStep] = explode('|', $key, 2);
            if ($decisionRunId === $runId) {
                $approvals[$decisionStep] = $decision->toDict();
            }
        }
        $record = [
            'run_id' => $runId,
            'query' => $query,
            'status' => $status,
            'error' => $error,
            'steps' => array_map(static fn (LightFlowStepResult $s): array => $s->toDict(), $recordSteps),
            'approvals' => $approvals,
            'updated_at' => time(),
        ];
        $this->records[$runId] = $record;
        if ($this->store !== null) {
            $this->store->saveRun($runId, $record);
        }
    }

    /**
     * @param list<LightFlowStep> $allSteps
     * @param list<LightFlowStepResult> $stepResults
     * @return list<LightFlowStepResult>
     */
    protected function remainingSkippedResults(LightFlowStep $failedStep, array $allSteps, array $stepResults): array
    {
        $seen = [];
        foreach ($stepResults as $result) {
            $seen[$result->name] = true;
        }
        $skipped = [];
        foreach ($allSteps as $step) {
            if (isset($seen[$step->name])) {
                continue;
            }
            $depSeen = false;
            foreach ($step->dependsOn as $dep) {
                if (isset($seen[$dep])) {
                    $depSeen = true;
                    break;
                }
            }
            if (in_array($failedStep->name, $step->dependsOn, true) || $depSeen) {
                $skipped[] = $this->skippedResult($step, "skipped after `{$failedStep->name}` failed");
            }
        }
        return $skipped;
    }

    /**
     * @param list<LightFlowStep> $allSteps
     * @param list<LightFlowStepResult> $stepResults
     * @return list<LightFlowStepResult>
     */
    protected function pendingSkippedResults(array $allSteps, array $stepResults, string $reason): array
    {
        $seen = [];
        foreach ($stepResults as $result) {
            $seen[$result->name] = true;
        }
        $skipped = [];
        foreach ($allSteps as $step) {
            if (!isset($seen[$step->name])) {
                $skipped[] = $this->skippedResult($step, $reason);
            }
        }
        return $skipped;
    }

    protected function skippedResult(LightFlowStep $step, string $reason, string $status = FlowConstants::SKIPPED): LightFlowStepResult
    {
        return new LightFlowStepResult(
            name: $step->name,
            content: '',
            error: $reason,
            attempts: 0,
            status: $status,
            retryCount: 0,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function checkApproval(
        LightFlowStep $step,
        array $context,
        ApprovalRequest $request,
        string $runId,
    ): ApprovalDecision {
        if (!$step->requiresApproval) {
            return ApprovalDecision::approve(['request_id' => $request->requestId]);
        }
        $persisted = $this->approvalDecisions["{$runId}|{$step->name}"] ?? null;
        if ($persisted !== null) {
            $persisted->requestId = $request->requestId;
            return $persisted;
        }
        if ($step->approvalHandler === null) {
            return ApprovalDecision::pending('approval required', ['request_id' => $request->requestId]);
        }
        $approvalContext = array_merge($context, ['approval_request' => $request]);
        try {
            $raw = ($step->approvalHandler)($step, $approvalContext);
        } catch (\Throwable $exc) {
            return ApprovalDecision::reject(
                "approval handler failed: {$exc->getMessage()}",
                ['request_id' => $request->requestId],
            );
        }
        if ($raw === false) {
            return ApprovalDecision::pending('approval rejected', ['request_id' => $request->requestId]);
        }
        if (is_string($raw)) {
            return ApprovalDecision::pending($raw, ['request_id' => $request->requestId]);
        }
        if (is_array($raw) && !array_key_exists('action', $raw)) {
            $approved = $raw['approved'] ?? ($raw['allowed'] ?? false);
            if (!$approved) {
                return ApprovalDecision::pending(
                    isset($raw['reason']) ? (string) $raw['reason'] : 'approval required',
                    ['request_id' => $request->requestId],
                );
            }
        }
        return self::coerceApprovalDecision($raw, requestId: $request->requestId);
    }

    /**
     * @param array<string, mixed> $record
     */
    protected function restoreApprovalDecisions(string $runId, array $record): void
    {
        foreach ($record['approvals'] ?? [] as $stepName => $data) {
            if (is_array($data)) {
                $this->approvalDecisions["{$runId}|{$stepName}"] = ApprovalDecision::fromDict($data);
            }
        }
        foreach ($record['steps'] ?? [] as $step) {
            $requestId = $step['approval_request_id'] ?? null;
            if ($requestId !== null) {
                $this->approvalRequestIds["{$runId}|{$step['name']}"] = (string) $requestId;
            }
        }
    }

    /**
     * @return list<string>
     */
    protected function downstreamSteps(string $stepName): array
    {
        $downstream = [$stepName => true];
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($this->steps as $step) {
                if (!isset($downstream[$step->name])) {
                    foreach ($step->dependsOn as $dep) {
                        if (isset($downstream[$dep])) {
                            $downstream[$step->name] = true;
                            $changed = true;
                            break;
                        }
                    }
                }
            }
        }
        return array_keys($downstream);
    }

    public static function coerceApprovalDecision(mixed $raw, string $requestId): ApprovalDecision
    {
        if ($raw instanceof ApprovalDecision) {
            $raw->requestId = $requestId;
            return $raw;
        }
        if ($raw === true || $raw === null) {
            return ApprovalDecision::approve(['request_id' => $requestId]);
        }
        if ($raw === false) {
            return ApprovalDecision::reject('approval rejected', ['request_id' => $requestId]);
        }
        if (is_string($raw)) {
            return ApprovalDecision::reject($raw, ['request_id' => $requestId]);
        }
        if (is_array($raw)) {
            $action = $raw['action'] ?? null;
            if ($action === null) {
                $approved = $raw['approved'] ?? ($raw['allowed'] ?? false);
                $action = $approved ? 'approve' : 'reject';
            }
            return new ApprovalDecision(
                action: (string) $action,
                requestId: $requestId,
                reason: isset($raw['reason']) ? (string) $raw['reason'] : null,
                arguments: is_array($raw['arguments'] ?? null) ? $raw['arguments'] : null,
                response: isset($raw['response']) ? (string) $raw['response'] : null,
                reviewerId: isset($raw['reviewer_id']) ? (string) $raw['reviewer_id'] : null,
                metadata: is_array($raw['metadata'] ?? null) ? $raw['metadata'] : [],
            );
        }
        if ($raw) {
            return ApprovalDecision::approve(['request_id' => $requestId]);
        }
        return ApprovalDecision::reject('approval rejected', ['request_id' => $requestId]);
    }

    public static function summarize(mixed $value, int $limit = 240): string
    {
        $text = $value === null ? '' : (string) $value;
        return mb_strlen($text) <= $limit ? $text : mb_substr($text, 0, $limit) . '...';
    }
}