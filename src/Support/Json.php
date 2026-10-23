<?php

declare(strict_types=1);

namespace LightAgent\Support;

/**
 * Safe JSON helpers.
 *
 * `json_encode()` returns **false** for input it cannot represent (invalid
 * UTF-8, INF/NAN, excessive nesting). Two patterns around that were fatal
 * or unsafe:
 *
 *   json_decode(json_encode($x), true)      // TypeError: json_decode(false)
 *   $encoded === false ? 'null' : $encoded   // every unencodable input
 *                                               // collapses to the SAME
 *                                               // value -> digest collisions
 *
 * These helpers make both explicit:
 *   - `decode()` degrades to a default instead of throwing;
 *   - `canonical()` THROWS, because a digest computed over a value that
 *     could not be encoded is not a binding at all.
 */
final class Json
{
    private const ENCODE_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    private function __construct()
    {
    }

    /**
     * Encode a value, substituting invalid UTF-8 instead of failing.
     *
     * Returns '' only for values JSON fundamentally cannot represent
     * (INF/NAN, runaway recursion) — never for bad encodings.
     */
    public static function encode(mixed $value, int $flags = 0): string
    {
        $encoded = json_encode($value, $flags | self::ENCODE_FLAGS);
        return $encoded === false ? '' : $encoded;
    }

    /**
     * Encode a value for transport to a model, a tool result or a store.
     *
     * `encode()` returns '' when the value cannot be represented at all
     * (INF/NAN, runaway recursion). A caller that forwards that '' to a
     * consumer turns an encoding failure into **silent data loss** — the
     * model would simply see an empty tool result. This variant makes the
     * failure visible instead (T5b).
     */
    public static function encodeOrError(mixed $value, int $flags = 0): string
    {
        $encoded = self::encode($value, $flags);
        if ($encoded === '') {
            return '[LA-JSON] value cannot be encoded as JSON';
        }
        return $encoded;
    }

    /**
     * Decode a value to an array, degrading instead of throwing.
     *
     * Preserves the original "round-trip through JSON" semantics (objects
     * become arrays, non-JSON types are dropped) but never throws on invalid
     * UTF-8.
     *
     * @param array<array-key, mixed> $default
     * @return array<array-key, mixed>
     */
    public static function decode(mixed $value, array $default = []): array
    {
        $encoded = json_encode($value, self::ENCODE_FLAGS);
        if ($encoded === false) {
            return $default;
        }
        $decoded = json_decode($encoded, true);
        return is_array($decoded) ? $decoded : $default;
    }

    /**
     * Canonical JSON: recursively key-sorted, like Python's
     * `json.dumps(sort_keys=True)`.
     *
     * @throws \RuntimeException when the value cannot be encoded — a digest
     *         over an unencodable value would be meaningless and would let
     *         distinct inputs collide (T5b).
     */
    public static function canonical(mixed $value): string
    {
        $encoded = json_encode(self::deepSort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new \RuntimeException(
                'value cannot be encoded as canonical JSON: ' . json_last_error_msg(),
            );
        }
        return $encoded;
    }

    /**
     * @return mixed
     */
    private static function deepSort(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::deepSort($item);
        }
        return $value;
    }
}
