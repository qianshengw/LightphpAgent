<?php

declare(strict_types=1);

namespace LightAgent\Protocol;

use LightAgent\Support\BooleanValue;

/**
 * Optional safety policy for shared memory backends
 * (protocol.py MemoryPolicy, frozen dataclass with __post_init__ normalization).
 *
 * Callback fields become PHP callables:
 *   - memoryWriteAdmission:    fn(string $candidate, array $context): mixed
 *   - memoryPromotionAdmission: fn(MemoryCandidate $c, array $context): mixed
 */
final class MemoryPolicy
{
    /**
     * @param list<string>|null $allowedSources
     * @param list<string>|null $allowedScopes
     * @param list<string>|null $allowedAgentNames
     * @param list<string>|null $allowedTrustLevels
     * @param list<string>|null $rejectWritePatterns
     * @param (callable(string, array<string, mixed>): mixed)|null $memoryWriteAdmission
     * @param (callable(MemoryCandidate, array<string, mixed>): mixed)|null $memoryPromotionAdmission
     */
    public function __construct(
        public readonly ?string $namespace = null,
        public readonly bool $allowUnattributedResults = true,
        public readonly ?array $allowedSources = null,
        public readonly ?array $allowedScopes = null,
        public readonly ?array $allowedAgentNames = null,
        public readonly ?array $allowedTrustLevels = null,
        public readonly ?float $minConfidence = null,
        public readonly bool $enforceExpiresAt = false,
        public readonly ?\Closure $memoryWriteAdmission = null,
        public readonly ?int $maxWritesPerRun = null,
        public readonly bool $rejectDuplicateWrites = false,
        public readonly ?int $minWriteLength = null,
        public readonly ?array $rejectWritePatterns = null,
        public readonly ?\Closure $memoryPromotionAdmission = null,
        public readonly bool $requirePromotionForInternalMemory = true,
        public readonly bool $requireWriteAdmission = false,
    ) {
        if ($this->maxWritesPerRun !== null && $this->maxWritesPerRun < 0) {
            throw new \InvalidArgumentException('max_writes_per_run must be greater than or equal to 0');
        }
        if ($this->minWriteLength !== null && $this->minWriteLength < 0) {
            throw new \InvalidArgumentException('min_write_length must be greater than or equal to 0');
        }
    }

    /**
     * The namespaced (scoped) user id used by the memory backends.
     *
     * The agent scopes BOTH the read and the write side with this id, so
     * a record stored under the RAW id (`alice`) is NOT found by a
     * namespaced read (`tenant-a:alice`). Use this method on both sides:
     *
     *     $scoped = $policy->scopedUserId($userId);
     *     $pool->store($data, $scoped);
     *     $pool->retrieve($query, $scoped);
     */
    public function scopedUserId(string $userId): string
    {
        $user = (string) $userId;
        if ($this->namespace === null || $this->namespace === '') {
            return $user;
        }
        return "{$this->namespace}:{$user}";
    }

