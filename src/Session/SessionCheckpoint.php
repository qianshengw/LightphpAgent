<?php

declare(strict_types=1);

namespace LightAgent\Session;

/**
 * Session checkpoint and replay summaries (session.py SessionCheckpoint / SessionReplay).
 */
final class SessionCheckpoint
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $checkpointId,
        public readonly string $sessionId,
        public readonly int $sequence,
        public readonly ?string $label = null,
        public readonly string $createdAt = '',
        public array $metadata = [],
    ) {
        // N14: a promoted readonly property cannot be reassigned in the
        // constructor body, so the previous "if empty then assign" pattern
        // was a guaranteed fatal. The creation timestamp is filled by
        // checkpoint creators (Session::checkpoint / SessionCheckpoint::create)
        // passing it explicitly — mirroring Python's
        // field(default_factory=_utc_now) at call sites.
        $this->metadata = SessionConstants::jsonSafe($this->metadata) ?? [];
    }

    /**
     * Factory with an auto-generated creation timestamp (N14): the
     * constructor keeps plain value semantics for round-tripping, while new
     * checkpoints get utcNow() the way Python's default_factory does.
     */
    public static function create(
        string $checkpointId,
        string $sessionId,
        int $sequence,
        ?string $label = null,
        array $metadata = [],
    ): self {
        return new self(
            checkpointId: $checkpointId,
            sessionId: $sessionId,
            sequence: $sequence,
            label: $label,
            createdAt: SessionConstants::utcNow(),
            metadata: $metadata,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'checkpoint_id' => $this->checkpointId,
            'session_id' => $this->sessionId,
            'sequence' => $this->sequence,
            'label' => $this->label,
            'created_at' => $this->createdAt,
            'metadata' => $this->metadata,
        ];
    }
}
