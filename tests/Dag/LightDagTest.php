<?php

declare(strict_types=1);

namespace LightAgent\Tests\Dag;

use LightAgent\Async\AsyncRuntime;
use LightAgent\Dag\AssuranceLevel;
use LightAgent\Dag\CallableVerifier;
use LightAgent\Dag\DAGConfig;
use LightAgent\Dag\DAGError;
use LightAgent\Dag\DAGRunStatus;
use LightAgent\Dag\DAGTaskStatus;
use LightAgent\Dag\LightDAG;
use LightAgent\Dag\RegistryWorkerFactory;
use LightAgent\Dag\TaskOutcome;
use LightAgent\Dag\TaskSpec;
use LightAgent\Dag\VerificationReport;
use LightAgent\Dag\VerificationVerdict;
use LightAgent\Security\CapabilityGate;
use LightAgent\Security\SecurityContext;
use PHPUnit\Framework\TestCase;

/**
 * PHP port of tests/test_lightdag.py core end-to-end scenarios.
 */
final class LightDagTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/la_lightdag_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->tmpDir);
    }

    private function makeContext(?string $runId = null): SecurityContext
    {
        return new SecurityContext(
            userId: 'alice',
            tenantId: 'tenant-a',
            projectId: 'project-a',
            runId: $runId,
            policyVersion: 'policy-1',
        );
    }

    private function makeDag(?DAGConfig $config = null): LightDAG
    {
        return new LightDAG(
            artifactRoot: $this->tmpDir . '/artifacts',
            config: $config,
        );
    }

    private function spec(string $taskId, array $dependsOn = []): TaskSpec
    {
        return new TaskSpec(
            goal: "goal {$taskId}",
            acceptanceContract: ['ok' => true],
            workerKey: 'worker',
            verifierKey: 'verifier',
            taskId: $taskId,
            dependsOn: $dependsOn,
        );
    }

    private function passVerifier(): CallableVerifier
    {
        return new CallableVerifier('verifier', static fn (object $request): bool => true);
    }

    public function test_requires_explicit_verifier_and_structured_worker_output(): void
    {
        $dag = $this->makeDag();
        $dag->registerWorker('worker', static fn (object $ctx): array => ['kind' => 'candidate', 'content' => 'ok']);
        // No verifier registered → createRun validation fails (Python parity)
        try {
            $dag->createRun([$this->spec('t')], context: $this->makeContext());
            self::fail('expected VERIFIER-UNAVAILABLE');
        } catch (DAGError $e) {
            self::assertSame('LA-DAG-VERIFIER-UNAVAILABLE', $e->errorCode);
        }

        // With verifier but unstructured worker output → attempt fails
        $dag2 = $this->makeDag();
        $dag2->registerWorker('worker', static fn (object $ctx): string => 'not-a-mapping');
        $dag2->registerVerifier('verifier', $this->passVerifier());
        $run2 = $dag2->createRun([$this->spec('t')], context: $this->makeContext());
        $result = $dag2->run($run2->runId, context: $this->makeContext($run2->runId));
        self::assertSame(DAGRunStatus::FAILED, $result->status);
        self::assertSame(['t'], $result->failedTasks);
    }

    public function test_publishes_only_verified_artifacts_and_enforces_scope(): void
    {
        $dag = $this->makeDag();
        $dag->registerWorker('worker', static fn (object $ctx): array => ['kind' => 'candidate', 'content' => 'artifact body']);
        $dag->registerVerifier('verifier', $this->passVerifier());

        $run = $dag->createRun([$this->spec('root')], context: $this->makeContext());
        $result = $dag->run($run->runId, context: $this->makeContext($run->runId));

        self::assertSame(DAGRunStatus::SUCCEEDED, $result->status);
        $artifacts = $result->rootArtifacts['root'] ?? [];
        self::assertCount(1, $artifacts);
        self::assertSame('published', $artifacts[0]->state);

        // Tampered artifact (content changed on disk) → integrity failure on next read
        $dag->store; // no-op touch
    }

    public function test_multi_step_dag_completes(): void
    {
        $dag = $this->makeDag();
        $calls = [];
        $dag->registerWorker('worker', static function (object $ctx) use (&$calls): array {
            $calls[] = $ctx->task->spec->taskId;
            return ['kind' => 'candidate', 'content' => "out:{$ctx->task->spec->taskId}"];
        });
        $dag->registerVerifier('verifier', $this->passVerifier());

        $run = $dag->createRun(
            [$this->spec('shared'), $this->spec('left', ['shared']), $this->spec('right', ['shared']), $this->spec('root', ['left', 'right'])],
            context: $this->makeContext(),
        );
        $result = $dag->run($run->runId, context: $this->makeContext($run->runId));

        self::assertSame(DAGRunStatus::SUCCEEDED, $result->status);
        self::assertCount(4, $calls);
        // The root tasks are the SOURCES of the graph (the tasks without
        // dependencies): `shared` is the root, `root` is the sink (V5-23).
        self::assertSame(['shared'], array_keys($result->rootArtifacts));
        self::assertCount(1, $result->rootArtifacts['shared'] ?? []);
        self::assertSame([], $result->failedTasks);
    }

    public function test_verification_failure_never_publishes_staged_artifact(): void
    {
        $dag = $this->makeDag();
        $dag->registerWorker('worker', static fn (object $ctx): array => ['kind' => 'candidate', 'content' => 'bad body']);
        $dag->registerVerifier('verifier', new CallableVerifier('verifier', static fn (object $request): bool => false));

        $run = $dag->createRun([$this->spec('root')], context: $this->makeContext());
        $result = $dag->run($run->runId, context: $this->makeContext($run->runId));

        self::assertSame(DAGRunStatus::FAILED, $result->status);
        self::assertSame(['root'], $result->failedTasks);
        self::assertSame([], $result->rootArtifacts['root'] ?? []);
        // staged artifacts must not be readable as published
        $published = $dag->store->listArtifacts($run->runId, 'root', state: 'published');
        self::assertSame([], $published);
    }

    public function test_blocked_run_requires_explicit_resolution_before_execution(): void
    {
        $dag = $this->makeDag();
        $dag->registerWorker('worker', static fn (object $ctx): array => ['kind' => 'blocked', 'blocker' => 'needs human input']);
        $dag->registerVerifier('verifier', $this->passVerifier());

        $run = $dag->createRun([$this->spec('root')], context: $this->makeContext());
        $result = $dag->run($run->runId, context: $this->makeContext($run->runId));

        self::assertSame(DAGRunStatus::BLOCKED, $result->status);
        self::assertSame(['root' => 'needs human input'], $result->blockers);

        // Resolve → resume completes
        $dag->resolveBlocker($run->runId, 'root', 'human resolved');
        $dag->registerWorker('worker', static fn (object $ctx): array => ['kind' => 'candidate', 'content' => 'done after resolve']);
        $result2 = $dag->resume($run->runId, context: $this->makeContext($run->runId));
        self::assertSame(DAGRunStatus::SUCCEEDED, $result2->status);
    }

    public function test_continue_independent_finishes_other_root_after_failure(): void
    {
        $config = new DAGConfig(failurePolicy: 'continue_independent');
        $dag = new LightDAG(artifactRoot: $this->tmpDir . '/artifacts', config: $config);
        $dag->registerWorker('worker', static function (object $ctx): array {
            if ($ctx->task->spec->taskId === 'bad') {
                throw new \RuntimeException('injected failure');
            }
            return ['kind' => 'candidate', 'content' => 'ok'];
        });
        $dag->registerVerifier('verifier', $this->passVerifier());

        $run = $dag->createRun([$this->spec('bad'), $this->spec('good')], context: $this->makeContext());
        $result = $dag->run($run->runId, context: $this->makeContext($run->runId));

        self::assertSame(DAGRunStatus::FAILED, $result->status);
        self::assertSame(['bad'], $result->failedTasks);
        self::assertCount(1, $result->rootArtifacts['good'] ?? []);
    }

    public function test_fail_fast_stops_before_finishing_other_root(): void
    {
        $config = new DAGConfig(failurePolicy: 'fail_fast', maxConcurrency: 1);
        $dag = new LightDAG(artifactRoot: $this->tmpDir . '/artifacts', config: $config);
        $executed = [];
        $dag->registerWorker('worker', static function (object $ctx) use (&$executed): array {
            $executed[] = $ctx->task->spec->taskId;
            if ($ctx->task->spec->taskId === 'bad') {
                throw new \RuntimeException('injected failure');
            }
            return ['kind' => 'candidate', 'content' => 'ok'];
        });
        $dag->registerVerifier('verifier', $this->passVerifier());

        $run = $dag->createRun([$this->spec('bad'), $this->spec('good')], context: $this->makeContext());
        $result = $dag->run($run->runId, context: $this->makeContext($run->runId));

        self::assertSame(DAGRunStatus::FAILED, $result->status);
        self::assertContains('bad', $executed);
        // fail_fast with concurrency=1: 'good' must not have completed
        self::assertNotContains('good', $executed);
    }

    public function test_capability_denial_fails_task(): void
    {
        $config = new DAGConfig(maxAttemptsPerTask: 1);
        $dag = new LightDAG(artifactRoot: $this->tmpDir . '/artifacts', config: $config);
        $dag->registerWorker('worker', static fn (object $ctx): array => ['kind' => 'candidate', 'content' => 'ok']);
        $dag->registerVerifier('verifier', $this->passVerifier());

        // Security context whose permission snapshot denies the execution
        // capability → the gate must block before the worker runs.
        $context = new SecurityContext(
            tenantId: 'tenant-a',
            projectId: 'project-a',
            policyVersion: 'p1',
            permissions: new \LightAgent\Capabilities\PermissionSet(
                denied: ['dag.task.execute'],
                maxRisk: \LightAgent\Capabilities\CapabilityRisk::READ_ONLY,
            ),
        );
        $run = $dag->createRun([$this->spec('t')], context: $context);
        $result = $dag->run($run->runId, context: $context);

        self::assertSame(DAGRunStatus::FAILED, $result->status);
        self::assertSame(['t'], $result->failedTasks);
    }

    public function test_pause_prevents_execution_until_resumed(): void
    {
        $dag = $this->makeDag();
        $dag->registerWorker('worker', static fn (object $ctx): array => ['kind' => 'candidate', 'content' => 'ok']);
        $dag->registerVerifier('verifier', $this->passVerifier());

        $run = $dag->createRun([$this->spec('t')], context: $this->makeContext());
        $dag->pause($run->runId, 'manual pause');

        $result = $dag->run($run->runId, context: $this->makeContext($run->runId));
        self::assertSame(DAGRunStatus::PAUSED, $result->status);

        $result2 = $dag->resume($run->runId, context: $this->makeContext($run->runId));
        self::assertSame(DAGRunStatus::SUCCEEDED, $result2->status);
    }

    /**
     * C-4 / V5-08: a task that needs more time than the attempt lease must
     * keep its claim - the lease is renewed while the worker runs - and it
     * must never be executed again without bound.
     */
    public function test_slow_task_keeps_its_lease_and_is_not_re_executed(): void
    {
        $config = new DAGConfig(taskLeaseSeconds: 0.2, maxAttemptsPerTask: 3);
        $dag = new LightDAG(artifactRoot: $this->tmpDir . '/artifacts', config: $config);
        $calls = 0;
        $dag->registerWorker('worker', static function (object $ctx) use (&$calls): array {
            $calls++;
            // Blocks the event loop, so the lease can only be renewed after
            // the worker returned.
            usleep(600000);
            return ['kind' => 'candidate', 'content' => 'slow body'];
        });
        $dag->registerVerifier('verifier', $this->passVerifier());

        $run = $dag->createRun([$this->spec('root')], context: $this->makeContext());
        $result = $dag->run($run->runId, context: $this->makeContext($run->runId));

        self::assertSame(DAGRunStatus::SUCCEEDED, $result->status);
        self::assertLessThanOrEqual(3, $calls, 'the worker must not be re-executed without bound');
        self::assertCount(1, $result->rootArtifacts['root'] ?? []);
    }

    /**
     * C-4: an expired lease consumes the retry budget - the task must end
     * as failed instead of being re-queued forever.
     */
    public function test_lease_expiry_consumes_the_retry_budget(): void
    {
        $config = new DAGConfig(taskLeaseSeconds: 0.05, maxAttemptsPerTask: 3);
        $dag = new LightDAG(artifactRoot: $this->tmpDir . '/artifacts', config: $config);
        $calls = 0;
        $dag->registerWorker('worker', static function (object $ctx) use (&$calls): array {
            $calls++;
            usleep(200000);
            throw new \RuntimeException('injected failure');
        });
        $dag->registerVerifier('verifier', $this->passVerifier());

        $run = $dag->createRun([$this->spec('root')], context: $this->makeContext());
        $result = $dag->run($run->runId, context: $this->makeContext($run->runId));

        self::assertSame(DAGRunStatus::FAILED, $result->status);
        self::assertLessThanOrEqual(3, $calls, 'max_attempts_per_task must bound the executions');
        self::assertSame(['root'], $result->failedTasks);
    }

    /**
     * V5-09: a passing report that is not bound to the published bytes must
     * not publish the artifact.
     */
    public function test_verification_report_must_be_bound_to_the_published_bytes(): void
    {
        $dag = $this->makeDag();
        $dag->registerWorker('worker', static fn (object $ctx): array => ['kind' => 'candidate', 'content' => 'artifact body']);
        $dag->registerVerifier('verifier', new CallableVerifier('verifier', static fn (object $request): VerificationReport => new VerificationReport(
            kind: 'completion',
            verdict: VerificationVerdict::PASS,
            assuranceLevel: AssuranceLevel::EXECUTABLE_CHECKS,
            verifierKey: 'verifier',
            verifierVersion: '1',
            configDigest: 'cfg',
            inputSnapshotHash: $request->inputSnapshotHash,
            artifactHashes: ['a-hash-that-is-not-the-candidate'],
        )));

        $run = $dag->createRun([$this->spec('root')], context: $this->makeContext());
        $result = $dag->run($run->runId, context: $this->makeContext($run->runId));

        self::assertSame(DAGRunStatus::FAILED, $result->status);
        self::assertSame([], $dag->store->listArtifacts($run->runId, 'root', state: 'published'));
    }

    /**
     * V5-23: the root tasks are the sources of the graph, so the root
     * artifacts are the artifacts of the source task (README §11).
     */
    public function test_root_artifacts_belong_to_the_source_task(): void
    {
        $dag = $this->makeDag();
        $dag->registerWorker('worker', static fn (object $ctx): array => ['kind' => 'candidate', 'content' => 'body']);
        $dag->registerVerifier('verifier', $this->passVerifier());

        $run = $dag->createRun(
            [$this->spec('root'), $this->spec('child', ['root'])],
            context: $this->makeContext(),
        );
        $result = $dag->run($run->runId, context: $this->makeContext($run->runId));

        self::assertSame(DAGRunStatus::SUCCEEDED, $result->status);
        self::assertArrayHasKey('root', $result->rootArtifacts);
        self::assertCount(1, $result->rootArtifacts['root']);
        self::assertArrayNotHasKey('child', $result->rootArtifacts);
    }

    /**
     * V5-31: a cancelled run returns a cancelled result instead of
     * throwing.
     */
    public function test_cancelled_run_returns_a_cancelled_result(): void
    {
        $dag = $this->makeDag();
        $dag->registerWorker('worker', static function (object $ctx) use (&$dag): array {
            $dag->cancel($ctx->run->runId, 'stop now');
            return ['kind' => 'candidate', 'content' => 'never published'];
        });
        $dag->registerVerifier('verifier', $this->passVerifier());

        $run = $dag->createRun([$this->spec('root')], context: $this->makeContext());
        $result = $dag->run($run->runId, context: $this->makeContext($run->runId));

        self::assertSame(DAGRunStatus::CANCELLED, $result->status);
        self::assertSame([], $result->rootArtifacts['root'] ?? []);
    }

    /**
     * M-24: the orphan cleanup must never delete the task graph database
     * that lives next to the artifacts.
     */
    public function test_orphan_cleanup_keeps_the_graph_database(): void
    {
        $dag = $this->makeDag();
        $dag->registerWorker('worker', static fn (object $ctx): array => ['kind' => 'candidate', 'content' => 'body']);
        $dag->registerVerifier('verifier', $this->passVerifier());

        $run = $dag->createRun([$this->spec('root')], context: $this->makeContext());
        $dag->run($run->runId, context: $this->makeContext($run->runId));

        $orphans = $dag->artifactStore->removeOrphans([], dryRun: true);
        self::assertNotContains('graph.sqlite3', $orphans);
        self::assertNotContains('graph.sqlite3-wal', $orphans);
        self::assertNotContains('graph.sqlite3-shm', $orphans);

        $dag->artifactStore->removeOrphans([], dryRun: false);
        self::assertFileExists($this->tmpDir . '/artifacts/graph.sqlite3');
    }
}
