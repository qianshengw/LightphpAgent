<?php

declare(strict_types=1);

namespace LightAgent\Dag;

use LightAgent\Security\SecurityUtils;

/**
 * Task specification (dag/models.py TaskSpec, frozen dataclass).
 *
 * @param array<string, mixed> $metadata
 */
final class TaskSpec
{
    /**
     * @param list<string>|array<int, string> $dependsOn
     * @param list<string>|array<int, string> $resourceRequirements
     * @param array<string, mixed> $metadata
     */
    /**
     * Auto-generated when omitted (R11): stored as a plain readonly property
     * assigned once in the constructor — a promoted readonly parameter can
     * never be filled in after construction, which made every no-taskId
     * construction (and fromDict without task_id) a fatal error.
     * (Python parity: task_id: str = field(default_factory=...).)
     */
    public readonly string $taskId;

    public function __construct(
        public readonly string $goal,
        public readonly mixed $acceptanceContract = null,
        public readonly string $workerKey = '',
        public readonly string $verifierKey = '',
        string $taskId = '',
        public readonly ?string $decomposerKey = null,
        public array $dependsOn = [],
        public array $resourceRequirements = [],
        public readonly ?string $createdByTaskId = null,
        public readonly ?string $supersedesTaskId = null,
        public readonly int $priority = 0,
        public array $metadata = [],
        public string $contractHash = '',
        public readonly int $schemaVersion = DAGConstants::SCHEMA_VERSION,
    ) {
        if (trim($this->goal) === '') {
            throw new \InvalidArgumentException('TaskSpec.goal must not be empty');
        }
        if (trim($this->workerKey) === '' || trim($this->verifierKey) === '') {
            throw new \InvalidArgumentException('TaskSpec worker_key and verifier_key must not be empty');
        }
        $this->taskId = $taskId !== '' ? $taskId : bin2hex(random_bytes(16));
        if (in_array($this->taskId, $this->dependsOn, true)) {
            throw new \InvalidArgumentException('a task cannot depend on itself');
        }
        // dedupe preserving order (Python dict.fromkeys)
        $this->dependsOn = array_values(array_unique($this->dependsOn));
        $this->resourceRequirements = array_values(array_unique($this->resourceRequirements));
        $expectedContractHash = SecurityUtils::canonicalDigest($this->acceptanceContract);
        if ($this->contractHash !== '' && $this->contractHash !== $expectedContractHash) {
            throw new \InvalidArgumentException('TaskSpec.contract_hash does not match acceptance_contract');
        }
        $this->contractHash = $expectedContractHash;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'goal' => $this->goal,
            'acceptance_contract' => $this->acceptanceContract,
            'worker_key' => $this->workerKey,
            'verifier_key' => $this->verifierKey,
            'task_id' => $this->taskId,
            'decomposer_key' => $this->decomposerKey,
            'depends_on' => $this->dependsOn,
            'resource_requirements' => $this->resourceRequirements,
            'created_by_task_id' => $this->createdByTaskId,
            'supersedes_task_id' => $this->supersedesTaskId,
            'priority' => $this->priority,
            'metadata' => $this->metadata,
            'contract_hash' => $this->contractHash,
            'schema_version' => $this->schemaVersion,
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromDict(array $value): self
    {
        return new self(
            goal: (string) ($value['goal'] ?? ''),
            acceptanceContract: $value['acceptance_contract'] ?? null,
            workerKey: (string) ($value['worker_key'] ?? ''),
            verifierKey: (string) ($value['verifier_key'] ?? ''),
            taskId: (string) ($value['task_id'] ?? ''),
            decomposerKey: isset($value['decomposer_key']) ? (string) $value['decomposer_key'] : null,
            dependsOn: is_array($value['depends_on'] ?? null) ? array_values($value['depends_on']) : [],
            resourceRequirements: is_array($value['resource_requirements'] ?? null) ? array_values($value['resource_requirements']) : [],
            createdByTaskId: isset($value['created_by_task_id']) ? (string) $value['created_by_task_id'] : null,
            supersedesTaskId: isset($value['supersedes_task_id']) ? (string) $value['supersedes_task_id'] : null,
            priority: (int) ($value['priority'] ?? 0),
            metadata: is_array($value['metadata'] ?? null) ? $value['metadata'] : [],
            contractHash: (string) ($value['contract_hash'] ?? ''),
            schemaVersion: (int) ($value['schema_version'] ?? DAGConstants::SCHEMA_VERSION),
        );
    }
}
