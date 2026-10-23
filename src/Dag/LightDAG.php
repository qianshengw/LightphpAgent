<?php

declare(strict_types=1);

namespace LightAgent\Dag;

use LightAgent\Async\AsyncCancelledException;
use LightAgent\Async\AsyncRuntime;
use LightAgent\Async\CancellationToken;
use LightAgent\Capabilities\CapabilityRisk;
use LightAgent\Capabilities\CapabilitySpec;
use LightAgent\Security\CapabilityGate;
use LightAgent\Security\SecurityContext;
use LightAgent\Security\SecurityUtils;
use LightAgent\Support\Json;

/**
 * Opt-in persistent DAG runtime with explicit Worker and Verifier contracts
 * (port of dag/scheduler.py LightDAG).
 *
 * PORTING-DIFF: Python's asyncio.gather maps to AsyncRuntime::gather over
 * fiber-per-claim; the verification semaphore maps to AsyncMutex-based
 * counting via Amp semantics.
 */
final class LightDAG
{
    public CapabilitySpec $executionCapability;
    public SqliteTaskGraphStore $store;
    public DAGConfig $config;
    public LocalArtifactStore $artifactStore;
    public RegistryWorkerFactory $workerFactory;
    /** @var array<string, CallableVerifier> */
    public array $verifiers = [];
    public CapabilityGate $capabilityGate;
    public readonly string $schedulerId;

    /** @var array<string, CancellationToken> */
    private array $runTokens = [];

    /** Number of verifier invocations currently running (V5-40). */
    private int $activeVerifications = 0;

    /** @var list<\Amp\DeferredFuture> */
    private array $verificationWaiters = [];

    /** Number of staged artifacts that are awaiting their verification (V5-40). */
    private int $pendingVerifications = 0;

    /** @var list<\Amp\DeferredFuture> */
    private array $pendingVerificationWaiters = [];

    public function __construct(
        ?SqliteTaskGraphStore $store = null,
        ?LocalArtifactStore $artifactStore = null,
        ?RegistryWorkerFactory $workerFactory = null,
        ?array $verifiers = null,
        ?CapabilityGate $capabilityGate = null,
        ?DAGConfig $config = null,
        ?string $schedulerId = null,
        string $artifactRoot = '.lightagent/artifacts',
    ) {
        $this->config = $config ?? new DAGConfig();
        // Default the graph store to a file next to the artifacts (H8): the
        // previous ':memory:' default silently discarded every run when the
        // process exited, contradicting the "persistent DAG" contract.
        $graphPath = rtrim($artifactRoot, '/\\') . '/graph.sqlite3';
        $this->store = $store ?? new SqliteTaskGraphStore($graphPath);
        // The artifact cleanup must not delete the graph database that
        // lives in the same root (M-24).
        $this->artifactStore = $artifactStore ?? new LocalArtifactStore(
            $artifactRoot,
            maxArtifactBytes: $this->config->maxArtifactBytes,
            reservedFiles: [$graphPath],
        );
        $this->workerFactory = $workerFactory ?? new RegistryWorkerFactory();
        foreach ($verifiers ?? [] as $key => $verifier) {
            $this->verifiers[(string) $key] = $verifier;
        }
        $this->capabilityGate = $capabilityGate ?? new CapabilityGate();
        $this->schedulerId = $schedulerId ?? bin2hex(random_bytes(16));
        $this->executionCapability = new CapabilitySpec(
            'dag.task.execute',
            description: 'Execute one isolated LightDAG task attempt',
            risk: CapabilityRisk::SENSITIVE,
            execute: true,
            persistent: true,
            cancellable: true,
        );
    }

    public function registerWorker(string $key, mixed $worker): void
    {
        $this->workerFactory->register($key, $worker);
    }

    public function registerWorkerFactory(string $key, mixed $factory): void
    {
        $this->workerFactory->registerFactory($key, $factory);
    }

    public function registerVerifier(string $key, CallableVerifier $verifier): void
    {
        if (trim($key) === '') {
            throw new \InvalidArgumentException('verifier key must not be empty');
        }
        $this->verifiers[$key] = $verifier;
    }

