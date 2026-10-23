<?php

declare(strict_types=1);

namespace LightAgent\Session;

/**
 * Compaction result (session.py CompactionResult).
 */
final class CompactionResult
{
    /**
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $spilled
     */
    public function __construct(
        public readonly array $messages,
        public readonly int $removedCount,
        public readonly ?string $summary = null,
        public readonly array $spilled = [],
    ) {
    }
}
