<?php

declare(strict_types=1);

namespace LightAgent\Guardrails;

/**
 * Built-in guardrail factories (port of guardrails.py bottom section).
 */
final class GuardrailFactories
{
    /**
     * The default privacy patterns.
     *
     * The list used to cover only the "obvious" shapes (a national id, a
     * card number, an e-mail address, a `key: value` pair). A BARE API key
     * ("sk-…"), an `Authorization: Bearer <token>` header and a Chinese
     * mobile number therefore passed through UNCHANGED, even though the
     * repository's own MCP sanitiser already redacts the first two — the
     * project was internally inconsistent (M-38).
     */
    public const DEFAULT_PRIVACY_PATTERNS = [
        '/\b\d{3}-\d{2}-\d{4}\b/i',
        '/\b(?:\d[ -]*?){13,19}\b/i',
        '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i',
        '/\b(?:api[_-]?key|access[_-]?token|auth[_-]?token|client[_-]?secret|secret|password|passwd|pwd|credential|token)s?\s*[:=]\s*\S+/i',
        // Bare API keys (OpenAI / Anthropic style, Alibaba, Stripe, GitHub,
        // Google).
        '/\bsk-[A-Za-z0-9_\-]{8,}/i',
        '/\bak_[A-Za-z0-9_\-]{8,}/i',
        '/\bpk-[A-Za-z0-9_\-]{8,}/i',
        '/\bghp_[A-Za-z0-9]{16,}/i',
        '/\bAIza[0-9A-Za-z_\-]{10,}/',
        // An Authorization header carrying a bearer token.
        '/\bBearer\s+[A-Za-z0-9\-._~+\/]{16,}=*/i',
        // A Chinese mobile number (11 digits, 1[3-9] prefix). The
        // look-arounds keep it from matching inside a longer digit run.
        '/(?<!\d)1[3-9]\d(?:[ -]?\d){8}(?!\d)/',
    ];

    private function __construct()
    {
    }

    /**
     * Return an input guardrail that blocks common private data patterns.
     *
     * @param list<string>|null $patterns
     */
    public static function privacyInput(?array $patterns = null, string $reason = 'privacy-sensitive input detected'): \Closure
    {
        $regexes = self::compile($patterns ?? self::DEFAULT_PRIVACY_PATTERNS);

        return static function (mixed $query, array $context) use ($regexes, $reason): GuardrailDecision {
            $text = $query === null ? '' : (string) $query;
            foreach ($regexes as $regex) {
                if (preg_match($regex, $text) === 1) {
                    return new GuardrailDecision(false, reason: $reason);
                }
            }
            return new GuardrailDecision(true, value: $query);
        };
    }

    /**
     * Return a tool guardrail that blocks selected tools until approved.
     *
     * @param list<string> $toolNames
     */
    public static function sensitiveToolConfirmation(array $toolNames, bool $approved = false, string $reason = 'tool call requires approval'): \Closure
    {
        // Normalize once: model-supplied tool names vary in case/whitespace
        // (M17); the block must not depend on that.
        $sensitive = array_map(static fn ($n): string => strtolower(trim((string) $n)), $toolNames);

        return static function (mixed $toolCall, array $context) use ($sensitive, $approved, $reason): GuardrailDecision {
            $name = is_array($toolCall) ? ($toolCall['tool_name'] ?? null) : null;
            $normalized = $name !== null ? strtolower(trim((string) $name)) : null;
            if ($normalized !== null && in_array($normalized, $sensitive, true) && !$approved) {
                return new GuardrailDecision(false, reason: $reason);
            }
            return new GuardrailDecision(true, value: $toolCall);
        };
    }

    /**
     * Return a tool guardrail for validating sensitive argument values.
     *
     * A rule is either a LIST of accepted values or a CALLABLE that
     * receives the value and answers whether it is acceptable. Every
     * callable shape is accepted (a Closure, an invokable object, a
     * `[$object, 'method']` pair, a function name) — the old code only
     * recognised a Closure and silently SKIPPED every other callable, so
     * the call was ALLOWED (M-39).
     *
     * A rule whose type cannot be understood FAILS CLOSED: a rule that is
     * not evaluated must never be mistaken for a rule that passed.
     *
     * @param array<string, callable|array<string>> $rules
     */
    public static function highRiskParameter(array $rules, string $reason = 'high-risk tool parameter blocked'): \Closure
    {
        return static function (mixed $toolCall, array $context) use ($rules, $reason): GuardrailDecision {
            $arguments = is_array($toolCall) ? ($toolCall['arguments'] ?? []) : [];
            $arguments = is_array($arguments) ? $arguments : [];
            foreach ($rules as $name => $rule) {
                $value = $arguments[$name] ?? null;
                if (is_array($rule) && !is_callable($rule)) {
                    // A plain list of accepted values.
                    if (!in_array($value, $rule, true)) {
                        return new GuardrailDecision(false, reason: "{$reason}: {$name}");
                    }
                    continue;
                }
                if (is_callable($rule)) {
                    if (!$rule($value)) {
                        return new GuardrailDecision(false, reason: "{$reason}: {$name}");
                    }
                    continue;
                }
                // An unknown rule type is not a rule that passed.
                return new GuardrailDecision(false, reason: "{$reason}: {$name} (unsupported rule type)");
            }
            return new GuardrailDecision(true, value: $toolCall);
        };
    }

    /**
     * Return an output guardrail that redacts common sensitive patterns.
     *
     * @param list<string>|null $patterns
     */
    public static function outputRedaction(?array $patterns = null, string $replacement = '[redacted]'): \Closure
    {
        $regexes = self::compile($patterns ?? self::DEFAULT_PRIVACY_PATTERNS);

        return static function (mixed $output, array $context) use ($regexes, $replacement): GuardrailDecision {
            $text = $output === null ? '' : (string) $output;
            foreach ($regexes as $regex) {
                $text = preg_replace($regex, $replacement, $text) ?? $text;
            }
            return new GuardrailDecision(true, value: $text);
        };
    }

    /**
     * @param list<string> $patterns
     * @return list<string>
     */
    private static function compile(array $patterns): array
    {
        return array_map(static function (string $pattern): string {
            if (preg_match('/^\/.*\/[a-zA-Z]*$/', $pattern) === 1) {
                return $pattern;
            }
            return '/' . str_replace('/', '\/', $pattern) . '/i';
        }, $patterns);
    }
}
