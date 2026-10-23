<?php

declare(strict_types=1);

namespace LightAgent\Session;

use LightAgent\Support\Json;
/**
 * Append-only session state reconstructed from versioned events
 * (session.py Session).
 */
final class Session
{
    /**
     * @param list<SessionEvent> $events
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $sessionId = '',
        public array $metadata = [],
        public array $events = [],
        public string $createdAt = '',
        public string $updatedAt = '',
        public int $schemaVersion = SessionConstants::SCHEMA_VERSION,
    ) {
        if ($this->sessionId === '') {
            $this->sessionId = bin2hex(random_bytes(16));
        }
        $this->metadata = SessionConstants::jsonSafe($this->metadata) ?? [];
        usort($this->events, static fn (SessionEvent $a, SessionEvent $b): int => $a->sequence <=> $b->sequence);
        $this->validate();
    }

    public function nextSequence(): int
    {
        $last = end($this->events);
        return $last === false ? 1 : $last->sequence + 1;
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public function append(
        string $eventType,
        ?array $data = null,
        ?string $turnId = null,
        ?string $stepId = null,
        ?string $runId = null,
        ?string $agentId = null,
        ?string $parentEventId = null,
    ): SessionEvent {
        $event = new SessionEvent(
            type: $eventType,
            sessionId: $this->sessionId,
            data: $data ?? [],
            sequence: $this->nextSequence(),
            schemaVersion: $this->schemaVersion,
            turnId: $turnId,
            stepId: $stepId,
            runId: $runId,
            agentId: $agentId,
            parentEventId: $parentEventId,
        );
        $this->events[] = $event;
        $this->updatedAt = $event->timestamp;
        // Redact at WRITE time (V4-10): the payload is scrubbed when the
        // event is created, but `data` is mutable — scrub it again here so
        // a payload that was changed after the event was built cannot
        // reach the store with a credential in clear text.
        $event->data = SessionConstants::jsonSafe($event->data) ?? [];
        return $event;
    }

    public function appendEvent(SessionEvent $event): SessionEvent
    {
        if ($event->sessionId !== $this->sessionId) {
            throw new \InvalidArgumentException('event session_id does not match Session');
        }
        $expected = $this->nextSequence();
        if ($event->sequence === 0) {
            $event->sequence = $expected;
        }
        if ($event->sequence !== $expected) {
            throw new \InvalidArgumentException("event sequence must be {$expected}, got {$event->sequence}");
        }
        foreach ($this->events as $existing) {
            if ($existing->eventId === $event->eventId) {
                throw new \InvalidArgumentException("duplicate event_id: {$event->eventId}");
            }
        }
        $this->events[] = $event;
        $this->updatedAt = $event->timestamp;
        // Redact at WRITE time (V4-10): an event that was built elsewhere
        // may carry a payload that was mutated after its construction.
        $event->data = SessionConstants::jsonSafe($event->data) ?? [];
        return $event;
    }

    /**
     * @return list<SessionEvent>
     */
    public function page(int $after = 0, int $limit = 100): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('limit must be at least 1');
        }
        $out = [];
        foreach ($this->events as $event) {
            if ($event->sequence > $after) {
                $out[] = $event;
                if (count($out) >= $limit) {
                    break;
                }
            }
        }
        return $out;
    }

    public function checkpoint(?string $label = null, ?array $metadata = null): SessionCheckpoint
    {
        $last = end($this->events);
        $checkpoint = SessionCheckpoint::create(
            checkpointId: bin2hex(random_bytes(16)),
            sessionId: $this->sessionId,
            sequence: $last === false ? 0 : $last->sequence,
            label: $label,
            metadata: $metadata ?? [],
        );
        $this->append('session.checkpointed', ['checkpoint' => $checkpoint->toDict()]);
        return $checkpoint;
    }

    public function fork(
        ?string $newSessionId = null,
        ?int $throughSequence = null,
        ?array $metadata = null,
    ): self {
        $last = end($this->events);
        $lastSeq = $last === false ? 0 : $last->sequence;
        $boundary = $throughSequence ?? $lastSeq;
        if ($boundary < 0 || $boundary > $lastSeq) {
            throw new \InvalidArgumentException('through_sequence is outside the session event range');
        }
        $forked = new self(
            sessionId: $newSessionId ?? bin2hex(random_bytes(16)),
            metadata: array_merge(
                Json::decode($this->metadata) ?? [],
                $metadata ?? [],
                ['forked_from' => $this->sessionId, 'forked_at_sequence' => $boundary],
            ),
        );
        $forked->append('session.started', ['forked_from' => $this->sessionId, 'through_sequence' => $boundary]);
        foreach ($this->events as $event) {
            if ($event->sequence > $boundary) {
                break;
            }
            $forked->append(
                $event->type,
                array_merge(
                    Json::decode($event->data) ?? [],
                    [
                        'source_session_id' => $this->sessionId,
                        'source_event_id' => $event->eventId,
                        'source_sequence' => $event->sequence,
                    ],
                ),
                turnId: $event->turnId,
                stepId: $event->stepId,
                runId: $event->runId,
                agentId: $event->agentId,
                parentEventId: $event->eventId,
            );
        }
        $forked->append('session.forked', ['source_session_id' => $this->sessionId, 'through_sequence' => $boundary]);
        return $forked;
    }

    public function replay(): SessionReplay
    {
        $started = [];
        $completed = [];
        $failed = [];
        foreach ($this->events as $event) {
            if ($event->turnId === null) {
                continue;
            }
            if ($event->type === 'turn.started') {
                $started[] = $event->turnId;
            } elseif ($event->type === 'turn.completed') {
                $completed[] = $event->turnId;
            } elseif ($event->type === 'turn.failed') {
                $failed[] = $event->turnId;
            }
        }
        $last = end($this->events);
        return new SessionReplay(
            sessionId: $this->sessionId,
            eventCount: count($this->events),
            completedTurns: array_values(array_unique($completed)),
            incompleteTurns: array_values(array_unique(array_diff($started, $completed, $failed))),
            failedTurns: array_values(array_unique($failed)),
            lastSequence: $last === false ? 0 : $last->sequence,
            lastEventType: $last === false ? null : $last->type,
        );
    }

    public function validate(): void
    {
        $seenIds = [];
        $expected = 1;
        foreach ($this->events as $event) {
            if ($event->sessionId !== $this->sessionId) {
                throw new \InvalidArgumentException('session contains an event for another session');
            }
            if (in_array($event->eventId, $seenIds, true)) {
                throw new \InvalidArgumentException("duplicate event_id: {$event->eventId}");
            }
            if ($event->sequence !== $expected) {
                throw new \InvalidArgumentException("event sequence gap: expected {$expected}, got {$event->sequence}");
            }
            if ($event->schemaVersion > SessionConstants::SCHEMA_VERSION) {
                throw new \InvalidArgumentException(
                    'unsupported SessionEvent schema_version=' . $event->schemaVersion
                    . '; maximum supported=' . SessionConstants::SCHEMA_VERSION,
                );
            }
            $seenIds[] = $event->eventId;
            $expected++;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'session_id' => $this->sessionId,
            // Redact at WRITE time (V4-10): the metadata is scrubbed in the
            // constructor, but it is a public array and may be mutated
            // afterwards — the serialisation boundary is the last chance to
            // remove a credential.
            'metadata' => Json::decode(SessionConstants::jsonSafe($this->metadata) ?? []),
            'events' => array_map(static fn (SessionEvent $e): array => $e->toDict(), $this->events),
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'schema_version' => $this->schemaVersion,
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromDict(array $value): self
    {
        $migrated = (new SessionMigrationRegistry())->migrate($value);
        $events = [];
        foreach ($migrated['events'] ?? [] as $event) {
            if (is_array($event)) {
                $events[] = SessionEvent::fromDict($event);
            }
        }
        return new self(
            sessionId: (string) $migrated['session_id'],
            metadata: is_array($migrated['metadata'] ?? null) ? $migrated['metadata'] : [],
            events: $events,
            createdAt: (string) ($migrated['created_at'] ?? '') ?: SessionConstants::utcNow(),
            updatedAt: (string) ($migrated['updated_at'] ?? '') ?: SessionConstants::utcNow(),
            schemaVersion: (int) ($migrated['schema_version'] ?? SessionConstants::SCHEMA_VERSION),
        );
    }

    /**
     * Produce a copy of this session bound to a NEW session id (M24): every
     * event is rebuilt against the new id and event ids are regenerated, so
     * the copy persists cleanly. `preserveEventIds` keeps the original event
     * ids (used when a store supports scoped uniqueness), which is why the
     * default rebinds — the old ids belong to the source session.
     */
    public function copyWithSessionId(string $newSessionId, bool $preserveEventIds = false): self
    {
        $events = [];
        foreach ($this->events as $event) {
            $dict = $event->toDict();
            $dict['session_id'] = $newSessionId;
            if (!$preserveEventIds) {
                $dict['event_id'] = bin2hex(random_bytes(16));
            }
            $events[] = SessionEvent::fromDict($dict);
        }
        return new self(
            sessionId: $newSessionId,
            metadata: $this->metadata,
            events: $events,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
            schemaVersion: $this->schemaVersion,
        );
    }
}