    /**
     * Return whether a retrieved memory item can be injected into context.
     *
     * @param array<string, mixed> $item
     */
    public function allowsResult(mixed $item, string $scopedUserId, string $originalUserId): bool
    {
        if (!is_array($item)) {
            return $this->allowUnattributedResults;
        }

        $metadata = isset($item['metadata']) && is_array($item['metadata']) ? $item['metadata'] : [];
        $itemUserId = $item['user_id'] ?? $item['userId'] ?? $metadata['user_id'] ?? $metadata['userId'] ?? null;

        if ($this->blocksPromptInjection($item, $metadata)) {
            return false;
        }

        if ($itemUserId === null) {
            if (!$this->allowUnattributedResults) {
                return false;
            }
            // Unattributed records still pass through every content filter
            // (H5): only the OWNERSHIP check is skipped.
            return $this->allowsValue($item, $metadata, ['source', 'memory_source'], $this->allowedSources)
                && $this->allowsValue($item, $metadata, ['scope', 'memory_scope'], $this->allowedScopes)
                && $this->allowsValue($item, $metadata, ['agent_name', 'agent'], $this->allowedAgentNames)
                && $this->allowsValue($item, $metadata, ['trust_level', 'trust'], $this->allowedTrustLevels)
                && $this->allowsConfidence($item, $metadata)
                && $this->allowsNotExpired($item, $metadata);
        }

        $allowed = [(string) $originalUserId, (string) $scopedUserId];
        if (!in_array((string) $itemUserId, $allowed, true)) {
            return false;
        }

        return $this->allowsValue($item, $metadata, ['source', 'memory_source'], $this->allowedSources)
            && $this->allowsValue($item, $metadata, ['scope', 'memory_scope'], $this->allowedScopes)
            && $this->allowsValue($item, $metadata, ['agent_name', 'agent'], $this->allowedAgentNames)
            && $this->allowsValue($item, $metadata, ['trust_level', 'trust'], $this->allowedTrustLevels)
            && $this->allowsConfidence($item, $metadata)
            && $this->allowsNotExpired($item, $metadata);
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $metadata
     * @param list<string> $names
     * @param list<string>|null $allowedValues
     */
    private function allowsValue(array $item, array $metadata, array $names, ?array $allowedValues): bool
    {
        if ($allowedValues === null) {
            return true;
        }
        $value = self::getValue($item, $metadata, $names);
        if ($value === null) {
            return false;
        }
        return in_array((string) $value, array_map('strval', $allowedValues), true);
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $metadata
     * @param list<string> $names
     */
    private static function getValue(array $item, array $metadata, array $names): mixed
    {
        foreach ($names as $name) {
            if (array_key_exists($name, $item)) {
                return $item[$name];
            }
            if (array_key_exists($name, $metadata)) {
                return $metadata[$name];
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $metadata
     */
    private function allowsConfidence(array $item, array $metadata): bool
    {
        if ($this->minConfidence === null) {
            return true;
        }
        $value = self::getValue($item, $metadata, ['confidence', 'score', 'trust_score']);
        if ($value === null) {
            return false;
        }
        if (!is_numeric($value)) {
            return false;
        }
        return (float) $value >= $this->minConfidence;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $metadata
     */
    private function allowsNotExpired(array $item, array $metadata): bool
    {
        $expiresAt = self::getValue($item, $metadata, ['expires_at', 'expiresAt']);
        if ($expiresAt === null) {
            return !$this->enforceExpiresAt;
        }
        try {
            if (is_int($expiresAt) || is_float($expiresAt)) {
                $expires = new \DateTimeImmutable('@' . (string) (float) $expiresAt);
            } else {
                $expires = new \DateTimeImmutable(str_replace('Z', '+00:00', (string) $expiresAt));
                // Python: naive datetimes are assumed UTC
                if ($expires->getTimezone()->getName() === 'UTC' && !str_contains((string) $expiresAt, '+') && !str_ends_with((string) $expiresAt, 'Z')) {
                    // already UTC via @ or explicit offset; keep
                }
            }
        } catch (\Exception) {
            return false;
        }
        return $expires > new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $metadata
     */
    private function blocksPromptInjection(array $item, array $metadata): bool
    {
        $injectable = self::getValue($item, $metadata, ['injectable', 'prompt_injectable', 'is_injectable']);
        if ($injectable !== null && !self::truthy($injectable)) {
            return true;
        }

        $status = self::getValue($item, $metadata, ['promotion_status']);
        $normalizedStatus = $status !== null ? strtolower((string) $status) : null;
        if ($normalizedStatus !== null && in_array($normalizedStatus, [
            'candidate', 'pending', 'requires_review', 'review_required',
            'kept', 'non_injectable', 'rejected', 'blocked',
        ], true)) {
            return true;
        }

        if (!$this->requirePromotionForInternalMemory) {
            return false;
        }

        $source = self::getValue($item, $metadata, ['source', 'memory_source']);
        $scope = self::getValue($item, $metadata, ['scope', 'memory_scope']);
        $internalSource = $source !== null && in_array(strtolower((string) $source), [
            'reflection', 'delegation', 'self_learning', 'agent', 'derived', 'swarm', 'trace', 'tool',
        ], true);
        $internalScope = $scope !== null && in_array(strtolower((string) $scope), [
            'agent', 'delegation', 'shared', 'internal', 'swarm', 'flow',
        ], true);
        if (!$internalSource && !$internalScope) {
            return false;
        }

        if ($normalizedStatus !== null && in_array($normalizedStatus, ['promoted', 'approved'], true)) {
            return false;
        }
        // Internal memory is injectable only via an explicit promotion
        // decision (M13): a self-declared injectable=true flag is not
        // sufficient evidence.
        return true;
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }
        return in_array(trim(strtolower((string) $value)), ['1', 'true', 'yes', 'y', 'on', 'promoted', 'approved'], true);
    }

    /**
     * Return whether a candidate memory write should be persisted.
     *
     * `rejectDuplicateWrites` needs the fingerprints of the recent writes.
     * When they are not supplied the check CANNOT be performed, so the
     * write is refused with an explicit reason instead of silently
     * bypassing the configured policy (V5-45).
     *
     * @param array<string, mixed>|null $context
     * @param set<string>|null $recentFingerprints
     */
    public function allowsWrite(
        string $data,
        ?array $context = null,
        int $writeCount = 0,
        ?array $recentFingerprints = null,
    ): MemoryAdmissionDecision {
        $context ??= [];
        $candidate = (string) $data;

        if ($this->maxWritesPerRun !== null && $writeCount >= $this->maxWritesPerRun) {
            return new MemoryAdmissionDecision(
                allowed: false,
                reason: "Memory write limit exceeded: max_writes_per_run={$this->maxWritesPerRun}",
            );
        }

        if ($this->minWriteLength !== null && mb_strlen(trim($candidate)) < $this->minWriteLength) {
            return new MemoryAdmissionDecision(
                allowed: false,
                reason: "Memory write is shorter than min_write_length={$this->minWriteLength}",
            );
        }

        foreach ($this->rejectWritePatterns ?? [] as $pattern) {
            // @: an invalid pattern emits a PHP warning; the false return is
            // handled explicitly and fails closed (M14).
            $match = @preg_match(self::normalizePattern((string) $pattern), $candidate);
            if ($match === false || $match === 1) {
                return new MemoryAdmissionDecision(
                    allowed: false,
                    reason: $match === false
                        ? "Memory write pattern is invalid, rejecting (fail-closed): {$pattern}"
                        : "Memory write rejected by pattern: {$pattern}",
                );
            }
        }

        $fingerprint = self::writeFingerprint($candidate, $context);
        if ($this->rejectDuplicateWrites) {
            if ($recentFingerprints === null) {
                // The duplicate check needs the fingerprints of the recent
                // writes; without them the policy CANNOT be enforced. A
                // configured policy must not be silently skipped (V5-45):
                // the write is refused with an explicit reason instead.
                return new MemoryAdmissionDecision(
                    allowed: false,
                    reason: 'Duplicate memory write check is unavailable: '
                        . 'no recent fingerprints were supplied.',
                );
            }
            if (isset($recentFingerprints[$fingerprint])) {
                return new MemoryAdmissionDecision(allowed: false, reason: 'Duplicate memory write blocked.');
            }
        }

        if ($this->memoryWriteAdmission === null) {
            if ($this->requireWriteAdmission) {
                return new MemoryAdmissionDecision(
                    allowed: false,
                    reason: 'Memory write requires an explicit admission decision.',
                );
            }
            return new MemoryAdmissionDecision(allowed: true, value: $candidate);
        }

        try {
            $rawDecision = ($this->memoryWriteAdmission)($candidate, $context);
        } catch (\Throwable $exc) {
            return new MemoryAdmissionDecision(
                allowed: false,
                reason: 'Memory write admission failed: ' . $exc::class,
            );
        }
        if ($this->requireWriteAdmission && $rawDecision === null) {
            return new MemoryAdmissionDecision(
                allowed: false,
                reason: 'Memory write admission did not explicitly approve this write.',
            );
        }
        return $this->coerceWriteDecision($rawDecision, $candidate);
    }

    /**
     * Return whether a non-injectable candidate can be promoted.
     *
     * @param array<string, mixed>|null $context
     */
    public function allowsPromotion(MemoryCandidate $candidate, ?array $context = null): MemoryPromotionDecision
    {
        if ($this->memoryPromotionAdmission === null) {
            return MemoryPromotionDecision::keep('Memory promotion requires explicit approval.');
        }
        try {
            $rawDecision = ($this->memoryPromotionAdmission)($candidate, $context ?? []);
        } catch (\Throwable $exc) {
            return MemoryPromotionDecision::keep(
                'Memory promotion admission failed: ' . $exc::class,
                metadata: ['admission_error_type' => $exc::class],
            );
        }
        return $this->coercePromotionDecision($rawDecision, $candidate->data);
    }

    /**
     * Build a lightweight duplicate key for a candidate memory write.
     *
     * @param array<string, mixed>|null $context
     */
    public static function writeFingerprint(string $data, ?array $context = null): string
    {
        $context ??= [];
        $normalized = preg_replace('/\s+/', ' ', mb_strtolower(trim($data))) ?? '';
        $scopeKey = implode('|', array_map(
            static fn (string $key): string => (string) ($context[$key] ?? ''),
            ['memory_user_id', 'source', 'scope', 'agent_name'],
        ));
        return "{$scopeKey}|{$normalized}";
    }

    /**
     * @param array<string, mixed> $rawDecision
     */
    private function coerceWriteDecision(mixed $rawDecision, string $currentValue): MemoryAdmissionDecision
    {
        if ($rawDecision instanceof MemoryAdmissionDecision) {
            return $rawDecision;
        }
        if ($rawDecision === null || $rawDecision === true) {
            return new MemoryAdmissionDecision(allowed: true, value: $currentValue);
        }
        if ($rawDecision === false) {
            return new MemoryAdmissionDecision(allowed: false, reason: 'Memory write admission blocked this write.');
        }
        if (is_string($rawDecision)) {
            return new MemoryAdmissionDecision(allowed: false, reason: $rawDecision);
        }
        if (is_array($rawDecision)) {
            return new MemoryAdmissionDecision(
                // Strict boolean parsing: `(bool) 'false'` is TRUE in PHP, so a
                // memory-admission callback answering with a JSON-decoded
                // payload would turn its own "deny" into an "allow" (T6).
                // A *missing* 'allowed' key still means "no opinion" -> allow.
                allowed: array_key_exists('allowed', $rawDecision)
                    ? BooleanValue::parse($rawDecision['allowed'])
                    : true,
                reason: isset($rawDecision['reason']) ? (string) $rawDecision['reason'] : null,
                value: isset($rawDecision['value']) ? (string) $rawDecision['value'] : $currentValue,
            );
        }
        return new MemoryAdmissionDecision(allowed: true, value: (string) $rawDecision);
    }

    private function coercePromotionDecision(mixed $rawDecision, string $currentValue): MemoryPromotionDecision
    {
        if ($rawDecision instanceof MemoryPromotionDecision) {
            return $rawDecision;
        }
        if ($rawDecision === null) {
            return MemoryPromotionDecision::keep('Memory promotion callback did not approve this candidate.');
        }
        if ($rawDecision === true) {
            return MemoryPromotionDecision::approve($currentValue);
        }
        if ($rawDecision === false) {
            return MemoryPromotionDecision::reject('Memory promotion callback rejected this candidate.');
        }
        if (is_string($rawDecision)) {
            return MemoryPromotionDecision::reject($rawDecision);
        }
        if (is_array($rawDecision)) {
            $action = $rawDecision['action'] ?? null;
            if ($action !== null) {
                return new MemoryPromotionDecision(
                    action: (string) $action,
                    reason: isset($rawDecision['reason']) ? (string) $rawDecision['reason'] : null,
                    value: isset($rawDecision['value']) ? (string) $rawDecision['value'] : null,
                    metadata: is_array($rawDecision['metadata'] ?? null) ? $rawDecision['metadata'] : [],
                );
            }
            $allowed = $rawDecision['allowed'] ?? ($rawDecision['promote'] ?? null);
            if ($allowed === true) {
                $value = (string) ($rawDecision['value'] ?? $currentValue);
                if ($value !== $currentValue) {
                    return MemoryPromotionDecision::rewrite(
                        $value,
                        reason: isset($rawDecision['reason']) ? (string) $rawDecision['reason'] : null,
                        metadata: is_array($rawDecision['metadata'] ?? null) ? $rawDecision['metadata'] : null,
                    );
                }
                return MemoryPromotionDecision::approve(
                    $value,
                    reason: isset($rawDecision['reason']) ? (string) $rawDecision['reason'] : null,
                    metadata: is_array($rawDecision['metadata'] ?? null) ? $rawDecision['metadata'] : null,
                );
            }
            if ($allowed === false) {
                return MemoryPromotionDecision::reject(
                    isset($rawDecision['reason']) ? (string) $rawDecision['reason'] : null,
                    metadata: is_array($rawDecision['metadata'] ?? null) ? $rawDecision['metadata'] : null,
                );
            }
            if (array_key_exists('value', $rawDecision)) {
                return MemoryPromotionDecision::rewrite(
                    (string) $rawDecision['value'],
                    reason: isset($rawDecision['reason']) ? (string) $rawDecision['reason'] : null,
                    metadata: is_array($rawDecision['metadata'] ?? null) ? $rawDecision['metadata'] : null,
                );
            }
            return MemoryPromotionDecision::keep(
                isset($rawDecision['reason']) ? (string) $rawDecision['reason'] : null,
                metadata: is_array($rawDecision['metadata'] ?? null) ? $rawDecision['metadata'] : null,
            );
        }
        return MemoryPromotionDecision::keep('Memory promotion callback returned an unsupported decision.');
    }

    /**
     * Python re.search takes an unanchored pattern with flags=IGNORECASE;
     * wrap the pattern as a case-insensitive PCRE.
     *
     * Two accepted spellings:
     *   - `/body/` or `/body/flags` — the leading/trailing slashes are
     *     delimiters and the body is the pattern. The body's own slashes are
     *     escaped so they cannot terminate the delimiter, and slashes that
     *     were already escaped are unescaped first, so `/etc/passwd/` and
     *     `/etc\/passwd/` behave identically (R3).
     *   - anything else — the whole string is the pattern body.
     *
     * The modifier set is PCRE's, spelled in PCRE's case. This PHP build
     * accepts `i m s u x U A D S J X n r`; lower-casing the captured
     * modifiers would silently turn `S` into `s` (dotall), `U` into `u`
     * (utf8) and `X` into `x` (extended) — different matching semantics
     * under the same name (M6).
     *
     * Only *valid* PCRE modifiers are captured. A modifier this build does
     * not support (`e`, `E`, `a`, `L`, `b`, …) is therefore NOT captured and
     * the whole string falls back to the literal form. That is deliberate:
     * the `/body/flags` spelling is inherently ambiguous — `/path/to/e` is
     * either "body `path/to` with modifier `e`" or "the literal path
     * `/path/to/e`". Capturing unsupported letters made every rule whose
     * last path segment is one of them (`/etc/e`, `/var/L`, …) an invalid
     * PCRE, i.e. a rule that silently rejected EVERY memory write. Leaving
     * them uncaptured keeps such rules working as plain paths; the cost is
     * that a rule that *meant* an unsupported modifier is treated as a
     * literal instead of failing closed. A genuinely broken regex body
     * (e.g. `secret)`) still fails closed (A5/M14).
     *
     * `i` is always added because the Python original always searches with
     * `re.IGNORECASE`.
     */
    private static function normalizePattern(string $pattern): string
    {
        // Delimiter form `/body/` or `/body/flags` (valid PCRE modifiers only).
        if (preg_match('/^\/(.*)\/([imsxuUADSJXnr]*)$/', $pattern, $m) === 1) {
            $flags = $m[2] !== '' ? $m[2] : 'i';
            if (!str_contains($flags, 'i')) {
                $flags .= 'i';
            }
            return '/(' . self::escapePatternSlashes($m[1]) . ')/' . $flags;
        }

        // Everything else: the raw body (paths, plain words). Escape the
        // slashes so they cannot be confused with the delimiters.
        return '/(' . self::escapePatternSlashes($pattern) . ')/i';
    }

    /**
     * Normalise slashes inside a pattern body: a pre-escaped `\/` becomes a
     * plain `/` first, then every `/` is escaped exactly once. This keeps
     * `/etc/passwd/`, `/etc\/passwd/` and `/etc/passwd/i` equivalent while
     * guaranteeing the result is a valid PCRE (R3/R8).
     */
    private static function escapePatternSlashes(string $body): string
    {
        $body = str_replace('\\/', '/', $body);
        return str_replace('/', '\\/', $body);
    }
}
