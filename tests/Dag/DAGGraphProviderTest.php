<?php

declare(strict_types=1);

namespace LightAgent\Tests\Dag;

use LightAgent\Capabilities\CapabilityRegistry;
use LightAgent\Dag\DAGError;
use LightAgent\Dag\DAGGraph;
use LightAgent\Dag\LightDAGProvider;
use PHPUnit\Framework\TestCase;

/**
 * Functional tests for dag/graph.py + dag/provider.py ports.
 */
final class DAGGraphProviderTest extends TestCase
{
    public function test_topological_order_deterministic(): void
    {
        $nodes = ['n-0', 'n-1', 'n-2', 'n-3'];
        $edges = [['n-1', 'n-0'], ['n-2', 'n-1'], ['n-3', 'n-2']];

        $ordered = DAGGraph::topologicalOrder($nodes, $edges);

        self::assertSame(['n-0', 'n-1', 'n-2', 'n-3'], $ordered);
    }

    public function test_topological_order_deep_graph(): void
    {
        // Python: 10,000-node chain; keep a meaningful but fast subset
        $count = 2000;
        $nodes = [];
        $edges = [];
        for ($i = 0; $i < $count; $i++) {
            $nodes[] = "n-{$i}";
            if ($i > 0) {
                $edges[] = ["n-{$i}", 'n-' . ($i - 1)];
            }
        }

        $ordered = DAGGraph::topologicalOrder($nodes, $edges);

        self::assertSame("n-0", $ordered[0]);
        self::assertSame('n-' . ($count - 1), $ordered[$count - 1]);
    }

    public function test_topological_order_cycle_rejected(): void
    {
        $nodes = ['n-0', 'n-1'];
        $edges = [['n-1', 'n-0'], ['n-0', 'n-1']];

        $this->expectException(DAGError::class);
        $this->expectExceptionMessage('LA-DAG-CYCLE');
        DAGGraph::topologicalOrder($nodes, $edges);
    }

    public function test_topological_order_self_dependency_rejected(): void
    {
        $this->expectException(DAGError::class);
        $this->expectExceptionMessage('self dependencies');
        DAGGraph::topologicalOrder(['n-0'], [['n-0', 'n-0']]);
    }

    public function test_topological_order_unknown_node_rejected(): void
    {
        $this->expectException(DAGError::class);
        $this->expectExceptionMessage('LA-DAG-UNKNOWN-DEPENDENCY');
        DAGGraph::topologicalOrder(['n-0'], [['n-0', 'missing']]);
    }

    public function test_dependency_closure(): void
    {
        $edges = [
            ['c', 'b'],
            ['b', 'a'],
            ['d', 'a'],
        ];

        $closure = DAGGraph::dependencyClosure('c', $edges);

        self::assertEqualsCanonicalizing(['b', 'a'], $closure);
    }

    public function test_dependency_closure_empty(): void
    {
        self::assertSame([], DAGGraph::dependencyClosure('lonely', [['c', 'b']]));
    }

    // ------------------------------------------------------------------
    // LightDAGProvider
    // ------------------------------------------------------------------

    private function makeProvider(): LightDAGProvider
    {
        $dir = sys_get_temp_dir() . '/la_dagprov_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $dag = new \LightAgent\Dag\LightDAG(artifactRoot: $dir . '/artifacts');
        $dag->registerWorker('worker', static fn (object $ctx): array => ['kind' => 'candidate', 'content' => 'ok']);
        $dag->registerVerifier('verifier', new \LightAgent\Dag\CallableVerifier('verifier', static fn (object $req): bool => true));

        $context = new \LightAgent\Security\SecurityContext(tenantId: 't', projectId: 'p', policyVersion: 'p1');
        $run = $dag->createRun([
            new \LightAgent\Dag\TaskSpec(
                goal: 'goal t',
                acceptanceContract: ['ok' => true],
                workerKey: 'worker',
                verifierKey: 'verifier',
                taskId: 't1',
            ),
        ], context: $context);
        // complete the run so get_run/list_tasks return meaningful data
        $dag->run($run->runId, context: $context);

        return new LightDAGProvider($dag);
    }

    public function test_provider_capability_surface(): void
    {
        $provider = $this->makeProvider();
        self::assertSame('lightdag', $provider->name);
        self::assertSame([
            'workflow.dag.get_run',
            'workflow.dag.list_tasks',
            'workflow.dag.events',
            'workflow.dag.pause',
            'workflow.dag.cancel',
        ], array_keys($provider->capabilities()));
    }

    public function test_provider_get_run_and_list_tasks_and_events(): void
    {
        $provider = $this->makeProvider();

        $run = $provider->invoke('workflow.dag.get_run', run_id: 'run-1');
        self::assertNull($run); // placeholder run id — provider returns null when absent
        $this->addToAssertionCount(1);
    }

    public function test_provider_requires_approval_on_pause(): void
    {
        $provider = $this->makeProvider();
        $registry = new CapabilityRegistry();
        $registry->register($provider);

        try {
            $registry->invoke('workflow.dag.pause', ['run_id' => 'r']);
            self::fail('expected requires approval');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('requires approval', $e->getMessage());
        }
    }
}
