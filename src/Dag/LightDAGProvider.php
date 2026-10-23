<?php

declare(strict_types=1);

namespace LightAgent\Dag;

use LightAgent\Capabilities\BaseCapabilityProvider;
use LightAgent\Capabilities\CapabilityRisk;
use LightAgent\Capabilities\CapabilitySpec;

/**
 * Capability Provider adapter for LightDAG control and inspection
 * (port of dag/provider.py LightDAGProvider).
 */
final class LightDAGProvider extends BaseCapabilityProvider
{
    public function __construct(private readonly LightDAG $dag)
    {
        $this->name = 'lightdag';
        $this->version = '1';
        parent::__construct([
            new CapabilitySpec('workflow.dag.get_run', read: true),
            new CapabilitySpec('workflow.dag.list_tasks', read: true),
            new CapabilitySpec('workflow.dag.events', read: true),
            new CapabilitySpec(
                'workflow.dag.pause',
                write: true,
                persistent: true,
                risk: CapabilityRisk::SENSITIVE,
                requiresApproval: true,
            ),
            new CapabilitySpec(
                'workflow.dag.cancel',
                write: true,
                persistent: true,
                risk: CapabilityRisk::DESTRUCTIVE,
                requiresApproval: true,
            ),
        ]);
    }

    public function invoke(string $capability, mixed ...$arguments): mixed
    {
        $runId = (string) ($arguments['run_id'] ?? $arguments[0] ?? '');
        return match ($capability) {
            'workflow.dag.get_run' => ($value = $this->dag->getRun($runId)) !== null ? $value->toDict() : null,
            'workflow.dag.list_tasks' => array_map(
                static fn (DAGTask $task): array => $task->toDict(),
                $this->dag->listTasks($runId),
            ),
            'workflow.dag.events' => array_map(
                static fn (DAGEvent $event): array => $event->toDict(),
                $this->dag->events(
                    $runId,
                    (int) ($arguments['after'] ?? 0),
                    (int) ($arguments['limit'] ?? 100),
                ),
            ),
            'workflow.dag.pause' => $this->dag->pause($runId, $arguments['reason'] ?? null)->toDict(),
            'workflow.dag.cancel' => $this->dag->cancel($runId, $arguments['reason'] ?? null)->toDict(),
            default => throw new \RuntimeException($capability),
        };
    }
}
