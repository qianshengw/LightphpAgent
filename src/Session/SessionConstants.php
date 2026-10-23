<?php

declare(strict_types=1);

namespace LightAgent\Session;

/**
 * Event-sourced sessions, persistence, replay, and context projection
 * (port of session.py).
 */
final class SessionConstants
{
    public const SCHEMA_VERSION = 1;

    public const SENSITIVE_KEYS = [
        'api_key', 'apikey', 'authorization', 'credential', 'credentials',
        'password', 'secret', 'token', 'access_token', 'refresh_token',
        'client_secret', 'private_key', 'secret_key', 'oauth_token',
        'passwd', 'pwd', 'auth', 'session_key', 'bearer',
    ];

    private function __construct()
    {
    }

    public static function utcNow(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
    }

    /**
     * Return a JSON-safe copy while removing common credential fields.
     */
    public static function jsonSafe(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && self::isSensitiveKey($key)) {
            return '[redacted]';
        }
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[(string) $k] = self::jsonSafe($v, (string) $k);
            }
            return $out;
        }
        if (is_object($value) && method_exists($value, 'toDict')) {
            return self::jsonSafe($value->toDict());
        }
        // Objects without a dict projection never expose raw properties:
        // only the type name is recorded (H6 — print_r leaked credentials).
        return is_object($value) ? get_class($value) : get_debug_type($value);
    }

    /**
     * Sensitive-key match with suffix awareness: `user_api_key`,
     * `openai_token`, `db_password` all redact; ordinary words do not.
     *
     * The key is normalised to snake_case FIRST, because the credential
     * spellings that actually occur in payloads are camelCase
     * (`accessToken`, `dbPassword`, `clientSecret`, `authToken`,
     * `openaiToken`, `userSecret`). Matching the raw spelling only
     * recognised `api_key`/`access_token`/`password`, so every camelCase
     * credential was written to the session log IN CLEAR TEXT.
     */
    public static function isSensitiveKey(string $key): bool
    {
        $normalized = self::normalizeKey($key);
        foreach (self::SENSITIVE_KEYS as $candidate) {
            if ($normalized === $candidate) {
                return true;
            }
            if (str_ends_with($normalized, '_' . $candidate) || str_starts_with($normalized, $candidate . '_')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalise a key to the snake_case spelling the sensitive-key list
     * uses.
     *
     *   - `accessToken`  -> `access_Token` -> `access_token`
     *   - `ApiKey`       -> `Api_Key`     -> `api_key`
     *   - `APIKey`       -> `API_Key`     -> `api_key`
     *   - `Api-Key`      -> `Api_Key`     -> `api_key`
     *   - `api key`      -> `api_key`
     *
     * Ordinary words are untouched by the camel split (`user`, `session`,
     * `tokenizer`), so they keep NOT matching the sensitive-key list.
     */
    private static function normalizeKey(string $key): string
    {
        // A lower-case letter or digit followed by an upper-case letter is
        // a word boundary: `accessToken` -> `access_Token`.
        $spaced = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key) ?? $key;
        // A run of capitals followed by a capitalised word is a boundary
        // too: `APIKey` -> `API_Key`.
        $spaced = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $spaced) ?? $spaced;
        return strtolower(str_replace(['-', ' '], '_', $spaced));
    }
}
