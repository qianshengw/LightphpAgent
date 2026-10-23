<?php

declare(strict_types=1);

namespace LightAgent\Memory;

use LightAgent\Protocol\MemoryScope;

/**
 * Append-only shared memory record (shared_memory.py SharedMemoryRecord).
 */
final class SharedMemoryRecord
{
    public string $memoryId;
    public string $createdAt;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $memory,
        public readonly string $userId,
        public array $metadata = [],
        ?string $memoryId = null,
        ?string $createdAt = null,
    ) {
        $this->memoryId = $memoryId ?? bin2hex(random_bytes(16));
        $this->createdAt = $createdAt ?? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
    }

    /**
     * Return the MemoryProtocol retrieval shape.
     *
     * @return array<string, mixed>
     */
    public function toResult(): array
    {
        return [
            'memory' => $this->memory,
            'user_id' => $this->userId,
            'metadata' => $this->metadata,
            'memory_id' => $this->memoryId,
            'created_at' => $this->createdAt,
        ];
    }
}
