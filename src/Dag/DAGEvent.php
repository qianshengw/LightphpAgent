<?php

declare(strict_types=1);

namespace LightAgent\Dag;

/**
 * Append-only DAG event (dag/models.py DAGEvent).
 *
 * @param array<string, mixed> $data
 */
final class DAGEvent
{
    public function __construct(
        public readonly string $runId,
        public readonly int $sequence,
        public readonly string $type,
        public string $eventId = '',
        public readonly ?string $taskId = null,
        public readonly ?string $attemptId = null,
        public array $data = [],
        public string $createdAt = '',
        public readonly int $schemaVersion = DAGConstants::SCHEMA_VERSION,
    ) {
        if ($this->eventId === '') {
            $this->eventId = bin2hex(random_bytes(16));
        }
        if ($this->createdAt === '') {
            $this->createdAt = DAGConstants::utcNow();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'run_id' => $this->runId,
            'sequence' => $this->sequence,
            'type' => $this->type,
            'event_id' => $this->eventId,
            'task_id' => $this->taskId,
            'attempt_id' => $this->attemptId,
            'data' => $this->data,
            'created_at' => $this->createdAt,
            'schema_version' => $this->schemaVersion,
        ];
    }
}
