<?php

declare(strict_types=1);

namespace LightAgent\Support;

/**
 * Strict boolean parsing for values that arrive from outside the framework.
 *
 * PHP's `(bool)` cast is not usable for this: `(bool) 'false'` is **true**
 * (every non-empty string is truthy). A policy / guardrail / approval
 * callback that answers with a JSON-decoded payload therefore hands the
 * framework the *string* `'false'`, and a naive cast silently turns a
 * "deny" into an "allow".
 *
 * This helper accepts only an explicit, unambiguous set of spellings and
 * treats everything else as **false** (fail-closed). Callers decide what a
 * *missing* key means by branching on `array_key_exists()` themselves — the
 * default of a missing key is a per-callsite policy decision, not something
 * this helper should guess.
 */
final class BooleanValue
{
    /** @var list<string> */
    private const TRUE_SPELLINGS = ['1', 'true', 'yes', 'on'];

    private function __construct()
    {
    }

    /**
     * Parse a value that is expected to be boolean.
     *
     * Accepted as true:  `true`, `1`, `'1'`, `'true'`, `'TRUE'`, `' yes '`, `'on'`
     * Accepted as false: `false`, `0`, `0.0`, `'0'`, `'false'`, `'no'`, `'off'`, `''`, `null`
     * Anything else (arrays, objects, unknown strings) is **false** — a value
     * that cannot be understood must not grant access.
     */
    public static function parse(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null) {
            return false;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_float($value)) {
            return $value !== 0.0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), self::TRUE_SPELLINGS, true);
        }
        // Arrays / objects / resources cannot express a boolean decision.
        return false;
    }
}
