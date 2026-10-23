<?php

declare(strict_types=1);

namespace LightAgent\Protocol;

/**
 * Decision returned before internal memory can become prompt-injectable
 * (protocol.py MemoryPromotionDecision, frozen dataclass).
 */
final class MemoryPromotionDecision
{
    public readonly string $action;
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        string $action = MemoryPromotion::KEEP,
        public readonly ?string $reason = null,
        public readonly ?string $value = null,
        public readonly array $metadata = [],
    ) {
        $action = strtolower($action);
        if (!in_array($action, [MemoryPromotion::APPROVE, MemoryPromotion::REJECT, MemoryPromotion::REWRITE, MemoryPromotion::KEEP], true)) {
            throw new \InvalidArgumentException('MemoryPromotionDecision action must be approve, reject, rewrite, or keep');
        }
        $this->action = $action;
    }

    public function promotes(): bool
    {
        return $this->action === MemoryPromotion::APPROVE || $this->action === MemoryPromotion::REWRITE;
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public static function approve(?string $value = null, ?string $reason = null, ?array $metadata = null): self
    {
        return new self(action: MemoryPromotion::APPROVE, value: $value, reason: $reason, metadata: $metadata ?? []);
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public static function reject(?string $reason = null, ?array $metadata = null): self
    {
        return new self(action: MemoryPromotion::REJECT, reason: $reason, metadata: $metadata ?? []);
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public static function rewrite(string $value, ?string $reason = null, ?array $metadata = null): self
    {
        return new self(action: MemoryPromotion::REWRITE, value: $value, reason: $reason, metadata: $metadata ?? []);
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public static function keep(?string $reason = null, ?array $metadata = null): self
    {
        return new self(action: MemoryPromotion::KEEP, reason: $reason, metadata: $metadata ?? []);
    }
}