    /**
     * @param list<TaskSpec|array<string, mixed>> $rootTasks
     * @param list<string>|null $rootTaskIds
     */
    public function createRun(
        array $rootTasks,
        SecurityContext $context,
        ?string $runId = null,
        ?array $rootTaskIds = null,
    ): DAGRun {
        $tasks = [];
        foreach ($rootTasks as $task) {
            $tasks[] = $task instanceof TaskSpec ? $task : TaskSpec::fromDict($task);
        }
        if ($context->tenantId === null || $context->projectId === null
            || $context->tenantId === '' || $context->projectId === '') {
            throw new DAGError('IDENTITY-REQUIRED', 'SecurityContext requires tenant_id and project_id');
        }
        $resolvedRunId = $runId ?? $context->runId ?? bin2hex(random_bytes(16));
        if ($context->runId !== null && $context->runId !== $resolvedRunId) {
            throw new \InvalidArgumentException('SecurityContext run_id does not match the requested run');
        }
        // replace(context, run_id=resolved)
        $runContext = new SecurityContext(
            userId: $context->userId,
            tenantId: $context->tenantId,
            projectId: $context->projectId,
            sessionId: $context->sessionId,
            runId: $resolvedRunId,
            taskId: $context->taskId,
            attemptId: $context->attemptId,
            agentId: $context->agentId,
            parentAgentId: $context->parentAgentId,
            permissions: $context->permissions,
            resources: $context->resources,
            networkAllowed: $context->networkAllowed,
            sandboxRequired: $context->sandboxRequired,
            deadline: $context->deadline,
            policyVersion: $context->policyVersion,
            approvalTokenIds: $context->approvalTokenIds,
            metadata: $context->metadata,
            schemaVersion: $context->schemaVersion,
            resourcesRestricted: $context->isResourceRestricted(),
        );
        if ($rootTaskIds === null) {
            // The root tasks are the SOURCES of the graph: the tasks that do
            // not depend on any other task (V5-23). Deriving the sinks
            // instead (the tasks nobody depends on) made `rootArtifacts`
            // empty for the README example and reported the last task of
            // the graph as the run root.
            $roots = [];
            foreach ($tasks as $task) {
                if ($task->dependsOn === []) {
                    $roots[] = $task->taskId;
                }
            }
            sort($roots);
        } else {
            $roots = array_values(array_unique($rootTaskIds));
        }
        if ($roots === []) {
            throw new \InvalidArgumentException('at least one root task is required');
        }
        $this->validateInitialRegistrations($tasks);
        $run = new DAGRun(
            tenantId: $context->tenantId,
            projectId: $context->projectId,
            rootTaskIds: $roots,
            runId: $resolvedRunId,
            status: DAGRunStatus::RUNNING,
            configDigest: SecurityUtils::canonicalDigest($this->config->toDict()),
            policyDigest: SecurityUtils::canonicalDigest(['policy_version' => $context->policyVersion]),
            securityContext: $runContext->toDict(),
        );
        return $this->store->createRun($run, $tasks, $this->config);
    }

    /**
     * Run the scheduler loop to completion.
     *
     * @param array<string, mixed> $options context/run overrides
     */
    public function run(string $runId, ?SecurityContext $context = null): DAGRunResult
    {
        return AsyncRuntime::run(fn (): DAGRunResult => $this->arun($runId, $context));
    }

