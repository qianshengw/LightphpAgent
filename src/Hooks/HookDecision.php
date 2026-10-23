<?php

declare(strict_types=1);

namespace LightAgent\Hooks;

/**
 * Decision returned by a runtime hook (hooks.py HookDecision).
 */
final class HookDecision
{
    /**
     * @param array<string, mixed>|null $payload
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $action = HookAction::CONTINUE,
        public ?array $payload = null,
        public ?string $reason = null,
        public array $metadata = [],
    ) {
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public static function cont(?array $metadata = null): self
    {
        return new self(action: HookAction::CONTINUE, metadata: $metadata ?? []);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $metadata
     */
    public static function replace(array $payload, ?array $metadata = null): self
    {
        return new self(action: HookAction::REPLACE, payload: $payload, metadata: $metadata ?? []);
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public static function block(?string $reason = null, ?array $metadata = null): self
    {
        return new self(action: HookAction::BLOCK, reason: $reason, metadata: $metadata ?? []);
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public static function retry(?string $reason = null, ?array $metadata = null): self
    {
        return new self(action: HookAction::RETRY, reason: $reason, metadata: $metadata ?? []);
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public static function fallback(?string $reason = null, ?array $metadata = null): self
    {
        return new self(action: HookAction::FALLBACK, reason: $reason, metadata: $metadata ?? []);
    }
}
