<?php

declare(strict_types=1);

namespace LightAgent\Session;

/**
 * Session replay summary (session.py SessionReplay).
 */
final class SessionReplay
{
    /**
     * @param list<string> $completedTurns
     * @param list<string> $incompleteTurns
     * @param list<string> $failedTurns
     */
    public function __construct(
        public readonly string $sessionId,
        public readonly int $eventCount,
        public array $completedTurns = [],
        public array $incompleteTurns = [],
        public array $failedTurns = [],
        public readonly int $lastSequence = 0,
        public readonly ?string $lastEventType = null,
    ) {
        sort($this->completedTurns);
        sort($this->incompleteTurns);
        sort($this->failedTurns);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'session_id' => $this->sessionId,
            'event_count' => $this->eventCount,
            'completed_turns' => $this->completedTurns,
            'incomplete_turns' => $this->incompleteTurns,
            'failed_turns' => $this->failedTurns,
            'last_sequence' => $this->lastSequence,
            'last_event_type' => $this->lastEventType,
        ];
    }
}
