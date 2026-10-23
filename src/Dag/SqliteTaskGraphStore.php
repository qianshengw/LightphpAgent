<?php

declare(strict_types=1);

namespace LightAgent\Dag;

use LightAgent\Support\Timestamp;
use LightAgent\Support\Json;
use LightAgent\Security\SecurityUtils;

/**
 * Single-host SQLite task-graph store with transactional CAS and fencing
 * (port of dag/store.py SqliteTaskGraphStore).
 */
final class SqliteTaskGraphStore
{
    public const SCHEMA_VERSION = 1;

    public readonly string $path;
    private \PDO $pdo;

    /**
     * Test-only accessor to the underlying PDO handle.
     */
    public function pdoForTest(): \PDO
    {
        return $this->pdo;
    }

    public function __construct(string $path = ':memory:')
    {
        $this->path = $path;
        if ($path === ':memory:') {
            $this->pdo = new \PDO('sqlite::memory:', null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        } else {
            $dir = dirname($path);
            if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            $this->pdo = new \PDO('sqlite:' . $path, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        }
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        if ($path !== ':memory:') {
            $this->pdo->exec('PRAGMA journal_mode = WAL');
        }
        $this->initialize();
    }

    public function close(): void
    {
        $this->pdo = null;
    }

    private function initialize(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS dag_meta (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS dag_runs (
                run_id TEXT PRIMARY KEY,
                status TEXT NOT NULL,
                graph_revision INTEGER NOT NULL,
                scheduler_epoch INTEGER NOT NULL,
                payload TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS dag_tasks (
                run_id TEXT NOT NULL,
                task_id TEXT NOT NULL,
                status TEXT NOT NULL,
                priority INTEGER NOT NULL,
                spec TEXT NOT NULL,
                state TEXT NOT NULL,
                created_at TEXT NOT NULL,
                PRIMARY KEY (run_id, task_id),
                FOREIGN KEY (run_id) REFERENCES dag_runs(run_id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS dag_tasks_ready
                ON dag_tasks(run_id, status, priority DESC, created_at, task_id);
            CREATE TABLE IF NOT EXISTS dag_dependencies (
                run_id TEXT NOT NULL,
                task_id TEXT NOT NULL,
                dependency_id TEXT NOT NULL,
                decomposition_id TEXT NOT NULL,
                PRIMARY KEY (run_id, task_id, dependency_id),
                FOREIGN KEY (run_id, task_id) REFERENCES dag_tasks(run_id, task_id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS dag_dependencies_reverse
                ON dag_dependencies(run_id, dependency_id, task_id);
            CREATE TABLE IF NOT EXISTS dag_attempts (
                attempt_id TEXT PRIMARY KEY,
                run_id TEXT NOT NULL,
                task_id TEXT NOT NULL,
                status TEXT NOT NULL,
                lease_epoch INTEGER NOT NULL,
                lease_expires_at TEXT,
                payload TEXT NOT NULL,
                FOREIGN KEY (run_id, task_id) REFERENCES dag_tasks(run_id, task_id) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS dag_attempts_lease
                ON dag_attempts(run_id, status, lease_expires_at);
            CREATE TABLE IF NOT EXISTS dag_events (
                run_id TEXT NOT NULL,
                sequence INTEGER NOT NULL,
                payload TEXT NOT NULL,
                PRIMARY KEY (run_id, sequence),
                FOREIGN KEY (run_id) REFERENCES dag_runs(run_id) ON DELETE CASCADE
            );
            CREATE TABLE IF NOT EXISTS dag_commands (
                run_id TEXT NOT NULL,
                command_key TEXT NOT NULL,
                request_digest TEXT NOT NULL,
                response TEXT NOT NULL,
                PRIMARY KEY (run_id, command_key),
                FOREIGN KEY (run_id) REFERENCES dag_runs(run_id) ON DELETE CASCADE
            );
            CREATE TABLE IF NOT EXISTS dag_reports (
                report_id TEXT PRIMARY KEY,
                run_id TEXT NOT NULL,
                task_id TEXT NOT NULL,
                attempt_id TEXT NOT NULL,
                payload TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS dag_artifacts (
                artifact_id TEXT PRIMARY KEY,
                run_id TEXT NOT NULL,
                task_id TEXT NOT NULL,
                attempt_id TEXT NOT NULL,
                state TEXT NOT NULL,
                content_hash TEXT NOT NULL,
                payload TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS dag_artifacts_task
                ON dag_artifacts(run_id, task_id, state);
            CREATE TABLE IF NOT EXISTS dag_decompositions (
                proposal_id TEXT PRIMARY KEY,
                run_id TEXT NOT NULL,
                parent_task_id TEXT NOT NULL,
                proposal_digest TEXT NOT NULL,
                payload TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS dag_scheduler_leases (
                run_id TEXT PRIMARY KEY,
                owner_id TEXT NOT NULL,
                epoch INTEGER NOT NULL,
                expires_at TEXT NOT NULL
            );
            SQL);
        $stmt = $this->pdo->query("SELECT value FROM dag_meta WHERE key='schema_version'");
        $row = $stmt->fetch();
        if ($row === false) {
            $ins = $this->pdo->prepare("INSERT INTO dag_meta(key, value) VALUES('schema_version', ?)");
            $ins->execute([(string) self::SCHEMA_VERSION]);
        } elseif ((int) $row['value'] !== self::SCHEMA_VERSION) {
            throw new DAGError('SCHEMA-MISMATCH', "unsupported DAG schema version {$row['value']}");
        }
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    public function getRun(string $runId): ?DAGRun
    {
        $stmt = $this->pdo->prepare('SELECT payload FROM dag_runs WHERE run_id=?');
        $stmt->execute([$runId]);
        $row = $stmt->fetch();
        return $row !== false ? DAGRun::fromDict(json_decode((string) $row['payload'], true)) : null;
    }

    /**
     * @return list<DAGTask>
     */
    public function listTasks(string $runId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT spec,state,created_at FROM dag_tasks WHERE run_id=? ORDER BY priority DESC,created_at,task_id',
        );
        $stmt->execute([$runId]);
        return array_map([$this, 'taskFromRow'], $stmt->fetchAll());
    }

    public function getTask(string $runId, string $taskId): ?DAGTask
    {
        $stmt = $this->pdo->prepare(
            'SELECT spec,state,created_at FROM dag_tasks WHERE run_id=? AND task_id=?',
        );
        $stmt->execute([$runId, $taskId]);
        $row = $stmt->fetch();
        return $row !== false ? $this->taskFromRow($row) : null;
    }

    /**
     * @return list<string>
     */
    public function dependencies(string $runId, string $taskId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT dependency_id FROM dag_dependencies WHERE run_id=? AND task_id=? ORDER BY dependency_id',
        );
        $stmt->execute([$runId, $taskId]);
        return array_map(static fn (array $row): string => (string) $row['dependency_id'], $stmt->fetchAll());
    }

    /**
     * @return list<DAGEvent>
     */
    public function events(string $runId, int $after = 0, int $limit = 100): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('limit must be positive');
        }
        $stmt = $this->pdo->prepare(
            'SELECT payload FROM dag_events WHERE run_id=? AND sequence>? ORDER BY sequence LIMIT ?',
        );
        $stmt->execute([$runId, $after, $limit]);
        return array_map(
            static fn (array $row): DAGEvent => self::eventFromDict(json_decode((string) $row['payload'], true)),
            $stmt->fetchAll(),
        );
    }

    public function getAttempt(string $attemptId): ?TaskAttempt
    {
        $stmt = $this->pdo->prepare('SELECT payload FROM dag_attempts WHERE attempt_id=?');
        $stmt->execute([$attemptId]);
        $row = $stmt->fetch();
        return $row !== false ? TaskAttempt::fromDict(json_decode((string) $row['payload'], true)) : null;
    }

    /**
     * @return list<ArtifactManifest>
     */
    public function listArtifacts(string $runId, ?string $taskId = null, ?string $state = 'published'): array
    {
        $clauses = ['run_id=?'];
        $params = [$runId];
        if ($taskId !== null) {
            $clauses[] = 'task_id=?';
            $params[] = $taskId;
        }
        if ($state !== null) {
            $clauses[] = 'state=?';
            $params[] = $state;
        }
        $stmt = $this->pdo->prepare(
            'SELECT payload FROM dag_artifacts WHERE ' . implode(' AND ', $clauses) . ' ORDER BY artifact_id',
        );
        $stmt->execute($params);
        return array_map(
            static fn (array $row): ArtifactManifest => ArtifactManifest::fromDict(json_decode((string) $row['payload'], true)),
            $stmt->fetchAll(),
        );
    }

    public function getReport(string $reportId): ?VerificationReport
    {
        $stmt = $this->pdo->prepare('SELECT payload FROM dag_reports WHERE report_id=?');
        $stmt->execute([$reportId]);
        $row = $stmt->fetch();
        return $row !== false ? VerificationReport::fromDict(json_decode((string) $row['payload'], true)) : null;
    }

    // ------------------------------------------------------------------
    // Transaction helper (BEGIN IMMEDIATE equivalent via PDO transaction)
    // ------------------------------------------------------------------

    /**
     * @template T
     * @param \Closure(\PDO):T $fn
     * @return T
     */
    private function transaction(\Closure $fn): mixed
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $fn($this->pdo);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Run creation (idempotent via command digests)
    // ------------------------------------------------------------------

    /**
     * @param list<TaskSpec>|array<TaskSpec> $tasks
     */
    public function createRun(DAGRun $run, array $tasks, DAGConfig $config): DAGRun
    {
        $taskList = array_values($tasks);
        if ($run->tenantId === '' || $run->projectId === '') {
            throw new DAGError('IDENTITY-REQUIRED', 'tenant_id and project_id are required');
        }
        if ($taskList === []) {
            throw new \InvalidArgumentException('at least one task is required');
        }
        if (count($taskList) > $config->maxTasks) {
            throw new DAGError('BUDGET-EXHAUSTED', 'task count exceeds max_tasks');
        }
        $byId = [];
        foreach ($taskList as $task) {
            $byId[$task->taskId] = $task;
        }
        if (count($byId) !== count($taskList)) {
            throw new \InvalidArgumentException('task IDs must be unique');
        }
        if (array_diff($run->rootTaskIds, array_keys($byId)) !== []) {
            throw new DAGError('UNKNOWN-DEPENDENCY', 'every root task must exist in the run');
        }
        $edges = [];
        foreach ($taskList as $task) {
            foreach ($task->dependsOn as $dep) {
                $edges["{$task->taskId}|{$dep}"] = [$task->taskId, $dep];
            }
        }
        if (count($edges) > $config->maxEdges) {
            throw new DAGError('BUDGET-EXHAUSTED', 'edge count exceeds max_edges');
        }
        self::validateGraph(array_keys($byId), array_values($edges));
        $resolvedConfigDigest = $run->configDigest !== '' ? $run->configDigest : SecurityUtils::canonicalDigest($config->toDict());
        $runIdentity = [
            'run_id' => $run->runId,
            'tenant_id' => $run->tenantId,
            'project_id' => $run->projectId,
            'root_task_ids' => $run->rootTaskIds,
            'config_digest' => $resolvedConfigDigest,
            'policy_digest' => $run->policyDigest,
            'budget_limits' => $run->budgetLimits,
            'security_context' => $run->securityContext,
            'schema_version' => $run->schemaVersion,
        ];
        $payloadDigest = SecurityUtils::canonicalDigest([
            'run' => $runIdentity,
            'tasks' => array_map(static fn (TaskSpec $t): array => $t->toDict(), $taskList),
            'config' => $config->toDict(),
        ]);

        return $this->transaction(function (\PDO $db) use ($run, $taskList, $resolvedConfigDigest, $payloadDigest, $edges): DAGRun {
            $stmt = $db->prepare('SELECT payload FROM dag_runs WHERE run_id=?');
            $stmt->execute([$run->runId]);
            $existing = $stmt->fetch();
            if ($existing !== false) {
                $stored = DAGRun::fromDict(json_decode((string) $existing['payload'], true));
                $command = $this->commandLocked($db, $run->runId, "create:{$run->runId}", $payloadDigest);
                if ($command === null) {
                    throw new DAGError('IDEMPOTENCY-CONFLICT', 'run ID already exists with different content');
                }
                return $stored;
            }
            $run->graphRevision = 1;
            $run->configDigest = $resolvedConfigDigest;
            $run->updatedAt = DAGConstants::utcNow();
            $ins = $db->prepare(
                'INSERT INTO dag_runs(run_id,status,graph_revision,scheduler_epoch,payload,updated_at) VALUES(?,?,?,?,?,?)',
            );
            $ins->execute([
                $run->runId,
                $run->status->value,
                $run->graphRevision,
                $run->schedulerEpoch,
                $this->json($run->toDict()),
                $run->updatedAt,
            ]);
            foreach ($taskList as $task) {
                $status = $task->dependsOn === [] ? DAGTaskStatus::READY : DAGTaskStatus::WAITING_DEPENDENCIES;
                $state = new TaskState(taskId: $task->taskId, status: $status);
                $created = DAGConstants::utcNow();
                $insTask = $db->prepare(
                    'INSERT INTO dag_tasks(run_id,task_id,status,priority,spec,state,created_at) VALUES(?,?,?,?,?,?,?)',
                );
                $insTask->execute([
                    $run->runId,
                    $task->taskId,
                    $status->value,
                    $task->priority,
                    $this->json($task->toDict()),
                    $this->json($state->toDict()),
                    $created,
                ]);
            }
            $edgeKeys = array_keys($edges);
            sort($edgeKeys);
            foreach ($edgeKeys as $key) {
                [$taskId, $dependencyId] = $edges[$key];
                $insDep = $db->prepare(
                    'INSERT INTO dag_dependencies(run_id,task_id,dependency_id,decomposition_id) VALUES(?,?,?,?)',
                );
                $insDep->execute([$run->runId, $taskId, $dependencyId, 'initial']);
            }
            $this->appendEventLocked($db, $run->runId, 'dag.run.created', data: [
                'root_task_ids' => $run->rootTaskIds,
                'config_digest' => $run->configDigest,
            ]);
            $this->saveCommandLocked($db, $run->runId, "create:{$run->runId}", $payloadDigest, $run->toDict());
            return $run;
        });
    }

    // ------------------------------------------------------------------
    // Scheduler lease (epoch-fenced)
    // ------------------------------------------------------------------

    public function acquireSchedulerLease(string $runId, string $ownerId, float $leaseSeconds): int
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        // Millisecond precision: DateTimeInterface::ATOM truncates to whole
        // seconds, so the lease would be stored up to a second too early — a
        // sub-second lease would already look expired, and a LIVE lease could
        // be stolen by a second scheduler (T8).
        $expires = Timestamp::format(
            $now->modify('+' . (int) ceil($leaseSeconds * 1000) . ' milliseconds'),
        );
        return $this->transaction(function (\PDO $db) use ($runId, $ownerId, $expires, $now): int {
            $this->requireRunLocked($db, $runId);
            $stmt = $db->prepare('SELECT owner_id,epoch,expires_at FROM dag_scheduler_leases WHERE run_id=?');
            $stmt->execute([$runId]);
            $row = $stmt->fetch();
            if (
                $row !== false
                && (string) $row['owner_id'] !== $ownerId
                && self::parseTime((string) $row['expires_at']) > $now
            ) {
                throw new DAGError('LEASE-REJECTED', 'another scheduler owns an active lease');
            }
            $epoch = $row !== false
                ? ((string) $row['owner_id'] !== $ownerId ? (int) $row['epoch'] + 1 : (int) $row['epoch'])
                : 1;
            $upsert = $db->prepare(
                'INSERT INTO dag_scheduler_leases(run_id,owner_id,epoch,expires_at) VALUES(?,?,?,?) '
                . 'ON CONFLICT(run_id) DO UPDATE SET owner_id=excluded.owner_id,epoch=excluded.epoch,expires_at=excluded.expires_at',
            );
            $upsert->execute([$runId, $ownerId, $epoch, $expires]);
            $this->updateRunLocked($db, $runId, schedulerEpoch: $epoch);
            $this->appendEventLocked($db, $runId, 'dag.scheduler.acquired', data: ['owner_id' => $ownerId, 'epoch' => $epoch]);
            return $epoch;
        });
    }

    public function renewSchedulerLease(string $runId, string $ownerId, int $epoch, float $leaseSeconds): void
    {
        // Millisecond precision, see acquireSchedulerLease() (T8).
        $expires = Timestamp::format(
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify('+' . (int) ceil($leaseSeconds * 1000) . ' milliseconds'),
        );
        $this->transaction(function (\PDO $db) use ($runId, $ownerId, $epoch, $expires): void {
            $stmt = $db->prepare(
                'UPDATE dag_scheduler_leases SET expires_at=? WHERE run_id=? AND owner_id=? AND epoch=?',
            );
            $stmt->execute([$expires, $runId, $ownerId, $epoch]);
            if ($stmt->rowCount() === 0) {
                throw new DAGError('LEASE-REJECTED', 'scheduler lease is stale');
            }
        });
    }

    public function releaseSchedulerLease(string $runId, string $ownerId, int $epoch): void
    {
        $this->transaction(function (\PDO $db) use ($runId, $ownerId, $epoch): void {
            $stmt = $db->prepare(
                'DELETE FROM dag_scheduler_leases WHERE run_id=? AND owner_id=? AND epoch=?',
            );
            $stmt->execute([$runId, $ownerId, $epoch]);
        });
    }

    // ------------------------------------------------------------------
    // Attempt lifecycle
    // ------------------------------------------------------------------

    /**
     * Expire the attempts whose lease lapsed and requeue their tasks.
     *
     * @param int|null $maxAttempts retry budget of a task. The attempt
     *        counter is incremented when a task is claimed, so an expired
     *        attempt has already consumed one unit of the budget: the
     *        requeue MUST consult it, otherwise a task whose worker keeps
     *        outliving its lease is re-queued forever and
     *        `max_attempts_per_task` is silently bypassed (C-4).
     *        `null` keeps the legacy "unlimited requeue" behaviour for
     *        direct store users that do not track a budget.
     */
    public function expireAttempts(string $runId, ?int $maxAttempts = null): int
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $this->transaction(function (\PDO $db) use ($runId, $now, $maxAttempts): int {
            $expired = 0;
            $stmt = $db->prepare(
                'SELECT attempt_id,payload FROM dag_attempts WHERE run_id=? AND status IN (?,?,?) AND lease_expires_at IS NOT NULL',
            );
            $stmt->execute([
                $runId,
                DAGAttemptStatus::CLAIMED->value,
                DAGAttemptStatus::RUNNING->value,
                DAGAttemptStatus::SUBMITTED->value,
            ]);
            foreach ($stmt->fetchAll() as $row) {
                $attempt = TaskAttempt::fromDict(json_decode((string) $row['payload'], true));
                if ($attempt->leaseExpiresAt === null || self::parseTime($attempt->leaseExpiresAt) > $now) {
                    continue;
                }
                $attempt->status = DAGAttemptStatus::EXPIRED;
                $attempt->updatedAt = DAGConstants::utcNow();
                $this->saveAttemptLocked($db, $attempt);
                $task = $this->requireTaskLocked($db, $runId, $attempt->taskId);
                $budgetExhausted = false;
                if ($task->state->activeAttemptId === $attempt->attemptId) {
                    // An attempt that already staged its artifact was
                    // awaiting verification: its staged row must not be
                    // orphaned by the requeue (M-45).
                    $this->abandonStagedArtifactsLocked($db, $runId, $attempt->attemptId);
                    $task->state->activeAttemptId = null;
                    $budgetExhausted = $maxAttempts !== null && $task->state->attemptCount >= $maxAttempts;
                    $task->state->status = $budgetExhausted ? DAGTaskStatus::FAILED : DAGTaskStatus::READY;
                    $task->state->stateVersion++;
                    $task->state->lastDiagnostic = $budgetExhausted
                        ? "attempt lease expired; retry budget of {$maxAttempts} attempts is exhausted"
                        : 'previous attempt lease expired';
                    $task->state->updatedAt = DAGConstants::utcNow();
                    $this->saveTaskLocked($db, $runId, $task);
                }
                $this->appendEventLocked(
                    $db,
                    $runId,
                    'dag.attempt.expired',
                    taskId: $attempt->taskId,
                    attemptId: $attempt->attemptId,
                    data: [
                        'lease_epoch' => $attempt->leaseEpoch,
                        'retry_budget_exhausted' => $budgetExhausted,
                    ],
                );
                $expired++;
            }
            return $expired;
        });
    }

    /**
     * Mark the staged artifacts of an abandoned attempt as 'abandoned'.
     *
     * The blob itself stays on disk (it is reclaimable through
     * LocalArtifactStore::removeOrphans()), but the manifest row must not
     * keep claiming to be a publishable candidate of a dead attempt.
     */
    private function abandonStagedArtifactsLocked(\PDO $db, string $runId, string $attemptId): void
    {
        $stmt = $db->prepare(
            "SELECT payload FROM dag_artifacts WHERE run_id=? AND attempt_id=? AND state='staged'",
        );
        $stmt->execute([$runId, $attemptId]);
        foreach ($stmt->fetchAll() as $row) {
            $manifest = ArtifactManifest::fromDict(json_decode((string) $row['payload'], true));
            $manifest->state = 'abandoned';
            $upd = $db->prepare('UPDATE dag_artifacts SET state=?,payload=? WHERE artifact_id=?');
            $upd->execute([$manifest->state, $this->json($manifest->toDict()), $manifest->artifactId]);
            $this->appendEventLocked(
                $db,
                $runId,
                'dag.artifact.abandoned',
                taskId: $manifest->taskId,
                attemptId: $attemptId,
                data: ['artifact_id' => $manifest->artifactId],
            );
        }
    }

    /**
     * @return array{0: DAGTask, 1: TaskAttempt}|null
     */
    public function claimReadyTask(string $runId, string $workerId, float $leaseSeconds): ?array
    {
        return $this->transaction(function (\PDO $db) use ($runId, $workerId, $leaseSeconds): ?array {
            $run = $this->requireRunLocked($db, $runId);
            if ($run->status !== DAGRunStatus::RUNNING) {
                return null;
            }
            $stmt = $db->prepare(
                'SELECT spec,state,created_at FROM dag_tasks WHERE run_id=? AND status=? '
                . 'ORDER BY priority DESC,created_at,task_id',
            );
            $stmt->execute([$runId, DAGTaskStatus::READY->value]);
            foreach ($stmt->fetchAll() as $row) {
                $task = $this->taskFromRow($row);
                if (!$this->dependenciesVerifiedLocked($db, $runId, $task->spec->taskId)) {
                    continue;
                }
                $task->state->attemptCount++;
                $phase = $task->state->decompositionCount > 0 ? 'integrate' : 'solve';
                $dependencyManifest = $this->dependencyManifestLocked($db, $runId, $task->spec->taskId);
                // Epoch fencing (M-44): the epoch is bumped on EVERY claim,
                // so it is a real fencing token for the task and not a
                // constant. A worker that comes back from a lapsed lease
                // carries the old epoch and is rejected by
                // assertAttemptCurrentLocked().
                $leaseEpoch = $this->currentTaskEpochLocked($db, $runId, $task->spec->taskId) + 1;
                $attempt = new TaskAttempt(
                    runId: $runId,
                    taskId: $task->spec->taskId,
                    attemptNo: $task->state->attemptCount,
                    workerId: $workerId,
                    phase: $phase,
                    inputSnapshotHash: SecurityUtils::canonicalDigest([
                        'contract_hash' => $task->spec->contractHash,
                        'dependencies' => $dependencyManifest,
                        'state_version' => $task->state->stateVersion,
                    ]),
                    // Millisecond precision: DateTimeInterface::ATOM truncates
                    // to whole seconds, so a sub-second attempt lease could
                    // be expired the moment it was written (T8).
                    leaseExpiresAt: Timestamp::inSeconds($leaseSeconds),
                    leaseEpoch: $leaseEpoch,
                );
                $task->state->status = DAGTaskStatus::RUNNING;
                $task->state->activeAttemptId = $attempt->attemptId;
                $task->state->stateVersion++;
                $task->state->updatedAt = DAGConstants::utcNow();
                $this->saveTaskLocked($db, $runId, $task);
                $ins = $db->prepare(
                    'INSERT INTO dag_attempts(attempt_id,run_id,task_id,status,lease_epoch,lease_expires_at,payload) '
                    . 'VALUES(?,?,?,?,?,?,?)',
                );
                $ins->execute([
                    $attempt->attemptId,
                    $runId,
                    $task->spec->taskId,
                    $attempt->status->value,
                    $attempt->leaseEpoch,
                    $attempt->leaseExpiresAt,
                    $this->json($attempt->toDict()),
                ]);
                $this->appendEventLocked(
                    $db,
                    $runId,
                    'dag.task.claimed',
                    taskId: $task->spec->taskId,
                    attemptId: $attempt->attemptId,
                    data: ['worker_id' => $workerId, 'phase' => $phase, 'lease_epoch' => $attempt->leaseEpoch],
                );
                return [$task, $attempt];
            }
            return null;
        });
    }

    public function markAttemptRunning(string $attemptId, int $leaseEpoch): TaskAttempt
    {
        return $this->transaction(function (\PDO $db) use ($attemptId, $leaseEpoch): TaskAttempt {
            $attempt = $this->requireAttemptLocked($db, $attemptId);
            $this->assertAttemptCurrentLocked($db, $attempt, $leaseEpoch);
            $attempt->status = DAGAttemptStatus::RUNNING;
            $attempt->updatedAt = DAGConstants::utcNow();
            $this->saveAttemptLocked($db, $attempt);
            return $attempt;
        });
    }

    /**
     * Extend the lease of a running attempt.
     *
     * @param bool $allowLapsedLease renew a lease that already lapsed. The
     *        attempt still has to own the task (epoch + activeAttemptId +
     *        non-terminal status are all checked), so the renewal is safe:
     *        it only rescues a worker that legitimately outlived its lease
     *        instead of letting the result be thrown away (C-4a).
     */
    public function renewAttempt(string $attemptId, int $leaseEpoch, float $leaseSeconds, bool $allowLapsedLease = false): TaskAttempt
    {
        return $this->transaction(function (\PDO $db) use ($attemptId, $leaseEpoch, $leaseSeconds, $allowLapsedLease): TaskAttempt {
            $attempt = $this->requireAttemptLocked($db, $attemptId);
            $this->assertAttemptCurrentLocked($db, $attempt, $leaseEpoch, $allowLapsedLease);
            // Millisecond precision (T8): ATOM would truncate a sub-second
            // lease to whole seconds.
            $attempt->leaseExpiresAt = Timestamp::inSeconds($leaseSeconds);
            $attempt->updatedAt = DAGConstants::utcNow();
            $this->saveAttemptLocked($db, $attempt);
            return $attempt;
        });
    }

    // ------------------------------------------------------------------
    // Artifact staging / publishing / rejection
    // ------------------------------------------------------------------

    public function saveStagedArtifact(ArtifactManifest $manifest, int $leaseEpoch): ArtifactManifest
    {
        return $this->transaction(function (\PDO $db) use ($manifest, $leaseEpoch): ArtifactManifest {
            $attempt = $this->requireAttemptLocked($db, $manifest->attemptId);
            $this->assertAttemptCurrentLocked($db, $attempt, $leaseEpoch);
            $stmt = $db->prepare('SELECT payload FROM dag_artifacts WHERE artifact_id=?');
            $stmt->execute([$manifest->artifactId]);
            $existing = $stmt->fetch();
            if ($existing !== false) {
                $stored = ArtifactManifest::fromDict(json_decode((string) $existing['payload'], true));
                if ($stored->contentHash !== $manifest->contentHash) {
                    throw new DAGError('IDEMPOTENCY-CONFLICT', 'artifact ID was reused with different content');
                }
                return $stored;
            }
            $ins = $db->prepare(
                'INSERT INTO dag_artifacts(artifact_id,run_id,task_id,attempt_id,state,content_hash,payload) VALUES(?,?,?,?,?,?,?)',
            );
            $ins->execute([
                $manifest->artifactId,
                $manifest->runId,
                $manifest->taskId,
                $manifest->attemptId,
                $manifest->state,
                $manifest->contentHash,
                $this->json($manifest->toDict()),
            ]);
            $attempt->status = DAGAttemptStatus::SUBMITTED;
            $attempt->updatedAt = DAGConstants::utcNow();
            $this->saveAttemptLocked($db, $attempt);
            $task = $this->requireTaskLocked($db, $manifest->runId, $manifest->taskId);
            $task->state->status = DAGTaskStatus::VERIFYING;
            $task->state->stateVersion++;
            $task->state->updatedAt = DAGConstants::utcNow();
            $this->saveTaskLocked($db, $manifest->runId, $task);
            $this->appendEventLocked(
                $db,
                $manifest->runId,
                'dag.artifact.staged',
                taskId: $manifest->taskId,
                attemptId: $manifest->attemptId,
                data: ['artifact_id' => $manifest->artifactId, 'content_hash' => $manifest->contentHash],
            );
            return $manifest;
        });
    }

    public function publishArtifact(ArtifactManifest $manifest, VerificationReport $report, int $leaseEpoch): ArtifactManifest
    {
        if ($report->verdict !== VerificationVerdict::PASS) {
            throw new DAGError('VERIFICATION-FAILED', 'only passing reports can publish artifacts');
        }
        // Bind the report to the published bytes (V5-09): the input
        // snapshot hash covers the contract + dependencies + state version,
        // but NOT the candidate content, so a report that does not name
        // the artifact hash could approve arbitrary bytes. Fail closed:
        // a report without the manifest's content hash cannot publish.
        if (!in_array($manifest->contentHash, $report->artifactHashes, true)) {
            throw new DAGError(
                'VERIFICATION-FAILED',
                'the verification report is not bound to the published artifact hash',
            );
        }
        return $this->transaction(function (\PDO $db) use ($manifest, $report, $leaseEpoch): ArtifactManifest {
            $attempt = $this->requireAttemptLocked($db, $manifest->attemptId);
            $this->assertAttemptCurrentLocked($db, $attempt, $leaseEpoch);
            $task = $this->requireTaskLocked($db, $manifest->runId, $manifest->taskId);
            if ($report->inputSnapshotHash !== $attempt->inputSnapshotHash) {
                throw new DAGError('VERSION-CONFLICT', 'verification report input snapshot is stale');
            }
            $stmt = $db->prepare('SELECT payload,state FROM dag_artifacts WHERE artifact_id=?');
            $stmt->execute([$manifest->artifactId]);
            $existing = $stmt->fetch();
            if ($existing === false) {
                throw new \RuntimeException($manifest->artifactId);
            }
            $stored = ArtifactManifest::fromDict(json_decode((string) $existing['payload'], true));
            if ($stored->state === 'published') {
                return $stored;
            }
            $manifest->state = 'published';
            $manifest->verificationReportId = $report->reportId;
            $insReport = $db->prepare(
                'INSERT OR IGNORE INTO dag_reports(report_id,run_id,task_id,attempt_id,payload) VALUES(?,?,?,?,?)',
            );
            $insReport->execute([
                $report->reportId,
                $manifest->runId,
                $manifest->taskId,
                $manifest->attemptId,
                $this->json($report->toDict()),
            ]);
            $upd = $db->prepare('UPDATE dag_artifacts SET state=?,payload=? WHERE artifact_id=?');
            $upd->execute([$manifest->state, $this->json($manifest->toDict()), $manifest->artifactId]);
            $attempt->status = DAGAttemptStatus::SUCCEEDED;
            $attempt->updatedAt = DAGConstants::utcNow();
            $this->saveAttemptLocked($db, $attempt);
            $task->state->status = DAGTaskStatus::VERIFIED;
            $task->state->activeAttemptId = null;
            $task->state->blocker = null;
            $task->state->verifiedArtifactIds = array_values(array_unique(array_merge(
                $task->state->verifiedArtifactIds,
                [$manifest->artifactId],
            )));
            $task->state->stateVersion++;
            $task->state->updatedAt = DAGConstants::utcNow();
            $this->saveTaskLocked($db, $manifest->runId, $task);
            $this->appendEventLocked(
                $db,
                $manifest->runId,
                'dag.artifact.published',
                taskId: $manifest->taskId,
                attemptId: $manifest->attemptId,
                data: ['artifact_id' => $manifest->artifactId, 'report_id' => $report->reportId],
            );
            $this->appendEventLocked(
                $db,
                $manifest->runId,
                'dag.task.verified',
                taskId: $manifest->taskId,
                attemptId: $manifest->attemptId,
                data: ['artifact_ids' => $task->state->verifiedArtifactIds],
            );
            $this->refreshDependentsLocked($db, $manifest->runId, $manifest->taskId);
            $this->refreshRunStatusLocked($db, $manifest->runId);
            return $manifest;
        });
    }

    public function rejectCandidate(string $attemptId, int $leaseEpoch, VerificationReport $report, int $maxAttempts): DAGTask
    {
        return $this->transaction(function (\PDO $db) use ($attemptId, $leaseEpoch, $report, $maxAttempts): DAGTask {
            $attempt = $this->requireAttemptLocked($db, $attemptId);
            $this->assertAttemptCurrentLocked($db, $attempt, $leaseEpoch);
            $task = $this->requireTaskLocked($db, $attempt->runId, $attempt->taskId);
            $insReport = $db->prepare(
                'INSERT OR IGNORE INTO dag_reports(report_id,run_id,task_id,attempt_id,payload) VALUES(?,?,?,?,?)',
            );
            $insReport->execute([
                $report->reportId,
                $attempt->runId,
                $attempt->taskId,
                $attemptId,
                $this->json($report->toDict()),
            ]);
            $attempt->status = DAGAttemptStatus::FAILED;
            $attempt->error = implode('; ', $report->diagnostics) ?: $report->verdict->value;
            $attempt->updatedAt = DAGConstants::utcNow();
            $this->saveAttemptLocked($db, $attempt);
            $task->state->verificationFailureCount++;
            $task->state->activeAttemptId = null;
            $task->state->lastDiagnostic = $attempt->error;
            $task->state->status = match (true) {
                $task->state->attemptCount < $maxAttempts && in_array($report->verdict->value, ['fail', 'error'], true) => DAGTaskStatus::READY,
                $report->verdict->value === 'inconclusive' => DAGTaskStatus::BLOCKED,
                default => DAGTaskStatus::FAILED,
            };
            $task->state->blocker = $task->state->status === DAGTaskStatus::BLOCKED ? $attempt->error : null;
            $task->state->stateVersion++;
            $task->state->updatedAt = DAGConstants::utcNow();
            $this->saveTaskLocked($db, $attempt->runId, $task);
            $this->appendEventLocked(
                $db,
                $attempt->runId,
                'dag.verification.failed',
                taskId: $attempt->taskId,
                attemptId: $attemptId,
                data: ['report_id' => $report->reportId, 'verdict' => $report->verdict->value, 'diagnostics' => $report->diagnostics],
            );
            $this->refreshRunStatusLocked($db, $attempt->runId);
            return $task;
        });
    }

    public function blockAttempt(string $attemptId, int $leaseEpoch, string $reason): DAGTask
    {
        return $this->transaction(function (\PDO $db) use ($attemptId, $leaseEpoch, $reason): DAGTask {
            $attempt = $this->requireAttemptLocked($db, $attemptId);
            $this->assertAttemptCurrentLocked($db, $attempt, $leaseEpoch);
            $attempt->status = DAGAttemptStatus::FAILED;
            $attempt->error = $reason;
            $attempt->updatedAt = DAGConstants::utcNow();
            $this->saveAttemptLocked($db, $attempt);
            $task = $this->requireTaskLocked($db, $attempt->runId, $attempt->taskId);
            $task->state->status = DAGTaskStatus::BLOCKED;
            $task->state->blocker = $reason;
            $task->state->lastDiagnostic = $reason;
            $task->state->activeAttemptId = null;
            $task->state->stateVersion++;
            $task->state->updatedAt = DAGConstants::utcNow();
            $this->saveTaskLocked($db, $attempt->runId, $task);
            $this->appendEventLocked(
                $db,
                $attempt->runId,
                'dag.task.blocked',
                taskId: $attempt->taskId,
                attemptId: $attemptId,
                data: ['reason' => $reason],
            );
            $this->refreshRunStatusLocked($db, $attempt->runId);
            return $task;
        });
    }

    public function failAttempt(string $attemptId, int $leaseEpoch, string $reason, int $maxAttempts): DAGTask
    {
        return $this->transaction(function (\PDO $db) use ($attemptId, $leaseEpoch, $reason, $maxAttempts): DAGTask {
            $attempt = $this->requireAttemptLocked($db, $attemptId);
            $this->assertAttemptCurrentLocked($db, $attempt, $leaseEpoch);
            $attempt->status = DAGAttemptStatus::FAILED;
            $attempt->error = $reason;
            $attempt->updatedAt = DAGConstants::utcNow();
            $this->saveAttemptLocked($db, $attempt);
            $task = $this->requireTaskLocked($db, $attempt->runId, $attempt->taskId);
            $task->state->activeAttemptId = null;
            $task->state->lastDiagnostic = $reason;
            $task->state->status = $task->state->attemptCount < $maxAttempts
                ? DAGTaskStatus::READY
                : DAGTaskStatus::FAILED;
            $task->state->stateVersion++;
            $task->state->updatedAt = DAGConstants::utcNow();
            $this->saveTaskLocked($db, $attempt->runId, $task);
            $this->appendEventLocked(
                $db,
                $attempt->runId,
                'dag.attempt.failed',
                taskId: $attempt->taskId,
                attemptId: $attemptId,
                data: ['reason' => $reason, 'retrying' => $task->state->status === DAGTaskStatus::READY],
            );
            $this->refreshRunStatusLocked($db, $attempt->runId);
            return $task;
        });
    }

    /**
     * Record a failure that could not be applied through failAttempt().
     *
     * failAttempt() asserts that the attempt still owns the task, so it
     * throws STALE-ATTEMPT exactly in the case the caller has to report
     * (lease lapsed mid-flight, attempt superseded). Swallowing that error
     * made the failure invisible and left the retry budget untouched
     * (C-4c): this method stores the reason on the attempt and, when the
     * attempt still owns the task, applies the same retry budget rule as
     * failAttempt() so the task cannot stay RUNNING forever.
     *
     * @param int|null $maxAttempts retry budget of the task; null keeps the
     *        task re-queued unconditionally.
     */
    public function recordStaleAttemptFailure(string $attemptId, string $reason, ?int $maxAttempts = null): void
    {
        $this->transaction(function (\PDO $db) use ($attemptId, $reason, $maxAttempts): void {
            $stmt = $db->prepare('SELECT payload FROM dag_attempts WHERE attempt_id=?');
            $stmt->execute([$attemptId]);
            $row = $stmt->fetch();
            if ($row === false) {
                return;
            }
            $attempt = TaskAttempt::fromDict(json_decode((string) $row['payload'], true));
            if (!in_array($attempt->status, [DAGAttemptStatus::EXPIRED, DAGAttemptStatus::CANCELLED], true)) {
                $attempt->status = DAGAttemptStatus::FAILED;
                $attempt->error = $reason;
                $attempt->updatedAt = DAGConstants::utcNow();
                $this->saveAttemptLocked($db, $attempt);
            }
            $task = $this->requireTaskLocked($db, $attempt->runId, $attempt->taskId);
            if ($task->state->activeAttemptId === $attemptId) {
                // The attempt still owns the task: apply the retry budget
                // like failAttempt() does, otherwise the task would stay
                // RUNNING and stall the whole run.
                $task->state->activeAttemptId = null;
                $task->state->lastDiagnostic = $reason;
                $task->state->status = $maxAttempts !== null && $task->state->attemptCount >= $maxAttempts
                    ? DAGTaskStatus::FAILED
                    : DAGTaskStatus::READY;
                $task->state->stateVersion++;
                $task->state->updatedAt = DAGConstants::utcNow();
                $this->saveTaskLocked($db, $attempt->runId, $task);
                $this->refreshRunStatusLocked($db, $attempt->runId);
            }
            $this->appendEventLocked(
                $db,
                $attempt->runId,
                'dag.attempt.stale',
                taskId: $attempt->taskId,
                attemptId: $attemptId,
                data: ['reason' => $reason],
            );
        });
    }

    /**
     * Mark an attempt as cancelled (run cancellation).
     *
     * The task status is left untouched: the run level cancellation has
     * already moved the task to CANCELLED, and overwriting it with
     * BLOCKED would hide the cancellation from the caller.
     */
    public function cancelAttempt(string $attemptId, int $leaseEpoch, string $reason): void
    {
        $this->transaction(function (\PDO $db) use ($attemptId, $leaseEpoch, $reason): void {
            $attempt = $this->requireAttemptLocked($db, $attemptId);
            if ($attempt->leaseEpoch !== $leaseEpoch) {
                return;
            }
            if (in_array($attempt->status, [DAGAttemptStatus::SUCCEEDED, DAGAttemptStatus::FAILED, DAGAttemptStatus::EXPIRED], true)) {
                return;
            }
            $attempt->status = DAGAttemptStatus::CANCELLED;
            $attempt->error = $reason;
            $attempt->updatedAt = DAGConstants::utcNow();
            $this->saveAttemptLocked($db, $attempt);
            $task = $this->requireTaskLocked($db, $attempt->runId, $attempt->taskId);
            if ($task->state->activeAttemptId === $attemptId) {
                $task->state->activeAttemptId = null;
                $task->state->lastDiagnostic = $reason;
                $task->state->updatedAt = DAGConstants::utcNow();
                $this->saveTaskLocked($db, $attempt->runId, $task);
            }
            $this->appendEventLocked(
                $db,
                $attempt->runId,
                'dag.attempt.cancelled',
                taskId: $attempt->taskId,
                attemptId: $attemptId,
                data: ['reason' => $reason],
            );
        });
    }

    public function blockTask(string $runId, string $taskId, string $reason): DAGTask
    {
        return $this->transaction(function (\PDO $db) use ($runId, $taskId, $reason): DAGTask {
            $task = $this->requireTaskLocked($db, $runId, $taskId);
            if (in_array($task->state->status, [
                DAGTaskStatus::VERIFIED, DAGTaskStatus::FAILED,
                DAGTaskStatus::CANCELLED, DAGTaskStatus::SUPERSEDED,
            ], true)) {
                throw new DAGError('VERSION-CONFLICT', 'terminal task cannot be blocked');
            }
            $task->state->status = DAGTaskStatus::BLOCKED;
            $task->state->blocker = $reason;
            $task->state->lastDiagnostic = $reason;
            $task->state->activeAttemptId = null;
            $task->state->stateVersion++;
            $task->state->updatedAt = DAGConstants::utcNow();
            $this->saveTaskLocked($db, $runId, $task);
            $this->appendEventLocked($db, $runId, 'dag.task.blocked', taskId: $taskId, data: ['reason' => $reason]);
            $this->refreshRunStatusLocked($db, $runId);
            return $task;
        });
    }

    public function resolveBlocker(string $runId, string $taskId, ?string $reason = null): DAGTask
    {
        return $this->transaction(function (\PDO $db) use ($runId, $taskId, $reason): DAGTask {
            $task = $this->requireTaskLocked($db, $runId, $taskId);
            if (!in_array($task->state->status, [DAGTaskStatus::BLOCKED, DAGTaskStatus::WAITING_APPROVAL], true)) {
                throw new DAGError('VERSION-CONFLICT', 'task is not blocked');
            }
            $task->state->status = DAGTaskStatus::READY;
            $task->state->blocker = null;
            $task->state->lastDiagnostic = $reason ?? $task->state->lastDiagnostic;
            $task->state->stateVersion++;
            $task->state->updatedAt = DAGConstants::utcNow();
            $this->saveTaskLocked($db, $runId, $task);
            $this->updateRunLocked($db, $runId, status: DAGRunStatus::RUNNING, blocker: null);
            $this->appendEventLocked($db, $runId, 'dag.task.unblocked', taskId: $taskId, data: ['reason' => $reason]);
            return $task;
        });
    }

    /**
     * Accept a verified decomposition (store.py accept_decomposition).
     *
     * @return list<DAGTask>
     */
    public function acceptDecomposition(
        string $runId,
        string $attemptId,
        int $leaseEpoch,
        DecompositionProposal $proposal,
        VerificationReport $report,
        DAGConfig $config,
    ): array {
        if ($report->verdict !== VerificationVerdict::PASS) {
            throw new DAGError('VERIFICATION-FAILED', 'decomposition requires a passing report');
        }
        return $this->transaction(function (\PDO $db) use ($runId, $attemptId, $leaseEpoch, $proposal, $report, $config): array {
            $attempt = $this->requireAttemptLocked($db, $attemptId);
            $this->assertAttemptCurrentLocked($db, $attempt, $leaseEpoch);
            if ($attempt->taskId !== $proposal->parentTaskId || $attempt->runId !== $runId) {
                throw new DAGError('STALE-ATTEMPT', 'proposal is not bound to the active parent attempt');
            }
            $run = $this->requireRunLocked($db, $runId);
            // M32: null expectedGraphRevision = "no explicit expectation"
            // (the worker cannot know the live revision when constructing
            // the proposal); only an explicit value performs the optimistic
            // revision check.
            if ($proposal->expectedGraphRevision !== null && $proposal->expectedGraphRevision !== $run->graphRevision) {
                throw new DAGError('VERSION-CONFLICT', 'decomposition graph revision is stale');
            }
            $parent = $this->requireTaskLocked($db, $runId, $proposal->parentTaskId);
            if ($parent->state->decompositionCount >= $config->maxDecompositionsPerTask) {
                throw new DAGError('BUDGET-EXHAUSTED', 'task decomposition limit reached');
            }
            // Depth limit (V5-40): maxDecompositionDepth is a safety limit
            // on how deep the decomposition tree may nest. It was declared
            // in DAGConfig but never consulted, so a decomposition chain
            // could nest without bound.
            $childDepth = $parent->state->decompositionDepth + 1;
            if ($childDepth > $config->maxDecompositionDepth) {
                throw new DAGError(
                    'BUDGET-EXHAUSTED',
                    "decomposition depth limit reached ({$config->maxDecompositionDepth})",
                );
            }
            $stmt = $db->prepare('SELECT proposal_digest FROM dag_decompositions WHERE proposal_id=?');
            $stmt->execute([$proposal->proposalId]);
            $existing = $stmt->fetch();
            if ($existing !== false) {
                if ((string) $existing['proposal_digest'] !== $proposal->digest()) {
                    throw new DAGError('IDEMPOTENCY-CONFLICT', 'proposal ID was reused with different content');
                }
                $tasks = [];
                foreach ($proposal->newTasks as $task) {
                    $tasks[] = $this->requireTaskLocked($db, $runId, $task->taskId);
                }
                return $tasks;
            }
            $existingIds = [];
            $allStmt = $db->prepare('SELECT task_id FROM dag_tasks WHERE run_id=?');
            $allStmt->execute([$runId]);
            foreach ($allStmt->fetchAll() as $row) {
                $existingIds[(string) $row['task_id']] = true;
            }
            $newIds = [];
            foreach ($proposal->newTasks as $task) {
                $newIds[$task->taskId] = true;
            }
            if (count($newIds) !== count($proposal->newTasks) || array_intersect_key($newIds, $existingIds) !== []) {
                throw new DAGError('IDEMPOTENCY-CONFLICT', 'decomposition task IDs must be new and unique');
            }
            foreach ($proposal->reuseTaskIds as $reuseId) {
                if (!isset($existingIds[$reuseId])) {
                    throw new DAGError('UNKNOWN-DEPENDENCY', 'decomposition references an unknown reusable task');
                }
            }
            $allIds = $existingIds + $newIds;
            $edges = [];
            $edgeStmt = $db->prepare('SELECT task_id,dependency_id FROM dag_dependencies WHERE run_id=?');
            $edgeStmt->execute([$runId]);
            foreach ($edgeStmt->fetchAll() as $row) {
                $edges[(string) $row['task_id'] . '|' . (string) $row['dependency_id']] = [(string) $row['task_id'], (string) $row['dependency_id']];
            }
            foreach ($proposal->newTasks as $task) {
                foreach ($task->dependsOn as $dep) {
                    $edges[$task->taskId . '|' . $dep] = [$task->taskId, $dep];
                }
            }
            foreach (array_keys($newIds) as $newId) {
                $edges[$proposal->parentTaskId . '|' . $newId] = [$proposal->parentTaskId, $newId];
            }
            foreach ($proposal->reuseTaskIds as $reuseId) {
                $edges[$proposal->parentTaskId . '|' . $reuseId] = [$proposal->parentTaskId, $reuseId];
            }
            if (count($allIds) > $config->maxTasks || count($edges) > $config->maxEdges) {
                throw new DAGError('BUDGET-EXHAUSTED', 'decomposition exceeds graph limits');
            }
            self::validateGraph(array_keys($allIds), array_values($edges));
            $createdTasks = [];
            foreach ($proposal->newTasks as $task) {
                $status = $task->dependsOn === [] ? DAGTaskStatus::READY : DAGTaskStatus::WAITING_DEPENDENCIES;
                $state = new TaskState(taskId: $task->taskId, status: $status, decompositionDepth: $childDepth);
                $created = DAGConstants::utcNow();
                $insTask = $db->prepare(
                    'INSERT INTO dag_tasks(run_id,task_id,status,priority,spec,state,created_at) VALUES(?,?,?,?,?,?,?)',
                );
                $insTask->execute([$runId, $task->taskId, $status->value, $task->priority, $this->json($task->toDict()), $this->json($state->toDict()), $created]);
                $createdTasks[] = new DAGTask($task, $state, $created);
            }
            $del = $db->prepare('DELETE FROM dag_dependencies WHERE run_id=? AND task_id=?');
            $del->execute([$runId, $proposal->parentTaskId]);
            $edgeKeys = array_keys($edges);
            sort($edgeKeys);
            foreach ($edgeKeys as $key) {
                [$taskId, $dependencyId] = $edges[$key];
                if (!isset($newIds[$taskId]) && $taskId !== $proposal->parentTaskId) {
                    continue;
                }
                $insDep = $db->prepare(
                    'INSERT OR IGNORE INTO dag_dependencies(run_id,task_id,dependency_id,decomposition_id) VALUES(?,?,?,?)',
                );
                $insDep->execute([$runId, $taskId, $dependencyId, $proposal->proposalId]);
            }
            $insDecomp = $db->prepare(
                'INSERT INTO dag_decompositions(proposal_id,run_id,parent_task_id,proposal_digest,payload) VALUES(?,?,?,?,?)',
            );
            $insDecomp->execute([$proposal->proposalId, $runId, $proposal->parentTaskId, $proposal->digest(), $this->json($proposal->toDict())]);
            $insReport = $db->prepare(
                'INSERT OR IGNORE INTO dag_reports(report_id,run_id,task_id,attempt_id,payload) VALUES(?,?,?,?,?)',
            );
            $insReport->execute([$report->reportId, $runId, $parent->spec->taskId, $attemptId, $this->json($report->toDict())]);
            $attempt->status = DAGAttemptStatus::SUCCEEDED;
            $attempt->updatedAt = DAGConstants::utcNow();
            $this->saveAttemptLocked($db, $attempt);
            $parent->state->status = DAGTaskStatus::WAITING_DEPENDENCIES;
            $parent->state->activeAttemptId = null;
            $parent->state->decompositionCount++;
            $parent->state->dependencyRevision++;
            $parent->state->stateVersion++;
            $parent->state->updatedAt = DAGConstants::utcNow();
            $this->saveTaskLocked($db, $runId, $parent);
            $run->graphRevision++;
            $run->updatedAt = DAGConstants::utcNow();
            $this->saveRunLocked($db, $run);
            $this->appendEventLocked(
                $db,
                $runId,
                'dag.decomposition.accepted',
                taskId: $parent->spec->taskId,
                attemptId: $attemptId,
                data: [
                    'proposal_id' => $proposal->proposalId,
                    'new_task_ids' => array_keys($newIds),
                    'reuse_task_ids' => $proposal->reuseTaskIds,
                    'graph_revision' => $run->graphRevision,
                ],
            );
            return $createdTasks;
        });
    }

    /**
     * Replace an unverified task in place (store.py supersede_task).
     */
    public function supersedeTask(
        string $runId,
        string $taskId,
        TaskSpec $replacement,
        int $expectedGraphRevision,
        DAGConfig $config,
    ): DAGTask {
        return $this->transaction(function (\PDO $db) use ($runId, $taskId, $replacement, $expectedGraphRevision, $config): DAGTask {
            $run = $this->requireRunLocked($db, $runId);
            if ($run->graphRevision !== $expectedGraphRevision) {
                throw new DAGError('VERSION-CONFLICT', 'replacement graph revision is stale');
            }
            $original = $this->requireTaskLocked($db, $runId, $taskId);
            if ($original->state->status === DAGTaskStatus::VERIFIED) {
                throw new DAGError('VERSION-CONFLICT', 'a verified task cannot be superseded in place');
            }
            if ($replacement->taskId === $taskId) {
                throw new \InvalidArgumentException('replacement task must have a new task_id');
            }
            if ($replacement->supersedesTaskId !== null && $replacement->supersedesTaskId !== $taskId) {
                throw new \InvalidArgumentException('replacement supersedes_task_id does not match the original task');
            }
            if (in_array($taskId, $replacement->dependsOn, true)) {
                throw new \InvalidArgumentException('replacement task cannot depend on the task it supersedes');
            }
            $checkStmt = $db->prepare('SELECT 1 FROM dag_tasks WHERE run_id=? AND task_id=?');
            $checkStmt->execute([$runId, $replacement->taskId]);
            if ($checkStmt->fetch() !== false) {
                throw new DAGError('IDEMPOTENCY-CONFLICT', 'replacement task_id already exists');
            }
            $dependents = [];
            $depStmt = $db->prepare(
                'SELECT t.spec,t.state,t.created_at FROM dag_dependencies d '
                . 'JOIN dag_tasks t ON t.run_id=d.run_id AND t.task_id=d.task_id '
                . 'WHERE d.run_id=? AND d.dependency_id=?',
            );
            $depStmt->execute([$runId, $taskId]);
            foreach ($depStmt->fetchAll() as $row) {
                $dependents[] = $this->taskFromRow($row);
            }
            foreach ($dependents as $dependent) {
                if ($dependent->state->status === DAGTaskStatus::VERIFIED) {
                    throw new DAGError('VERSION-CONFLICT', 'verified downstream tasks cannot be rewired in place');
                }
            }
            $taskIds = [];
            $allStmt = $db->prepare('SELECT task_id FROM dag_tasks WHERE run_id=?');
            $allStmt->execute([$runId]);
            foreach ($allStmt->fetchAll() as $row) {
                $taskIds[(string) $row['task_id']] = true;
            }
            $taskIds[$replacement->taskId] = true;
            $edges = [];
            $edgeStmt = $db->prepare('SELECT task_id,dependency_id FROM dag_dependencies WHERE run_id=?');
            $edgeStmt->execute([$runId]);
            foreach ($edgeStmt->fetchAll() as $row) {
                $t = (string) $row['task_id'];
                $d = (string) $row['dependency_id'];
                $edges[$t . '|' . $d] = [$t, $d];
            }
            $rewired = [];
            foreach ($edges as $key => [$dependent, $dependency]) {
                $newDep = $dependency === $taskId ? $replacement->taskId : $dependency;
                $rewired[$dependent . '|' . $newDep] = [$dependent, $newDep];
            }
            $edges = $rewired;
            foreach ($replacement->dependsOn as $dep) {
                $edges[$replacement->taskId . '|' . $dep] = [$replacement->taskId, $dep];
            }
            if (count($taskIds) > $config->maxTasks || count($edges) > $config->maxEdges) {
                throw new DAGError('BUDGET-EXHAUSTED', 'replacement exceeds graph limits');
            }
            self::validateGraph(array_keys($taskIds), array_values($edges));
            if ($original->state->activeAttemptId !== null) {
                $attempt = $this->requireAttemptLocked($db, $original->state->activeAttemptId);
                $attempt->status = DAGAttemptStatus::CANCELLED;
                $attempt->error = 'task was superseded';
                $attempt->updatedAt = DAGConstants::utcNow();
                $this->saveAttemptLocked($db, $attempt);
            }
            $original->state->status = DAGTaskStatus::SUPERSEDED;
            $original->state->activeAttemptId = null;
            $original->state->blocker = "superseded by {$replacement->taskId}";
            $original->state->stateVersion++;
            $original->state->updatedAt = DAGConstants::utcNow();
            $this->saveTaskLocked($db, $runId, $original);
            $state = new TaskState(
                taskId: $replacement->taskId,
                status: $replacement->dependsOn === [] ? DAGTaskStatus::READY : DAGTaskStatus::WAITING_DEPENDENCIES,
                decompositionDepth: $original->state->decompositionDepth,
            );
            $created = DAGConstants::utcNow();
            $insTask = $db->prepare(
                'INSERT INTO dag_tasks(run_id,task_id,status,priority,spec,state,created_at) VALUES(?,?,?,?,?,?,?)',
            );
            $insTask->execute([$runId, $replacement->taskId, $state->status->value, $replacement->priority, $this->json($replacement->toDict()), $this->json($state->toDict()), $created]);
            $upd = $db->prepare('UPDATE dag_dependencies SET dependency_id=? WHERE run_id=? AND dependency_id=?');
            $upd->execute([$replacement->taskId, $runId, $taskId]);
            foreach ($dependents as $dependent) {
                $dependent->state->dependencyRevision++;
                $dependent->state->stateVersion++;
                $dependent->state->updatedAt = DAGConstants::utcNow();
                $this->saveTaskLocked($db, $runId, $dependent);
            }
            foreach ($replacement->dependsOn as $dependency) {
                $insDep = $db->prepare(
                    'INSERT OR IGNORE INTO dag_dependencies(run_id,task_id,dependency_id,decomposition_id) VALUES(?,?,?,?)',
                );
                $insDep->execute([$runId, $replacement->taskId, $dependency, "supersede:{$taskId}"]);
            }
            // The root tasks are the SOURCES of the graph (V5-23): the
            // replacement only takes over the source position when it has
            // no dependencies of its own.
            if ($replacement->dependsOn === []) {
                $run->rootTaskIds = array_map(
                    static fn (string $root): string => $root === $taskId ? $replacement->taskId : $root,
                    $run->rootTaskIds,
                );
            }
            $run->graphRevision++;
            $run->updatedAt = DAGConstants::utcNow();
            $this->saveRunLocked($db, $run);
            $this->appendEventLocked(
                $db,
                $runId,
                'dag.task.superseded',
                taskId: $taskId,
                data: [
                    'replacement_task_id' => $replacement->taskId,
                    'graph_revision' => $run->graphRevision,
                ],
            );
            return new DAGTask($replacement, $state, $created);
        });
    }

    public function setRunStatus(string $runId, DAGRunStatus $status, ?string $reason = null): DAGRun
    {        return $this->transaction(function (\PDO $db) use ($runId, $status, $reason): DAGRun {
            $run = $this->requireRunLocked($db, $runId);
            $run->status = $status;
            $run->blocker = $reason;
            $run->updatedAt = DAGConstants::utcNow();
            $this->saveRunLocked($db, $run);
            if ($status === DAGRunStatus::CANCELLED) {
                $stmt = $db->prepare(
                    'SELECT spec,state,created_at FROM dag_tasks WHERE run_id=? AND status NOT IN (?,?,?,?)',
                );
                $stmt->execute([
                    $runId,
                    DAGTaskStatus::VERIFIED->value,
                    DAGTaskStatus::FAILED->value,
                    DAGTaskStatus::CANCELLED->value,
                    DAGTaskStatus::SUPERSEDED->value,
                ]);
                foreach ($stmt->fetchAll() as $row) {
                    $task = $this->taskFromRow($row);
                    $task->state->status = DAGTaskStatus::CANCELLED;
                    $task->state->activeAttemptId = null;
                    $task->state->blocker = $reason;
                    $task->state->stateVersion++;
                    $task->state->updatedAt = DAGConstants::utcNow();
                    $this->saveTaskLocked($db, $runId, $task);
                }
            }
            $eventName = match ($status) {
                DAGRunStatus::PAUSED => 'dag.run.paused',
                DAGRunStatus::RUNNING => 'dag.run.resumed',
                DAGRunStatus::CANCELLED => 'dag.run.cancelled',
                DAGRunStatus::SUCCEEDED => 'dag.run.completed',
                DAGRunStatus::FAILED => 'dag.run.failed',
                DAGRunStatus::BLOCKED => 'dag.run.blocked',
            };
            $this->appendEventLocked($db, $runId, $eventName, data: ['reason' => $reason]);
            return $run;
        });
    }

    public function refreshRunStatus(string $runId): DAGRun
    {
        return $this->transaction(fn (\PDO $db): DAGRun => $this->refreshRunStatusLocked($db, $runId));
    }

    // ------------------------------------------------------------------
    // Graph validation + internals
    // ------------------------------------------------------------------

    /**
     * @param list<string>|array<string> $taskIds
     * @param list<array{0: string, 1: string}> $edges
     */
    public static function validateGraph(array $taskIds, array $edges): void
    {
        $idSet = array_fill_keys($taskIds, true);
        $unknown = [];
        foreach ($edges as [$taskId, $dependencyId]) {
            foreach ([$taskId, $dependencyId] as $item) {
                if (!isset($idSet[$item])) {
                    $unknown[$item] = true;
                }
            }
        }
        if ($unknown !== []) {
            $sorted = array_keys($unknown);
            sort($sorted);
            throw new DAGError('UNKNOWN-DEPENDENCY', 'unknown task IDs: ' . json_encode($sorted));
        }
        foreach ($edges as [$taskId, $dependencyId]) {
            if ($taskId === $dependencyId) {
                throw new DAGError('CYCLE', 'self dependencies are not allowed');
            }
        }
        $indegree = array_fill_keys($taskIds, 0);
        $reverse = array_fill_keys($taskIds, []);
        foreach ($edges as [$taskId, $dependencyId]) {
            $indegree[$taskId]++;
            $reverse[$dependencyId][] = $taskId;
        }
        $ready = [];
        foreach ($indegree as $id => $degree) {
            if ($degree === 0) {
                $ready[] = $id;
            }
        }
        $visited = 0;
        while ($ready !== []) {
            $current = array_pop($ready);
            $visited++;
            foreach ($reverse[$current] as $dependent) {
                $indegree[$dependent]--;
                if ($indegree[$dependent] === 0) {
                    $ready[] = $dependent;
                }
            }
        }
        if ($visited !== count($taskIds)) {
            throw new DAGError('CYCLE', 'task dependencies contain a cycle');
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function taskFromRow(array $row): DAGTask
    {
        return new DAGTask(
            spec: TaskSpec::fromDict(json_decode((string) $row['spec'], true)),
            state: TaskState::fromDict(json_decode((string) $row['state'], true)),
            createdAt: (string) $row['created_at'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function eventFromDict(array $value): DAGEvent
    {
        return new DAGEvent(
            runId: (string) ($value['run_id'] ?? ''),
            sequence: (int) ($value['sequence'] ?? 0),
            type: (string) ($value['type'] ?? ''),
            eventId: (string) ($value['event_id'] ?? '') ?: bin2hex(random_bytes(16)),
            taskId: isset($value['task_id']) ? (string) $value['task_id'] : null,
            attemptId: isset($value['attempt_id']) ? (string) $value['attempt_id'] : null,
            data: is_array($value['data'] ?? null) ? $value['data'] : [],
            createdAt: (string) ($value['created_at'] ?? '') ?: DAGConstants::utcNow(),
            schemaVersion: (int) ($value['schema_version'] ?? DAGConstants::SCHEMA_VERSION),
        );
    }

    private function requireRunLocked(\PDO $db, string $runId): DAGRun
    {
        $stmt = $db->prepare('SELECT payload FROM dag_runs WHERE run_id=?');
        $stmt->execute([$runId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new \RuntimeException($runId);
        }
        return DAGRun::fromDict(json_decode((string) $row['payload'], true));
    }

    private function requireTaskLocked(\PDO $db, string $runId, string $taskId): DAGTask
    {
        $stmt = $db->prepare('SELECT spec,state,created_at FROM dag_tasks WHERE run_id=? AND task_id=?');
        $stmt->execute([$runId, $taskId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new \RuntimeException($taskId);
        }
        return $this->taskFromRow($row);
    }

    private function requireAttemptLocked(\PDO $db, string $attemptId): TaskAttempt
    {
        $stmt = $db->prepare('SELECT payload FROM dag_attempts WHERE attempt_id=?');
        $stmt->execute([$attemptId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new \RuntimeException($attemptId);
        }
        return TaskAttempt::fromDict(json_decode((string) $row['payload'], true));
    }

    private function assertAttemptCurrentLocked(\PDO $db, TaskAttempt $attempt, int $leaseEpoch, bool $allowLapsedLease = false): void
    {
        $task = $this->requireTaskLocked($db, $attempt->runId, $attempt->taskId);
        if ($attempt->leaseEpoch !== $leaseEpoch) {
            throw new DAGError('STALE-ATTEMPT', 'attempt lease epoch does not match the caller epoch');
        }
        // Epoch fencing (M-44): the epoch increases on every re-claim, so
        // an attempt of a previous claim is rejected even when the task
        // pointer still points at it.
        $currentEpoch = $this->currentTaskEpochLocked($db, $attempt->runId, $attempt->taskId);
        if ($currentEpoch !== $attempt->leaseEpoch) {
            throw new DAGError('STALE-ATTEMPT', 'a newer attempt has claimed this task');
        }
        if ($task->state->activeAttemptId !== $attempt->attemptId) {
            throw new DAGError('STALE-ATTEMPT', 'attempt no longer owns the task');
        }
        if (in_array($attempt->status, [DAGAttemptStatus::EXPIRED, DAGAttemptStatus::CANCELLED, DAGAttemptStatus::FAILED], true)) {
            throw new DAGError('STALE-ATTEMPT', "attempt is {$attempt->status->value}");
        }
        if (
            !$allowLapsedLease
            && $attempt->leaseExpiresAt !== null
            && self::parseTime($attempt->leaseExpiresAt) <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        ) {
            throw new DAGError('STALE-ATTEMPT', 'attempt lease has expired');
        }
    }

    /**
     * The highest lease epoch handed out for a task (= the epoch of its
     * current claim).
     */
    private function currentTaskEpochLocked(\PDO $db, string $runId, string $taskId): int
    {
        $stmt = $db->prepare(
            'SELECT COALESCE(MAX(lease_epoch),0) AS epoch FROM dag_attempts WHERE run_id=? AND task_id=?',
        );
        $stmt->execute([$runId, $taskId]);
        $row = $stmt->fetch();
        return $row === false ? 0 : (int) $row['epoch'];
    }

    private function saveRunLocked(\PDO $db, DAGRun $run): void
    {
        $stmt = $db->prepare(
            'UPDATE dag_runs SET status=?,graph_revision=?,scheduler_epoch=?,payload=?,updated_at=? WHERE run_id=?',
        );
        $stmt->execute([
            $run->status->value,
            $run->graphRevision,
            $run->schedulerEpoch,
            $this->json($run->toDict()),
            $run->updatedAt,
            $run->runId,
        ]);
    }

    private function updateRunLocked(
        \PDO $db,
        string $runId,
        ?DAGRunStatus $status = null,
        ?int $schedulerEpoch = null,
        ?string $blocker = null,
    ): DAGRun {
        $run = $this->requireRunLocked($db, $runId);
        if ($status !== null) {
            $run->status = $status;
        }
        if ($schedulerEpoch !== null) {
            $run->schedulerEpoch = $schedulerEpoch;
        }
        $run->blocker = $blocker;
        $run->updatedAt = DAGConstants::utcNow();
        $this->saveRunLocked($db, $run);
        return $run;
    }

    private function saveTaskLocked(\PDO $db, string $runId, DAGTask $task): void
    {
        $stmt = $db->prepare(
            'UPDATE dag_tasks SET status=?,priority=?,spec=?,state=? WHERE run_id=? AND task_id=?',
        );
        $stmt->execute([
            $task->state->status->value,
            $task->spec->priority,
            $this->json($task->spec->toDict()),
            $this->json($task->state->toDict()),
            $runId,
            $task->spec->taskId,
        ]);
    }

    private function saveAttemptLocked(\PDO $db, TaskAttempt $attempt): void
    {
        $stmt = $db->prepare(
            'UPDATE dag_attempts SET status=?,lease_epoch=?,lease_expires_at=?,payload=? WHERE attempt_id=?',
        );
        $stmt->execute([
            $attempt->status->value,
            $attempt->leaseEpoch,
            $attempt->leaseExpiresAt,
            $this->json($attempt->toDict()),
            $attempt->attemptId,
        ]);
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function appendEventLocked(
        \PDO $db,
        string $runId,
        string $eventType,
        ?string $taskId = null,
        ?string $attemptId = null,
        ?array $data = null,
    ): DAGEvent {
        // Bound parameter (M31): this was the only string-interpolated query
        // in the codebase; a run id containing SQL broke out of the WHERE.
        $row = $db->prepare(
            'SELECT COALESCE(MAX(sequence),0)+1 AS sequence FROM dag_events WHERE run_id = ?',
        );
        $row->execute([$runId]);
        $row = $row->fetch();
        $event = new DAGEvent(
            runId: $runId,
            sequence: (int) $row['sequence'],
            type: $eventType,
            taskId: $taskId,
            attemptId: $attemptId,
            data: $data ?? [],
        );
        $ins = $db->prepare('INSERT INTO dag_events(run_id,sequence,payload) VALUES(?,?,?)');
        $ins->execute([$runId, $event->sequence, $this->json($event->toDict())]);
        return $event;
    }

    private function dependenciesVerifiedLocked(\PDO $db, string $runId, string $taskId): bool
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) AS pending FROM dag_dependencies d '
            . 'JOIN dag_tasks t ON t.run_id=d.run_id AND t.task_id=d.dependency_id '
            . 'WHERE d.run_id=? AND d.task_id=? AND t.status != ?',
        );
        $stmt->execute([$runId, $taskId, DAGTaskStatus::VERIFIED->value]);
        $row = $stmt->fetch();
        return (int) $row['pending'] === 0;
    }

    /**
     * @return array<string, list<string>>
     */
    private function dependencyManifestLocked(\PDO $db, string $runId, string $taskId): array
    {
        $stmt = $db->prepare(
            "SELECT d.dependency_id,a.artifact_id FROM dag_dependencies d "
            . "LEFT JOIN dag_artifacts a ON a.run_id=d.run_id AND a.task_id=d.dependency_id AND a.state='published' "
            . 'WHERE d.run_id=? AND d.task_id=? ORDER BY d.dependency_id,a.artifact_id',
        );
        $stmt->execute([$runId, $taskId]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $depId = (string) $row['dependency_id'];
            if (!isset($result[$depId])) {
                $result[$depId] = [];
            }
            if ($row['artifact_id'] !== null) {
                $result[$depId][] = (string) $row['artifact_id'];
            }
        }
        return $result;
    }

    /**
     * The tasks of a run that no other task depends on (the sinks of the
     * graph).
     *
     * @param list<DAGTask> $tasks
     * @return list<DAGTask>
     */
    private function leafTasksLocked(\PDO $db, string $runId, array $tasks): array
    {
        $stmt = $db->prepare('SELECT DISTINCT dependency_id FROM dag_dependencies WHERE run_id=?');
        $stmt->execute([$runId]);
        $dependents = [];
        foreach ($stmt->fetchAll() as $row) {
            $dependents[(string) $row['dependency_id']] = true;
        }
        return array_values(array_filter(
            $tasks,
            // A superseded task handed its position to its replacement, so
            // it must not keep the run from completing.
            static fn (DAGTask $t): bool => !isset($dependents[$t->spec->taskId])
                && $t->state->status !== DAGTaskStatus::SUPERSEDED,
        ));
    }

    private function refreshDependentsLocked(\PDO $db, string $runId, string $dependencyId): void
    {
        $stmt = $db->prepare(
            'SELECT t.spec,t.state,t.created_at FROM dag_dependencies d '
            . 'JOIN dag_tasks t ON t.run_id=d.run_id AND t.task_id=d.task_id '
            . 'WHERE d.run_id=? AND d.dependency_id=?',
        );
        $stmt->execute([$runId, $dependencyId]);
        foreach ($stmt->fetchAll() as $row) {
            $task = $this->taskFromRow($row);
            if ($task->state->status !== DAGTaskStatus::WAITING_DEPENDENCIES) {
                continue;
            }
            if ($this->dependenciesVerifiedLocked($db, $runId, $task->spec->taskId)) {
                $task->state->status = DAGTaskStatus::READY;
                $task->state->stateVersion++;
                $task->state->updatedAt = DAGConstants::utcNow();
                $this->saveTaskLocked($db, $runId, $task);
                $this->appendEventLocked($db, $runId, 'dag.task.ready', taskId: $task->spec->taskId);
            }
        }
    }

    private function refreshRunStatusLocked(\PDO $db, string $runId): DAGRun
    {
        $run = $this->requireRunLocked($db, $runId);
        $stmt = $db->prepare('SELECT spec,state,created_at FROM dag_tasks WHERE run_id=?');
        $stmt->execute([$runId]);
        $tasks = array_map([$this, 'taskFromRow'], $stmt->fetchAll());
        // The run completes when every LEAF task (a task nobody depends on)
        // is verified: a leaf can only run after all of its ancestors, so
        // verified leaves imply a fully verified graph. The run's
        // rootTaskIds are the SOURCES of the graph (the tasks without
        // dependencies, V5-23) and they finish FIRST, so they cannot be
        // used as the completion criterion.
        $leaves = $this->leafTasksLocked($db, $runId, $tasks);
        $hasBlocked = false;
        $hasActive = false;
        $hasFailed = false;
        foreach ($tasks as $task) {
            if (in_array($task->state->status, [DAGTaskStatus::BLOCKED, DAGTaskStatus::WAITING_APPROVAL], true)) {
                $hasBlocked = true;
            }
            if (in_array($task->state->status, [DAGTaskStatus::READY, DAGTaskStatus::RUNNING, DAGTaskStatus::VERIFYING], true)) {
                $hasActive = true;
            }
            if ($task->state->status === DAGTaskStatus::FAILED) {
                $hasFailed = true;
            }
        }
        $hasRetryWait = false;
        foreach ($tasks as $task) {
            if ($task->state->status === DAGTaskStatus::RETRY_WAIT) {
                $hasRetryWait = true;
            }
        }

        if ($leaves !== [] && count(array_filter($leaves, static fn (DAGTask $t): bool => $t->state->status === DAGTaskStatus::VERIFIED)) === count($leaves)) {
            $run->status = DAGRunStatus::SUCCEEDED;
            $run->blocker = null;
            $event = 'dag.run.completed';
        } elseif ($hasBlocked && !$hasActive) {
            $run->status = DAGRunStatus::BLOCKED;
            $run->blocker = 'workflow requires external action';
            $event = 'dag.run.blocked';
        } elseif (!$hasActive && !$hasRetryWait && $hasFailed) {
            $run->status = DAGRunStatus::FAILED;
            $run->blocker = 'a required dependency task failed';
            $event = 'dag.run.failed';
        } else {
            return $run;
        }
        $run->updatedAt = DAGConstants::utcNow();
        $this->saveRunLocked($db, $run);
        $last = $db->prepare('SELECT payload FROM dag_events WHERE run_id=? ORDER BY sequence DESC LIMIT 1');
        $last->execute([$runId]);
        $lastRow = $last->fetch();
        $lastType = $lastRow !== false ? (string) (json_decode((string) $lastRow['payload'], true)['type'] ?? '') : '';
        if ($lastType !== $event) {
            $this->appendEventLocked($db, $runId, $event, data: ['reason' => $run->blocker]);
        }
        return $run;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function commandLocked(\PDO $db, string $runId, string $commandKey, string $requestDigest): ?array
    {
        $stmt = $db->prepare(
            'SELECT request_digest,response FROM dag_commands WHERE run_id=? AND command_key=?',
        );
        $stmt->execute([$runId, $commandKey]);
        $row = $stmt->fetch();
        if ($row === false || (string) $row['request_digest'] !== $requestDigest) {
            return null;
        }
        return json_decode((string) $row['response'], true);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function saveCommandLocked(\PDO $db, string $runId, string $commandKey, string $requestDigest, array $response): void
    {
        $stmt = $db->prepare(
            'INSERT INTO dag_commands(run_id,command_key,request_digest,response) VALUES(?,?,?,?)',
        );
        $stmt->execute([$runId, $commandKey, $requestDigest, $this->json($response)]);
    }

    /**
     * Python json.dumps(sort_keys, separators, ensure_ascii) equivalent.
     *
     * @param mixed $value
     */
    private function json(mixed $value): string
    {
        // Canonical JSON for the persisted state. Json::canonical() throws
        // for an unencodable value: writing '' into the state column would
        // make every such state identical (T5b).
        return Json::canonical($value);
    }

    public static function parseTime(string $value): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable(str_replace('Z', '+00:00', $value));
        } catch (\Exception $e) {
            throw new \InvalidArgumentException("invalid timestamp: {$value}");
        }
    }
}