    public function arun(string $runId, ?SecurityContext $context = null): DAGRunResult
    {
        $run = $this->requireRun($runId);
        if (in_array($run->status, [DAGRunStatus::SUCCEEDED, DAGRunStatus::FAILED, DAGRunStatus::CANCELLED], true)) {
            return $this->result($runId);
        }
        if (in_array($run->status, [DAGRunStatus::PAUSED, DAGRunStatus::BLOCKED], true)) {
            return $this->result($runId);
        }
        if ($run->configDigest !== SecurityUtils::canonicalDigest($this->config->toDict())) {
            throw new DAGError(
                'RECOVERY-CONFIG-MISMATCH',
                'the active LightDAG configuration differs from the persisted run',
            );
        }
        $nonTerminal = [];
        foreach ($this->store->listTasks($runId) as $task) {
            if (!in_array($task->state->status, [
                DAGTaskStatus::VERIFIED, DAGTaskStatus::FAILED,
                DAGTaskStatus::CANCELLED, DAGTaskStatus::SUPERSEDED,
            ], true)) {
                $nonTerminal[] = $task->spec;
            }
        }
        $this->validateInitialRegistrations($nonTerminal);
        $baseContext = $this->resolveContext($run, $context);
        $token = new CancellationToken(runId: $runId);
        $this->runTokens[$runId] = $token;
        $epoch = $this->store->acquireSchedulerLease(
            $runId,
            $this->schedulerId,
            $this->config->schedulerLeaseSeconds,
        );
        try {
            // The retry budget has to be applied on the expiry path too:
            // an attempt whose lease lapsed consumed one attempt of the
            // task (C-4).
            $this->store->expireAttempts($runId, $this->config->maxAttemptsPerTask);
            while (true) {
                // Re-check expired leases EVERY round (M33): an attempt whose
                // lease lapses mid-run must be requeued, or the run stalls at
                // BLOCKED forever.
                $this->store->expireAttempts($runId, $this->config->maxAttemptsPerTask);
                $run = $this->requireRun($runId);
                if ($token->isCancelled() && $run->status !== DAGRunStatus::CANCELLED) {
                    $this->store->setRunStatus($runId, DAGRunStatus::CANCELLED, $token->getReason());
                    break;
                }
                if ($run->status !== DAGRunStatus::RUNNING) {
                    break;
                }
                $this->store->renewSchedulerLease(
                    $runId,
                    $this->schedulerId,
                    $epoch,
                    $this->config->schedulerLeaseSeconds,
                );
                $claims = [];
                for ($i = 0; $i < $this->config->maxConcurrency; $i++) {
                    $claim = $this->store->claimReadyTask(
                        $runId,
                        workerId: "{$this->schedulerId}:" . bin2hex(random_bytes(8)),
                        leaseSeconds: $this->config->taskLeaseSeconds,
                    );
                    if ($claim === null) {
                        break;
                    }
                    $claims[] = $claim;
                }
                if ($claims === []) {
                    $run = $this->store->refreshRunStatus($runId);
                    if ($run->status === DAGRunStatus::RUNNING) {
                        $this->store->setRunStatus(
                            $runId,
                            DAGRunStatus::BLOCKED,
                            'no runnable tasks; inspect dependencies, leases, and registrations',
                        );
                    }
                    break;
                }
                // Concurrent claim execution (asyncio.gather equivalent)
                $closures = [];
                foreach ($claims as [$task, $attempt]) {
                    $closures[] = fn (): mixed => $this->executeClaim($runId, $task, $attempt, $baseContext, $token);
                }
                try {
                    $this->executeClaims($closures, $token);
                } catch (AsyncCancelledException) {
                    // A cancelled run returns a cancelled RESULT instead of
                    // throwing (V5-31): the README promises
                    // `$result->status === cancelled`.
                    $run = $this->store->getRun($runId);
                    if ($run !== null && $run->status !== DAGRunStatus::CANCELLED) {
                        $this->store->setRunStatus(
                            $runId,
                            DAGRunStatus::CANCELLED,
                            $token->getReason() ?? 'cancelled',
                        );
                    }
                    break;
                }

                if ($this->config->failurePolicy === 'fail_fast') {
                    $hasFailed = false;
                    foreach ($this->store->listTasks($runId) as $task) {
                        if ($task->state->status === DAGTaskStatus::FAILED) {
                            $hasFailed = true;
                            break;
                        }
                    }
                    if ($hasFailed) {
                        $this->store->setRunStatus(
                            $runId,
                            DAGRunStatus::FAILED,
                            'fail_fast stopped the run after a task failure',
                        );
                        break;
                    }
                }
            }
        } finally {
            $this->store->releaseSchedulerLease($runId, $this->schedulerId, $epoch);
            unset($this->runTokens[$runId]);
        }
        return $this->result($runId);
    }

    public function resume(string $runId, ?SecurityContext $context = null, ?int $expectedGraphRevision = null): DAGRunResult
    {
        $run = $this->requireRun($runId);
        if (in_array($run->status, [DAGRunStatus::SUCCEEDED, DAGRunStatus::FAILED, DAGRunStatus::CANCELLED], true)) {
            return $this->result($runId);
        }
        $this->assertGraphRevision($runId, $expectedGraphRevision);
        $this->store->setRunStatus($runId, DAGRunStatus::RUNNING, 'resume requested');
        return $this->arun($runId, $context);
    }

    /**
     * Pause a run.
     *
     * @param int|null $expectedGraphRevision optimistic concurrency guard
     *        (M-46): when given, the mutation is rejected unless the run
     *        still carries that graph revision.
     */
    public function pause(string $runId, ?string $reason = null, ?int $expectedGraphRevision = null): DAGRun
    {
        $this->assertGraphRevision($runId, $expectedGraphRevision);
        return $this->store->setRunStatus($runId, DAGRunStatus::PAUSED, $reason ?? 'pause requested');
    }

