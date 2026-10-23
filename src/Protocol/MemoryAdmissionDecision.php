<?php

declare(strict_types=1);

namespace LightAgent\Protocol;

/**
 * Decision returned before a memory write is persisted
 * (protocol.py MemoryAdmissionDecision, frozen dataclass).
 */
final class MemoryAdmissionDecision
{
    public function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason = null,
        public readonly ?string $value = null,
    ) {
    }
}
