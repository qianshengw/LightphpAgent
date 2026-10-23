<?php

declare(strict_types=1);

namespace LightAgent\Security;

use LightAgent\Capabilities\CapabilityRegistry;
use LightAgent\Capabilities\CapabilityRisk;
use LightAgent\Capabilities\CapabilitySpec;
use LightAgent\Capabilities\PolicyDecision;
use LightAgent\Capabilities\PolicyEngine;
use LightAgent\Capabilities\PolicyRequest;
use LightAgent\Capabilities\PermissionSet;
use LightAgent\Capabilities\RuntimeContext;

/**
 * Identity, canonical digests, and security primitives
 * (port of security.py).
 */
final class SecurityUtils
{
    /**
     * Schema 2 adds the explicit `unrestricted` flag to the permission
     * snapshot, so "empty allowlist" can finally mean "deny everything".
     * Schema 1 payloads have no such flag and are still loaded with the
     * legacy meaning (an empty allowlist = unrestricted).
     */
    public const SECURITY_CONTEXT_SCHEMA_VERSION = 2;
    public const APPROVAL_TOKEN_SCHEMA_VERSION = 1;
    public const PROVIDER_MANIFEST_SCHEMA_VERSION = 1;

    private function __construct()
    {
    }

    public static function utcNow(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
    }

    /**
     * @return \DateTimeImmutable
     */
    public static function parseUtc(string $value): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException("invalid UTC timestamp: {$value}");
        }
    }

    /**
     * Resolve a deadline into the single absolute instant it denotes.
     *
     * A RELATIVE deadline ("+5 minutes", "now", "tomorrow") is a moving
     * target: every re-parse of the same string yields a LATER instant. That
     * is harmless for a plain timestamp, but it breaks the two places where a
     * deadline is compared against another deadline:
     *
     *  - `SecurityContext::narrow()` compares the child's deadline with the
     *    parent's. A parent parsed at T1 and a child parsed at T2 > T1 made
     *    the child look like an extension, so a child could never inherit a
     *    relative deadline (V5-10).
     *  - `SecurityContext::isExpired()` re-parsed the string on every call,
     *    so a relative deadline was never reached (V5-11).
     *
     * Resolving ONCE — at construction — turns the deadline into a fixed
     * instant that every later comparison can use. An ABSOLUTE deadline
     * keeps the caller's exact spelling, so a round trip through
     * `toDict()` / `fromDict()` is byte-identical to what it was before.
     *
     * @throws \InvalidArgumentException when the value is not a timestamp
     */
    public static function resolveDeadline(string $value): string
    {
        $parsed = self::parseUtc($value);
        if (self::isAbsoluteDeadline($value)) {
            return $value;
        }
        return $parsed->format(\DateTimeInterface::ATOM);
    }

    /**
     * True when the value already denotes a fixed point in time (it carries
     * a date component). Everything else — "+5 minutes", "now", "5 minutes
     * ago" — is relative to the current clock and therefore has to be
     * resolved before it can be compared with another deadline.
     */
    public static function isAbsoluteDeadline(string $value): bool
    {
        $parsed = date_parse($value);
        if (!is_array($parsed)) {
            return false;
        }
        // A relative component ("+5 minutes") always wins: even
        // "2026-01-01 +5 minutes" moves with the clock.
        if (!empty($parsed['relative'])) {
            return false;
        }
        return !empty($parsed['year']) && !empty($parsed['month']) && !empty($parsed['day']);
    }

    /**
     * Canonical JSON digest (security.py canonical_digest).
     *
     * @param mixed $value
     */
    public static function canonicalDigest(mixed $value): string
    {
        return hash('sha256', self::canonicalJson($value));
    }

    /**
     * @param mixed $value
     *
     * @throws \RuntimeException when the value cannot be encoded. Returning a
     *         fixed literal here would give every unencodable input the SAME
     *         digest, so a token issued for one payload would verify against
     *         a different one (T5b).
     */
    public static function canonicalJson(mixed $value): string
    {
        // Python json.dumps(sort_keys=True) equivalent: recursively ksort arrays.
        return \LightAgent\Support\Json::canonical($value);
    }

    /**
     * Replace secret-ish values with digest receipts (security.py _secret_safe).
     *
     * @param mixed $value
     * @return mixed
     */
    public static function secretSafe(mixed $value): mixed
    {
        if (is_array($value) || $value instanceof \Traversable) {
            $out = [];
            foreach ($value as $key => $item) {
                $name = strtolower((string) $key);
                if (self::strContainsAny($name, ['key', 'token', 'secret', 'password', 'credential'])) {
                    $out[(string) $key] = ['secret_digest' => self::canonicalDigest($item)];
                } else {
                    $out[(string) $key] = self::secretSafe($item);
                }
            }
            return $out;
        }
        return $value;
    }

    private static function strContainsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }
}