    /**
     * Cancel a run.
     *
     * The cancellation is always applied: it is the emergency brake of the
     * runtime and must not be blocked by a stale revision. The optional
     * revision is only used to detect a stale caller.
     */
    public function cancel(string $runId, ?string $reason = null, ?int $expectedGraphRevision = null): DAGRun
    {
        $this->assertGraphRevision($runId, $expectedGraphRevision);
        $token = $this->runTokens[$runId] ?? null;
        if ($token !== null) {
            $token->cancel($reason ?? 'cancel requested');
        }
        return $this->store->setRunStatus($runId, DAGRunStatus::CANCELLED, $reason ?? 'cancel requested');
    }

    public function resolveBlocker(
        string $runId,
        string $taskId,
        ?string $reason = null,
        ?int $expectedGraphRevision = null,
    ): DAGTask {
        $this->assertGraphRevision($runId, $expectedGraphRevision);
        return $this->store->resolveBlocker($runId, $taskId, $reason);
    }

    public function resolveApproval(
        string $runId,
        string $taskId,
        ?string $reason = null,
        ?int $expectedGraphRevision = null,
    ): DAGTask {
        return $this->resolveBlocker($runId, $taskId, $reason ?? 'approval resolved', $expectedGraphRevision);
    }

    /**
     * Replace an unverified task in place.
     *
     * The expected graph revision is MANDATORY (M-47): defaulting it to the
     * freshly read revision silently disabled the optimistic concurrency
     * check, so a caller that never learned the revision could still
     * rewire a graph that changed in the meantime.
     *
     * @param TaskSpec|array<string, mixed> $replacement
     */
    public function supersedeTask(string $runId, string $taskId, mixed $replacement, ?int $expectedGraphRevision = null): DAGTask
    {
        $value = $replacement instanceof TaskSpec ? $replacement : TaskSpec::fromDict($replacement);
        if ($expectedGraphRevision === null) {
            throw new DAGError(
                'VERSION-CONFLICT',
                'supersedeTask() requires the expected graph revision',
            );
        }
        return $this->store->supersedeTask(
            $runId,
            $taskId,
            $value,
            $expectedGraphRevision,
            $this->config,
        );
    }

    /**
     * Optimistic concurrency guard (M-46).
     *
     * A null expectation means "no expectation" (the mutation is applied
     * unconditionally). A concrete expectation must match the revision the
     * caller last observed, otherwise the mutation is rejected instead of
     * silently overwriting a newer graph.
     */
    private function assertGraphRevision(string $runId, ?int $expectedGraphRevision): void
    {
        if ($expectedGraphRevision === null) {
            return;
        }
        $run = $this->requireRun($runId);
        if ($run->graphRevision !== $expectedGraphRevision) {
            throw new DAGError('VERSION-CONFLICT', 'the graph revision is stale');
        }
    }

    public function getRun(string $runId): ?DAGRun
    {
        return $this->store->getRun($runId);
    }

    /**
     * @return list<DAGTask>
     */
    public function listTasks(string $runId): array
    {
        return $this->store->listTasks($runId);
    }

    public function getTask(string $runId, string $taskId): ?DAGTask
    {
        return $this->store->getTask($runId, $taskId);
    }

    /**
     * @return list<DAGEvent>
     */
    public function events(string $runId, int $after = 0, int $limit = 100): array
    {
        return $this->store->events($runId, $after, $limit);
    }

