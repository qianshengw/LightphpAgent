<?php

declare(strict_types=1);

namespace LightAgent\Knowledge;

use LightAgent\Capabilities\BaseCapabilityProvider;
use LightAgent\Capabilities\CapabilityRisk;
use LightAgent\Capabilities\CapabilitySpec;

/**
 * Expose LightFlow operations as capabilities (knowledge.py WorkflowProviderAdapter).
 */
final class WorkflowProviderAdapter extends BaseCapabilityProvider
{
    public function __construct(private readonly \LightAgent\Flow\LightFlow $flow)
    {
        $this->name = 'lightflow';
        $this->version = '1';
        parent::__construct([
            new CapabilitySpec('workflow.start', execute: true, cancellable: true),
            new CapabilitySpec('workflow.status', read: true),
            new CapabilitySpec('workflow.resume', execute: true, resumable: true),
            new CapabilitySpec('workflow.rerun_step', execute: true, risk: CapabilityRisk::SENSITIVE),
        ]);
    }

    public function invoke(string $capability, mixed ...$arguments): mixed
    {
        return match ($capability) {
            'workflow.start' => $this->flow->run(
                (string) ($arguments['query'] ?? $arguments[0] ?? ''),
                $arguments['options'] ?? [],
            ),
            'workflow.status' => $this->flow->getRun((string) ($arguments['run_id'] ?? $arguments[0] ?? '')),
            'workflow.resume' => $this->flow->resume(
                (string) ($arguments['run_id'] ?? $arguments[0] ?? ''),
                $arguments['options'] ?? [],
            ),
            'workflow.rerun_step' => $this->flow->rerunStep(
                (string) ($arguments['run_id'] ?? $arguments[0] ?? ''),
                (string) ($arguments['step_name'] ?? $arguments[1] ?? ''),
                $arguments['options'] ?? [],
            ),
            default => throw new \RuntimeException("unknown capability: {$capability}"),
        };
    }
}
