<?php

declare(strict_types=1);

namespace LightAgent\Session;

use LightAgent\Support\Json;
/**
 * One immutable fact in a session event log (session.py SessionEvent).
 */
final class SessionEvent
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $type,
        public readonly string $sessionId,
        public array $data = [],
        public string $eventId = '',
        public string $timestamp = '',
        public int $sequence = 0,
        public readonly int $schemaVersion = SessionConstants::SCHEMA_VERSION,
        public readonly ?string $turnId = null,
        public readonly ?string $stepId = null,
        public readonly ?string $runId = null,
        public readonly ?string $agentId = null,
        public readonly ?string $parentEventId = null,
    ) {
        if ($this->type === '') {
            throw new \InvalidArgumentException('SessionEvent.type must be a non-empty string');
        }
        if ($this->sessionId === '') {
            throw new \InvalidArgumentException('SessionEvent.session_id must not be empty');
        }
        if ($this->sequence < 0) {
            throw new \InvalidArgumentException('SessionEvent.sequence must be non-negative');
        }
        if ($this->schemaVersion < 1) {
            throw new \InvalidArgumentException('SessionEvent.schema_version must be at least 1');
        }
        $this->data = SessionConstants::jsonSafe($this->data) ?? [];
        if ($this->eventId === '') {
            $this->eventId = bin2hex(random_bytes(16));
        }
        if ($this->timestamp === '') {
            $this->timestamp = SessionConstants::utcNow();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'event_id' => $this->eventId,
            'session_id' => $this->sessionId,
            'type' => $this->type,
            // Redact at WRITE time (V4-10): the constructor scrubs the
            // payload, but `data` is a public array — a caller may mutate
            // it afterwards and the mutated payload was persisted in
            // clear text. The serialisation boundary is the last place
            // where the credential can still be removed.
            'data' => Json::decode(SessionConstants::jsonSafe($this->data) ?? []),
            'timestamp' => $this->timestamp,
            'sequence' => $this->sequence,
            'schema_version' => $this->schemaVersion,
            'turn_id' => $this->turnId,
            'step_id' => $this->stepId,
            'run_id' => $this->runId,
            'agent_id' => $this->agentId,
            'parent_event_id' => $this->parentEventId,
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromDict(array $value): self
    {
        return new self(
            type: (string) $value['type'],
            sessionId: (string) $value['session_id'],
            data: is_array($value['data'] ?? null) ? $value['data'] : [],
            eventId: (string) ($value['event_id'] ?? '') ?: bin2hex(random_bytes(16)),
            timestamp: (string) ($value['timestamp'] ?? '') ?: SessionConstants::utcNow(),
            sequence: (int) ($value['sequence'] ?? 0),
            schemaVersion: (int) ($value['schema_version'] ?? SessionConstants::SCHEMA_VERSION),
            turnId: isset($value['turn_id']) ? (string) $value['turn_id'] : null,
            stepId: isset($value['step_id']) ? (string) $value['step_id'] : null,
            runId: isset($value['run_id']) ? (string) $value['run_id'] : null,
            agentId: isset($value['agent_id']) ? (string) $value['agent_id'] : null,
            parentEventId: isset($value['parent_event_id']) ? (string) $value['parent_event_id'] : null,
        );
    }
}