    private function executeClaim(
        string $runId,
        DAGTask $task,
        TaskAttempt $attempt,
        SecurityContext $baseContext,
        CancellationToken $runToken,
    ): void {
        $token = $runToken->child($runId);
        $worker = null;
        $context = null;
        $resources = $task->spec->resourceRequirements !== []
            ? $task->spec->resourceRequirements
            : $baseContext->resources;
        try {
            $attempt = $this->store->markAttemptRunning($attempt->attemptId, $attempt->leaseEpoch);
            $taskContextSecurity = $baseContext->narrow(
                taskId: $task->spec->taskId,
                attemptId: $attempt->attemptId,
                agentId: $attempt->workerId,
                resources: $resources,
                metadata: ['dag_phase' => $attempt->phase],
            );
            $arguments = [
                'run_id' => $runId,
                'task_id' => $task->spec->taskId,
                'attempt_id' => $attempt->attemptId,
                'worker_key' => $task->spec->workerKey,
                'phase' => $attempt->phase,
            ];
            if ($resources !== []) {
                foreach ($resources as $resource) {
                    $this->capabilityGate->authorize(
                        $this->executionCapability,
                        $task->spec->workerKey,
                        $arguments,
                        $taskContextSecurity,
                        resource: $resource,
                    );
                }
            } else {
                $this->capabilityGate->authorize(
                    $this->executionCapability,
                    $task->spec->workerKey,
                    $arguments,
                    $taskContextSecurity,
                );
            }
            $dependencyArtifacts = [];
            foreach ($this->store->dependencies($runId, $task->spec->taskId) as $dependencyId) {
                $dependencyArtifacts[$dependencyId] = $this->store->listArtifacts($runId, $dependencyId);
            }
            $context = new TaskContext(
                run: $this->requireRun($runId),
                task: $task,
                attempt: $attempt,
                dependencyArtifacts: $dependencyArtifacts,
                securityContext: $taskContextSecurity,
                cancellationToken: $token,
                lastDiagnostic: $task->state->lastDiagnostic,
            );
            $worker = $this->workerFactory->create($task->spec->workerKey, $context);
            // Keep the attempt lease alive while the worker runs (C-4a).
            // Without the renewal a task that legitimately needs more time
            // than `task_lease_seconds` (a model call) loses its lease
            // mid-flight and is executed again and again.
            $heartbeat = $this->startLeaseHeartbeat($attempt, $token);
            try {
                $outcome = $worker->execute($context);
            } finally {
                $heartbeat->stop = true;
            }
            if ($token->isCancelled()) {
                throw new AsyncCancelledException($token->getReason() ?? 'cancelled');
            }
            // The worker may have outlived its lease: a worker that blocks
            // the event loop stops the heartbeat from running. Renew the
            // lease before the result is staged - the attempt still owns
            // the task, so its result must not be thrown away (C-4a).
            $this->store->renewAttempt(
                $attempt->attemptId,
                $attempt->leaseEpoch,
                $this->config->taskLeaseSeconds,
                allowLapsedLease: true,
            );
            if ($outcome->kind === TaskOutcomeKind::BLOCKED) {
                $this->store->blockAttempt($attempt->attemptId, $attempt->leaseEpoch, $outcome->blocker ?? 'blocked');
                return;
            }
            $verifierKey = $outcome->kind === TaskOutcomeKind::DECOMPOSITION
                ? ($task->spec->decomposerKey ?? $task->spec->verifierKey)
                : $task->spec->verifierKey;
            $verifier = $this->verifiers[$verifierKey] ?? null;
            if ($verifier === null) {
                $this->store->blockAttempt(
                    $attempt->attemptId,
                    $attempt->leaseEpoch,
                    "verifier `{$verifierKey}` is not registered",
                );
                return;
            }
            if ($outcome->kind === TaskOutcomeKind::DECOMPOSITION) {
                $request = new VerificationRequest(
                    kind: 'decomposition',
                    run: $context->run,
                    task: $task,
                    attempt: $attempt,
                    inputSnapshotHash: $attempt->inputSnapshotHash,
                    decomposition: $outcome->decomposition,
                    dependencyArtifacts: $dependencyArtifacts,
                    securityContext: $taskContextSecurity,
                );
                $report = $this->runVerifier($verifier, $request);
                // The verification may have outlived the lease as well:
                // renew it before the result is published (C-4a).
                $this->store->renewAttempt(
                    $attempt->attemptId,
                    $attempt->leaseEpoch,
                    $this->config->taskLeaseSeconds,
                    allowLapsedLease: true,
                );
                if ($report->verdict === VerificationVerdict::PASS) {
                    $this->store->acceptDecomposition(
                        $runId,
                        $attempt->attemptId,
                        $attempt->leaseEpoch,
                        $outcome->decomposition,
                        $report,
                        $this->config,
                    );
                } else {
                    $this->store->rejectCandidate(
                        $attempt->attemptId,
                        $attempt->leaseEpoch,
                        $report,
                        $this->config->maxAttemptsPerTask,
                    );
                }
                return;
            }
            $content = self::candidateBytes($outcome->content);
            // A staged artifact awaits its verification: bound how many of
            // them may be in flight (V5-40 — the option was declared but
            // never read).
            $this->acquirePendingVerificationSlot();
            try {
                $manifest = $this->artifactStore->stage(
                    $content,
                    $context->run,
                    $task,
                    $attempt,
                    mediaType: $outcome->mediaType,
                    dependencyManifest: array_map(
                        static fn (array $values): array => array_map(
                            static fn (ArtifactManifest $a): string => $a->artifactId,
                            $values,
                        ),
                        $dependencyArtifacts,
                    ),
                );
                $manifest->metadata = array_merge($manifest->metadata, $outcome->metadata);
                $manifest = $this->store->saveStagedArtifact($manifest, $attempt->leaseEpoch);
                if (!$this->artifactStore->checkIntegrity($manifest)) {
                    throw new DAGError('ARTIFACT-INTEGRITY', 'staged artifact failed integrity verification');
                }
                $request = new VerificationRequest(
                    kind: 'completion',
                    run: $context->run,
                    task: $task,
                    attempt: $attempt,
                    inputSnapshotHash: $attempt->inputSnapshotHash,
                    candidate: $manifest,
                    candidateContent: $content,
                    dependencyArtifacts: $dependencyArtifacts,
                    securityContext: $taskContextSecurity,
                );
                $report = $this->runVerifier($verifier, $request);
                // The verification may have outlived the lease as well:
                // renew it before the artifact is published (C-4a).
                $this->store->renewAttempt(
                    $attempt->attemptId,
                    $attempt->leaseEpoch,
                    $this->config->taskLeaseSeconds,
                    allowLapsedLease: true,
                );
                if ($report->verdict === VerificationVerdict::PASS) {
                    $this->store->publishArtifact($manifest, $report, $attempt->leaseEpoch);
                } else {
                    $this->store->rejectCandidate(
                        $attempt->attemptId,
                        $attempt->leaseEpoch,
                        $report,
                        $this->config->maxAttemptsPerTask,
                    );
                }
            } finally {
                $this->releasePendingVerificationSlot();
            }
        } catch (AsyncCancelledException $error) {
            $reason = $token->getReason() ?? 'cancelled';
            $current = $this->store->getAttempt($attempt->attemptId);
            if ($current !== null && !in_array($current->status->value, ['expired', 'cancelled', 'failed'], true)) {
                try {
                    if ($token->isCancelled()) {
                        // The run was cancelled: the attempt is CANCELLED,
                        // not blocked. Blocking it would overwrite the
                        // CANCELLED task status written by the run level
                        // cancellation (V5-31).
                        $this->store->cancelAttempt($attempt->attemptId, $attempt->leaseEpoch, $reason);
                    } else {
                        $this->store->blockAttempt($attempt->attemptId, $attempt->leaseEpoch, $reason);
                    }
                } catch (DAGError) {
                    // pass
                }
            }
            // A cancelled run returns a cancelled RESULT instead of
            // throwing (V5-31): the scheduler loop turns the cancellation
            // into a cancelled run status.
        } catch (\Throwable $error) {
            $reason = 'task attempt failed: ' . $error::class . ': ' . $error->getMessage();
            // The worker may have outlived its lease while it failed: renew
            // the lease first, so the failure is recorded on the attempt
            // itself instead of being rejected as a stale attempt (C-4a).
            try {
                $this->store->renewAttempt(
                    $attempt->attemptId,
                    $attempt->leaseEpoch,
                    $this->config->taskLeaseSeconds,
                    allowLapsedLease: true,
                );
            } catch (\Throwable) {
                // The attempt is gone: fall through to the stale handling.
            }
            try {
                $this->store->failAttempt(
                    $attempt->attemptId,
                    $attempt->leaseEpoch,
                    $reason,
                    $this->config->maxAttemptsPerTask,
                );
            } catch (DAGError $dagError) {
                // The attempt does not own the task anymore: its lease
                // lapsed while the worker was running, or the task was
                // superseded. failAttempt() refuses to record the failure,
                // and swallowing the error made it invisible while the
                // retry budget was never consulted (C-4c): record the
                // stale failure instead.
                try {
                    $this->store->recordStaleAttemptFailure(
                        $attempt->attemptId,
                        $reason . ' | ' . $dagError->getMessage(),
                        $this->config->maxAttemptsPerTask,
                    );
                } catch (\Throwable) {
                    // pass
                }
            } catch (\RuntimeException) {
                // pass
            }
        } finally {
            if ($worker !== null && $context !== null) {
                try {
                    $this->workerFactory->release($worker, $context);
                } catch (\Throwable) {
                    // pass
                }
            }
        }
    }

