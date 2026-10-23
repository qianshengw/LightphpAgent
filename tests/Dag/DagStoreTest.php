<?php

declare(strict_types=1);

namespace LightAgent\Tests\Dag;

use LightAgent\Dag\DAGConfig;
use LightAgent\Dag\DAGError;
use LightAgent\Dag\DAGRun;
use LightAgent\Dag\DAGRunStatus;
use LightAgent\Dag\DAGTaskStatus;
use LightAgent\Dag\DAGAttemptStatus;
use LightAgent\Dag\SqliteTaskGraphStore;
use LightAgent\Dag\TaskSpec;
use PHPUnit\Framework\TestCase;

/**
 * PHP port of tests/test_dag_store.py core scenarios.
 */
final class DagStoreTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/la_dag_' . bin2hex(random_bytes(4)) . '.db';
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    /**
     * @param list<string> $dependsOn
     */
    private function spec(string $taskId, array $dependsOn = [], int $priority = 0): TaskSpec
    {
        return new TaskSpec(
            goal: "goal {$taskId}",
            acceptanceContract: ['ok' => true],
            workerKey: 'worker',
            verifierKey: 'verifier',
            taskId: $taskId,
            dependsOn: $dependsOn,
            priority: $priority,
        );
    }

    private function makeRun(array $rootTaskIds, string $runId = 'run-1'): DAGRun
    {
        return new DAGRun(
            tenantId: 'tenant-a',
            projectId: 'project-a',
            rootTaskIds: $rootTaskIds,
            runId: $runId,
        );
    }

    public function test_store_rejects_unknown_dependency_and_cycle(): void
    {
        $store = new SqliteTaskGraphStore(':memory:');
        $config = new DAGConfig();

        try {
            $store->createRun($this->makeRun(['a']), [$this->spec('a', ['missing'])], $config);
            self::fail('expected UNKNOWN-DEPENDENCY');
        } catch (DAGError $e) {
            self::assertSame('LA-DAG-UNKNOWN-DEPENDENCY', $e->errorCode);
        }

        try {
            $store->createRun($this->makeRun(['a']), [$this->spec('a', ['b']), $this->spec('b', ['a'])], $config);
            self::fail('expected CYCLE');
        } catch (DAGError $e) {
            self::assertSame('LA-DAG-CYCLE', $e->errorCode);
        }
    }

    public function test_store_creates_diamond_graph_and_claims_each_task_once(): void
    {
        $store = new SqliteTaskGraphStore(':memory:');
        $config = new DAGConfig();
        $tasks = [
            $this->spec('shared'),
            $this->spec('left', ['shared'], priority: 5),
            $this->spec('right', ['shared'], priority: 5),
            $this->spec('root', ['left', 'right']),
        ];
        $run = $store->createRun($this->makeRun(['root']), $tasks, $config);

        self::assertSame(1, $run->graphRevision);
        self::assertCount(4, $store->listTasks($run->runId));
        self::assertSame(['left', 'right'], $store->dependencies($run->runId, 'root'));

        // Claim shared twice: second claim must return NULL (no double claim)
        [$claimedTask, $attempt] = $store->claimReadyTask($run->runId, 'worker-1', 60) ?? [null, null];
        self::assertNotNull($claimedTask);
        self::assertSame('shared', $claimedTask->spec->taskId);
        self::assertSame(DAGTaskStatus::RUNNING, $claimedTask->state->status);
        self::assertSame(DAGAttemptStatus::CLAIMED, $attempt->status);

        self::assertNull($store->claimReadyTask($run->runId, 'worker-2', 60), 'shared is already claimed, dependencies unverified');

        // Stage then publish shared → left/right become ready
        $manifest = new \LightAgent\Dag\ArtifactManifest(
            runId: $run->runId,
            taskId: 'shared',
            attemptId: $attempt->attemptId,
            tenantId: 'tenant-a',
            projectId: 'project-a',
            contentHash: 'abc',
            relativeBlobPath: 'blobs/abc',
            mediaType: 'text/plain',
            byteSize: 3,
            contractHash: $claimedTask->spec->contractHash,
        );
        $store->saveStagedArtifact($manifest, $attempt->leaseEpoch);
        $report = new \LightAgent\Dag\VerificationReport(
            kind: 'contract',
            verdict: \LightAgent\Dag\VerificationVerdict::PASS,
            assuranceLevel: \LightAgent\Dag\AssuranceLevel::EXECUTABLE_CHECKS,
            verifierKey: 'verifier',
            verifierVersion: '1',
            configDigest: 'cfg',
            inputSnapshotHash: $attempt->inputSnapshotHash,
            // A report may only publish an artifact it is bound to: the
            // store refuses a report that does not name the content hash
            // of the artifact (V5-09).
            artifactHashes: [$manifest->contentHash],
        );
        $published = $store->publishArtifact($manifest, $report, $attempt->leaseEpoch);
        self::assertSame('published', $published->state);

        $left = $store->claimReadyTask($run->runId, 'worker-1', 60);
        self::assertNotNull($left);
        self::assertSame('left', $left[0]->spec->taskId);

        // right is still READY and independent → claimable by another worker
        $right = $store->claimReadyTask($run->runId, 'worker-2', 60);
        self::assertNotNull($right);
        self::assertSame('right', $right[0]->spec->taskId);

        // root waits until both left and right verified
        self::assertNull($store->claimReadyTask($run->runId, 'worker-3', 60));
    }

    public function test_expired_attempt_is_requeued_and_old_epoch_is_rejected(): void
    {
        $store = new SqliteTaskGraphStore(':memory:');
        $config = new DAGConfig();
        $run = $store->createRun($this->makeRun(['t']), [$this->spec('t')], $config);

        [, $attempt] = $store->claimReadyTask($run->runId, 'w1', 60);
        self::assertNotNull($attempt);

        // Force lease expiry
        $attempt->leaseExpiresAt = '2000-01-01T00:00:00+00:00';
        $this->forceAttemptPayload($store, $attempt);
        self::assertSame(1, $store->expireAttempts($run->runId));

        $task = $store->getTask($run->runId, 't');
        self::assertSame(DAGTaskStatus::READY, $task->state->status);
        self::assertNull($task->state->activeAttemptId);

        // Old epoch cannot operate the task anymore
        $this->expectException(DAGError::class);
        $this->expectExceptionMessage('STALE-ATTEMPT');
        $store->markAttemptRunning($attempt->attemptId, $attempt->leaseEpoch);
    }

    private function forceAttemptPayload(SqliteTaskGraphStore $store, \LightAgent\Dag\TaskAttempt $attempt): void
    {
        // Rewrite payload via a direct sqlite update (test-only backdoor)
        /** @var \PDO $pdo */
        $pdo = $store->pdoForTest();
        $stmt = $pdo->prepare('UPDATE dag_attempts SET lease_expires_at=?, payload=? WHERE attempt_id=?');
        $json = json_encode($attempt->toDict(), JSON_UNESCAPED_SLASHES);
        $stmt->execute([$attempt->leaseExpiresAt, $json, $attempt->attemptId]);
    }

    /**
     * M-44 / V4-18: the lease epoch is a real fencing token - it increases
     * on every claim and an attempt of a previous claim is rejected.
     */
    public function test_epoch_increases_on_every_claim_and_fences_the_previous_attempt(): void
    {
        $store = new SqliteTaskGraphStore(':memory:');
        $config = new DAGConfig();
        $run = $store->createRun($this->makeRun(['t']), [$this->spec('t')], $config);

        [, $first] = $store->claimReadyTask($run->runId, 'w1', 60);
        self::assertNotNull($first);
        self::assertSame(1, $first->leaseEpoch);

        // Let the lease lapse so the task can be claimed again.
        $first->leaseExpiresAt = '2000-01-01T00:00:00+00:00';
        $this->forceAttemptPayload($store, $first);
        $store->expireAttempts($run->runId);

        [, $second] = $store->claimReadyTask($run->runId, 'w2', 60);
        self::assertNotNull($second);
        self::assertGreaterThan($first->leaseEpoch, $second->leaseEpoch);

        // The previous attempt is fenced by the epoch of the newer claim.
        $this->expectException(DAGError::class);
        $this->expectExceptionMessage('STALE-ATTEMPT');
        $store->markAttemptRunning($first->attemptId, $first->leaseEpoch);
    }

    public function test_active_scheduler_lease_cannot_be_stolen(): void
    {
        $store = new SqliteTaskGraphStore(':memory:');
        $config = new DAGConfig();
        $run = $store->createRun($this->makeRun(['t']), [$this->spec('t')], $config);

        $epoch1 = $store->acquireSchedulerLease($run->runId, 'scheduler-a', 60);
        self::assertSame(1, $epoch1);

        try {
            $store->acquireSchedulerLease($run->runId, 'scheduler-b', 60);
            self::fail('expected LEASE-REJECTED');
        } catch (DAGError $e) {
            self::assertSame('LA-DAG-LEASE-REJECTED', $e->errorCode);
        }

        // Same owner re-acquiring keeps the same epoch (Python semantics)
        $epochAgain = $store->acquireSchedulerLease($run->runId, 'scheduler-a', 60);
        self::assertSame(1, $epochAgain);
        $store->renewSchedulerLease($run->runId, 'scheduler-a', $epoch1, 60);
        $store->releaseSchedulerLease($run->runId, 'scheduler-a', $epoch1);
    }

    /**
     * T8: a scheduler lease must keep sub-second precision.
     *
     * The expiry was computed in milliseconds but rendered with
     * DateTimeInterface::ATOM, which truncates to whole seconds. The stored
     * expiry was therefore up to a second too early — for a sub-second lease
     * it was already in the past the moment it was written, and a LIVE lease
     * could be stolen by a second scheduler.
     */
    public function test_scheduler_lease_keeps_sub_second_precision(): void
    {
        $store = new SqliteTaskGraphStore(':memory:');
        $config = new DAGConfig();
        $run = $store->createRun($this->makeRun(['t']), [$this->spec('t')], $config);

        $store->acquireSchedulerLease($run->runId, 'scheduler-a', 0.5);

        /** @var \PDO $pdo */
        $pdo = $store->pdoForTest();
        $stmt = $pdo->prepare('SELECT expires_at FROM dag_scheduler_leases WHERE run_id=?');
        $stmt->execute([$run->runId]);
        $stored = (string) $stmt->fetchColumn();

        self::assertMatchesRegularExpression(
            '/\.\d{3}\+00:00$/',
            $stored,
            'the stored lease expiry must keep millisecond precision, got: ' . $stored,
        );
        self::assertGreaterThan(
            microtime(true),
            (float) SqliteTaskGraphStore::parseTime($stored)->format('U.u'),
            'a freshly acquired lease must expire in the future, got: ' . $stored,
        );
    }

    public function test_create_run_is_idempotent_for_same_logical_request(): void
    {
        $store = new SqliteTaskGraphStore(':memory:');
        $config = new DAGConfig();
        $run = $this->makeRun(['t']);
        $tasks = [$this->spec('t')];

        $first = $store->createRun($run, $tasks, $config);
        $second = $store->createRun($this->makeRun(['t'], $run->runId), $tasks, $config);

        self::assertSame($first->runId, $second->runId);
        self::assertSame($first->graphRevision, $second->graphRevision);

        // Same id but different content → conflict
        $this->expectException(DAGError::class);
        $this->expectExceptionMessage('IDEMPOTENCY-CONFLICT');
        $store->createRun($this->makeRun(['other'], $run->runId), [$this->spec('other')], $config);
    }

    public function test_set_run_status_cancels_pending_tasks(): void
    {
        $store = new SqliteTaskGraphStore(':memory:');
        $config = new DAGConfig();
        $run = $store->createRun($this->makeRun(['a', 'b']), [$this->spec('a'), $this->spec('b')], $config);

        $cancelled = $store->setRunStatus($run->runId, DAGRunStatus::CANCELLED, 'user aborted');
        self::assertSame(DAGRunStatus::CANCELLED, $cancelled->status);
        self::assertSame('user aborted', $cancelled->blocker);

        $task = $store->getTask($run->runId, 'a');
        self::assertSame(DAGTaskStatus::CANCELLED, $task->state->status);

        // Claims are refused on a cancelled run
        self::assertNull($store->claimReadyTask($run->runId, 'w', 60));
    }

    public function test_events_are_sequential_and_paged(): void
    {
        $store = new SqliteTaskGraphStore(':memory:');
        $config = new DAGConfig();
        $run = $store->createRun($this->makeRun(['a', 'b']), [$this->spec('a'), $this->spec('b')], $config);

        $events = $store->events($run->runId);
        self::assertGreaterThanOrEqual(1, count($events));
        $sequences = array_map(static fn ($e): int => $e->sequence, $events);
        self::assertSame(range(1, count($sequences)), $sequences);

        // after=1 returns events strictly after sequence 1
        $page = $store->events($run->runId, after: 1, limit: 100);
        foreach ($page as $event) {
            self::assertGreaterThan(1, $event->sequence);
        }
    }

    public function test_fail_attempt_retries_until_max_attempts(): void
    {
        $store = new SqliteTaskGraphStore(':memory:');
        $config = new DAGConfig();
        $run = $store->createRun($this->makeRun(['t']), [$this->spec('t')], $config);

        $maxAttempts = $config->maxAttemptsPerTask;
        for ($i = 1; $i < $maxAttempts; $i++) {
            [, $attempt] = $store->claimReadyTask($run->runId, 'w', 60);
            self::assertNotNull($attempt);
            $task = $store->failAttempt($attempt->attemptId, $attempt->leaseEpoch, 'flaky', $maxAttempts);
            self::assertSame(DAGTaskStatus::READY, $task->state->status, "attempt {$i} should requeue");
        }
        // Final attempt fails → task FAILED
        [, $attempt] = $store->claimReadyTask($run->runId, 'w', 60);
        $task = $store->failAttempt($attempt->attemptId, $attempt->leaseEpoch, 'flaky', $maxAttempts);
        self::assertSame(DAGTaskStatus::FAILED, $task->state->status);
    }
}
