<?php

declare(strict_types=1);

namespace LightAgent\Guardrails;

use LightAgent\Support\BooleanValue;

/**
 * Runs configured guardrails while keeping the default runtime unchanged
 * (port of guardrails.py GuardrailManager).
 *
 * Guardrail callables have signature: fn(mixed $value, array $context): mixed
 * and may return GuardrailDecision, bool, string (block reason), array, or
 * any other value (treated as the new value).
 */
final class GuardrailManager
{
    /**
     * @param list<callable>|null $inputGuardrails
     * @param list<callable>|null $toolGuardrails
     * @param list<callable>|null $outputGuardrails
     */
    public function __construct(
        public readonly array $inputGuardrails = [],
        public readonly array $toolGuardrails = [],
        public readonly array $outputGuardrails = [],
    ) {
    }

    /**
     * @param array<string, mixed>|null $context
     */
    public function checkInput(string $query, ?array $context = null): GuardrailDecision
    {
        return $this->runGuardrails($this->inputGuardrails, $query, $context ?? []);
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed>|null $context
     */
    public function checkTool(string $toolName, array $arguments, ?array $context = null): GuardrailDecision
    {
        $payload = ['tool_name' => $toolName, 'arguments' => $arguments];
        return $this->runGuardrails($this->toolGuardrails, $payload, $context ?? []);
    }

    /**
     * @param array<string, mixed>|null $context
     */
    public function checkOutput(string $output, ?array $context = null): GuardrailDecision
    {
        return $this->runGuardrails($this->outputGuardrails, $output, $context ?? []);
    }

    /**
     * @param list<callable> $guardrails
     * @param array<string, mixed> $context
     */
    private function runGuardrails(array $guardrails, mixed $value, array $context): GuardrailDecision
    {
        $currentValue = $value;
        foreach ($guardrails as $guardrail) {
            $rawDecision = $guardrail($currentValue, $context);
            $decision = $this->coerceDecision($rawDecision, $currentValue);
            if (!$decision->allowed) {
                return $decision;
            }
            if ($decision->value !== null) {
                $currentValue = $decision->value;
            }
        }
        return new GuardrailDecision(allowed: true, value: $currentValue);
    }

    public static function coerceDecision(mixed $rawDecision, mixed $currentValue): GuardrailDecision
    {
        if ($rawDecision instanceof GuardrailDecision) {
            return $rawDecision;
        }
        if ($rawDecision === null || $rawDecision === true) {
            return new GuardrailDecision(allowed: true, value: $currentValue);
        }
        if ($rawDecision === false) {
            return new GuardrailDecision(allowed: false, reason: 'Guardrail blocked this operation.');
        }
        if (is_string($rawDecision)) {
            return new GuardrailDecision(allowed: false, reason: $rawDecision);
        }
        if (is_array($rawDecision)) {
            return new GuardrailDecision(
                // A guardrail that answers with an array may spell the verdict
                // as a string ('false' / 'true'). A plain (bool) cast turns
                // the string 'false' into TRUE, i.e. a "deny" becomes an
                // "allow" (T6). Parse strictly instead. A *missing* 'allowed'
                // key still means "no opinion" -> allow.
                allowed: array_key_exists('allowed', $rawDecision)
                    ? BooleanValue::parse($rawDecision['allowed'])
                    : true,
                reason: isset($rawDecision['reason']) ? (string) $rawDecision['reason'] : null,
                value: array_key_exists('value', $rawDecision) ? $rawDecision['value'] : $currentValue,
            );
        }
        return new GuardrailDecision(allowed: true, value: $rawDecision);
    }
}