    /**
     * Run the claim closures concurrently (asyncio.gather equivalent).
     *
     * Contrary to a bare gather(), a failing or cancelled claim does not
     * leave the sibling fibers running: the cancelled run token makes the
     * workers stop at their next safe boundary and every fiber is awaited
     * before the scheduler continues, so `run()` never returns while a
     * worker is still executing (V5-31).
     *
     * @param list<\Closure():mixed> $closures
     */
    private function executeClaims(array $closures, CancellationToken $token): void
    {
        $futures = [];
        foreach ($closures as $closure) {
            $futures[] = AsyncRuntime::async($closure);
        }
        $failure = null;
        foreach ($futures as $future) {
            try {
                $future->await();
            } catch (\Throwable $error) {
                $failure = $error;
                break;
            }
            if ($token->isCancelled()) {
                $failure = new AsyncCancelledException($token->getReason() ?? 'cancelled');
                break;
            }
        }
        if ($failure === null) {
            return;
        }
        foreach ($futures as $future) {
            if ($future->isComplete()) {
                continue;
            }
            try {
                $future->await();
            } catch (\Throwable) {
                // The first failure is the reported one; the errors of the
                // siblings are cleanup noise.
            }
        }
        throw $failure;
    }

    /**
     * Renew the attempt lease while the worker runs (C-4a).
     *
     * The heartbeat only runs while the event loop is free: a worker that
     * blocks the loop (a synchronous model call) cannot be renewed from
     * the outside, which is why the lease is renewed once more after the
     * worker returned.
     *
     * @return \stdClass mutable stop flag
     */
    private function startLeaseHeartbeat(TaskAttempt $attempt, CancellationToken $token): \stdClass
    {
        $state = new \stdClass();
        $state->stop = false;
        $leaseSeconds = $this->config->taskLeaseSeconds;
        $interval = max(0.05, $leaseSeconds / 3);
        AsyncRuntime::async(function () use ($state, $attempt, $token, $leaseSeconds, $interval): void {
            try {
                while (!$state->stop && !$token->isCancelled()) {
                    AsyncRuntime::sleep($interval);
                    if ($state->stop || $token->isCancelled()) {
                        return;
                    }
                    try {
                        $this->store->renewAttempt($attempt->attemptId, $attempt->leaseEpoch, $leaseSeconds);
                    } catch (\Throwable) {
                        // The attempt is gone (published, failed, expired or
                        // superseded): stop the heartbeat.
                        return;
                    }
                }
            } catch (\Throwable) {
                // The heartbeat must never take the scheduler down.
            }
        });
        return $state;
    }

