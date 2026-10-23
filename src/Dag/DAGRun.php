<?php

declare(strict_types=1);

namespace LightAgent\Dag;

/**
 * DAG run aggregate (dag/models.py DAGRun).
 *
 * @param list<string> $rootTaskIds
 * @param array<string, mixed> $budgetLimits
 * @param array<string, mixed> $securityContext
 */
final class DAGRun
{
    public function __construct(
        public string $tenantId,
        public string $projectId,
        public array $rootTaskIds,
        public string $runId = '',
        public DAGRunStatus $status = DAGRunStatus::RUNNING,
        public int $graphRevision = 0,
        public int $schedulerEpoch = 0,
        public string $configDigest = '',
        public string $policyDigest = '',
        public array $budgetLimits = [],
        public array $securityContext = [],
        public string $createdAt = '',
        public string $updatedAt = '',
        public int $schemaVersion = DAGConstants::SCHEMA_VERSION,
        public ?string $blocker = null,
    ) {
        if ($this->runId === '') {
            $this->runId = bin2hex(random_bytes(16));
        }
        $now = DAGConstants::utcNow();
        if ($this->createdAt === '') {
            $this->createdAt = $now;
        }
        if ($this->updatedAt === '') {
            $this->updatedAt = $now;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'project_id' => $this->projectId,
            'root_task_ids' => $this->rootTaskIds,
            'run_id' => $this->runId,
            'status' => $this->status->value,
            'graph_revision' => $this->graphRevision,
            'scheduler_epoch' => $this->schedulerEpoch,
            'config_digest' => $this->configDigest,
            'policy_digest' => $this->policyDigest,
            'budget_limits' => $this->budgetLimits,
            'security_context' => $this->securityContext,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'schema_version' => $this->schemaVersion,
            'blocker' => $this->blocker,
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromDict(array $value): self
    {
        return new self(
            tenantId: (string) ($value['tenant_id'] ?? ''),
            projectId: (string) ($value['project_id'] ?? ''),
            rootTaskIds: is_array($value['root_task_ids'] ?? null) ? $value['root_task_ids'] : [],
            runId: (string) ($value['run_id'] ?? '') ?: bin2hex(random_bytes(16)),
            status: DAGRunStatus::from((string) ($value['status'] ?? DAGRunStatus::RUNNING->value)),
            graphRevision: (int) ($value['graph_revision'] ?? 0),
            schedulerEpoch: (int) ($value['scheduler_epoch'] ?? 0),
            configDigest: (string) ($value['config_digest'] ?? ''),
            policyDigest: (string) ($value['policy_digest'] ?? ''),
            budgetLimits: is_array($value['budget_limits'] ?? null) ? $value['budget_limits'] : [],
            securityContext: is_array($value['security_context'] ?? null) ? $value['security_context'] : [],
            createdAt: (string) ($value['created_at'] ?? '') ?: DAGConstants::utcNow(),
            updatedAt: (string) ($value['updated_at'] ?? '') ?: DAGConstants::utcNow(),
            schemaVersion: (int) ($value['schema_version'] ?? DAGConstants::SCHEMA_VERSION),
            blocker: isset($value['blocker']) ? (string) $value['blocker'] : null,
        );
    }
}
