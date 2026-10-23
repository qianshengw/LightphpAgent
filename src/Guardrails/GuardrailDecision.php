<?php

declare(strict_types=1);

namespace LightAgent\Guardrails;

/**
 * Decision returned by an input, tool, or output guardrail
 * (guardrails.py GuardrailDecision, frozen dataclass).
 */
final class GuardrailDecision
{
    public function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason = null,
        public readonly mixed $value = null,
    ) {
    }
}