    /**
     * Run a verifier under the verification concurrency limit (V5-40).
     */
    private function runVerifier(CallableVerifier $verifier, VerificationRequest $request): VerificationReport
    {
        $this->acquireVerificationSlot();
        try {
            return $verifier->verify($request);
        } finally {
            $this->releaseVerificationSlot();
        }
    }

    /**
     * Counting semaphore: at most `verification_concurrency` verifiers run
     * at the same time (V5-40 — the option was declared but never read).
     */
    private function acquireVerificationSlot(): void
    {
        if ($this->activeVerifications < $this->config->verificationConcurrency) {
            $this->activeVerifications++;
            return;
        }
        $deferred = new \Amp\DeferredFuture();
        $this->verificationWaiters[] = $deferred;
        $deferred->getFuture()->await();
    }

    private function releaseVerificationSlot(): void
    {
        $waiter = array_shift($this->verificationWaiters);
        if ($waiter !== null) {
            // Hand the slot over to the next waiter.
            $waiter->complete();
            return;
        }
        $this->activeVerifications = max(0, $this->activeVerifications - 1);
    }

    /**
     * Counting semaphore: at most `max_pending_verifications` artifacts may
     * be staged and waiting for their verification (V5-40).
     */
    private function acquirePendingVerificationSlot(): void
    {
        if ($this->pendingVerifications < $this->config->maxPendingVerifications) {
            $this->pendingVerifications++;
            return;
        }
        $deferred = new \Amp\DeferredFuture();
        $this->pendingVerificationWaiters[] = $deferred;
        $deferred->getFuture()->await();
    }

    private function releasePendingVerificationSlot(): void
    {
        $waiter = array_shift($this->pendingVerificationWaiters);
        if ($waiter !== null) {
            $waiter->complete();
            return;
        }
        $this->pendingVerifications = max(0, $this->pendingVerifications - 1);
    }

    /**
     * @param list<TaskSpec> $tasks
     */
    private function validateInitialRegistrations(array $tasks): void
    {
        $missingWorkers = [];
        foreach ($tasks as $task) {
            if (!isset($this->workerFactory->registrations[$task->workerKey])) {
                $missingWorkers[$task->workerKey] = true;
            }
        }
        if ($missingWorkers !== []) {
            $sorted = array_keys($missingWorkers);
            sort($sorted);
            throw new DAGError('RECOVERY-CONFIG-MISMATCH', 'unregistered workers: ' . json_encode($sorted));
        }
        $missingVerifiers = [];
        foreach ($tasks as $task) {
            foreach ([$task->verifierKey, $task->decomposerKey] as $key) {
                if ($key !== null && $key !== '' && !isset($this->verifiers[$key])) {
                    $missingVerifiers[$key] = true;
                }
            }
        }
        if ($missingVerifiers !== []) {
            $sorted = array_keys($missingVerifiers);
            sort($sorted);
            throw new DAGError('VERIFIER-UNAVAILABLE', 'unregistered verifiers: ' . json_encode($sorted));
        }
    }

    public static function candidateBytes(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        return Json::encodeOrError($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function resolveContext(DAGRun $run, ?SecurityContext $context): SecurityContext
    {
        $stored = SecurityContext::fromDict($run->securityContext);
        $value = $context ?? $stored;
        if ($value->tenantId !== $run->tenantId || $value->projectId !== $run->projectId) {
            throw new \RuntimeException('SecurityContext does not match the DAG tenant/project');
        }
        if ($value->runId !== null && $value->runId !== $run->runId) {
            throw new \RuntimeException('SecurityContext does not match the DAG run');
        }
        if ($value->runId === $run->runId) {
            return $value;
        }
        // replace(value, run_id=run.run_id)
        return new SecurityContext(
            userId: $value->userId,
            tenantId: $value->tenantId,
            projectId: $value->projectId,
            sessionId: $value->sessionId,
            runId: $run->runId,
            taskId: $value->taskId,
            attemptId: $value->attemptId,
            agentId: $value->agentId,
            parentAgentId: $value->parentAgentId,
            permissions: $value->permissions,
            resources: $value->resources,
            resourcesRestricted: $value->isResourceRestricted(),
            networkAllowed: $value->networkAllowed,
            sandboxRequired: $value->sandboxRequired,
            deadline: $value->deadline,
            policyVersion: $value->policyVersion,
            approvalTokenIds: $value->approvalTokenIds,
            metadata: $value->metadata,
            schemaVersion: $value->schemaVersion,
        );
    }

    private function requireRun(string $runId): DAGRun
    {
        $run = $this->store->getRun($runId);
        if ($run === null) {
            throw new \RuntimeException($runId);
        }
        return $run;
    }

    private function result(string $runId): DAGRunResult
    {
        $run = $this->requireRun($runId);
        $tasks = $this->store->listTasks($runId);
        $events = $this->store->events($runId, 0, 1_000_000);
        $rootArtifacts = [];
        foreach ($run->rootTaskIds as $taskId) {
            $rootArtifacts[$taskId] = $this->store->listArtifacts($runId, $taskId);
        }
        foreach ($rootArtifacts as $artifacts) {
            foreach ($artifacts as $manifest) {
                if (!$this->artifactStore->checkIntegrity($manifest)) {
                    throw new DAGError(
                        'ARTIFACT-INTEGRITY',
                        "published artifact `{$manifest->artifactId}` failed integrity verification",
                    );
                }
            }
        }
        $failedTasks = [];
        $blockers = [];
        foreach ($tasks as $task) {
            if ($task->state->status === DAGTaskStatus::FAILED) {
                $failedTasks[] = $task->spec->taskId;
            }
            if (in_array($task->state->status, [DAGTaskStatus::BLOCKED, DAGTaskStatus::WAITING_APPROVAL], true)) {
                $blockers[$task->spec->taskId] = $task->state->blocker
                    ?? $task->state->lastDiagnostic
                    ?? 'blocked';
            }
        }
        $lastEvent = $events !== [] ? $events[count($events) - 1] : null;
        return new DAGRunResult(
            runId: $runId,
            status: $run->status,
            rootArtifacts: $rootArtifacts,
            failedTasks: $failedTasks,
            blockers: $blockers,
            eventCursor: $lastEvent !== null ? $lastEvent->sequence : 0,
        );
    }
}
