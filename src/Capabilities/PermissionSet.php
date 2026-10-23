<?php

declare(strict_types=1);

namespace LightAgent\Capabilities;

/**
 * Capability allowlist that can only be narrowed by descendants
 * (capabilities.py PermissionSet, frozen dataclass).
 */
final class PermissionSet
{
    /**
     * True when an empty `allowed` list means "no restriction" (legacy
     * semantics: only the risk level gates). `narrow(allowed: [])` flips
     * this to false so an explicitly emptied allowlist denies everything.
     *
     * @param list<string> $allowed
     * @param list<string> $denied
     */
    public function __construct(
        public readonly array $allowed = [],
        public readonly array $denied = [],
        public readonly CapabilityRisk $maxRisk = CapabilityRisk::DESTRUCTIVE,
        private readonly bool $unrestricted = true,
    ) {
    }

    public static function unrestricted(?CapabilityRisk $maxRisk = null): self
    {
        return new self(maxRisk: $maxRisk ?? CapabilityRisk::DESTRUCTIVE);
    }

    public static function denyAll(): self
    {
        return new self(allowed: [], denied: [], maxRisk: CapabilityRisk::READ_ONLY, unrestricted: false);
    }

    public function allows(string $capability, CapabilityRisk $risk = CapabilityRisk::READ_ONLY): bool
    {
        if (in_array($capability, $this->denied, true)) {
            return false;
        }
        if (!$this->unrestricted && $this->allowed === []) {
            // Explicitly narrowed to an empty allowlist: deny everything.
            return false;
        }
        if ($this->allowed !== [] && !in_array($capability, $this->allowed, true)) {
            return false;
        }
        return $risk->order() <= $this->maxRisk->order();
    }

    /**
     * True when an empty allowlist means "no restriction" rather than
     * "deny everything" — needed for loss-free serialization (A2).
     */
    public function isUnrestricted(): bool
    {
        return $this->unrestricted;
    }

    /**
     * True when the set grants no capability at all.
     *
     * A caller that has to decide between "no boundary at all" and "no
     * access at all" needs to tell the two apart: `new PermissionSet()`
     * is UNRESTRICTED (it grants everything) while `denyAll()` grants
     * nothing. V4-14 / V5-25: a parent that is not registered must never
     * degenerate into the former.
     */
    public function isDenyAll(): bool
    {
        return !$this->unrestricted && $this->allowed === [];
    }

    /**
     * Derive a child permission set from a parent that may be MISSING.
     *
     * A missing parent is NOT an unrestricted parent. `new PermissionSet()`
     * grants every capability, so a "parent not found" fallback silently
     * turned into "the child may do anything" (V4-14 / V5-25). A missing
     * parent therefore yields a deny-all set: the child can do nothing,
     * and asking for a capability on top of it is rejected by `narrow()`.
     *
     * A caller that means "this is a root agent, there is no parent" must
     * say so explicitly with `PermissionSet::unrestricted()`.
     *
     * @param list<string>|null $allowed
     */
    public static function narrowFrom(?self $parent, ?array $allowed = null, ?CapabilityRisk $maxRisk = null): self
    {
        if ($parent === null) {
            return self::denyAll();
        }
        return $parent->narrow(allowed: $allowed, maxRisk: $maxRisk);
    }

    /**
     * @param list<string>|null $allowed
     * @param list<string>|null $denied
     */
    public function narrow(
        ?array $allowed = null,
        ?array $denied = null,
        ?CapabilityRisk $maxRisk = null,
    ): self {
        $explicitlyPassed = $allowed !== null;
        $requested = $explicitlyPassed ? array_values($allowed) : $this->allowed;
        // The add-capability guard must hold for BOTH allowlist parents and
        // deny-all parents (A1): when unrestricted is false the parent grants
        // nothing, so any capability the child declares is an escalation.
        if ((!$this->unrestricted || $this->allowed !== [])
            && array_diff($requested, $this->allowed) !== []) {
            $extra = array_values(array_diff($requested, $this->allowed));
            sort($extra);
            throw new \InvalidArgumentException('child permissions cannot add capabilities: ' . json_encode($extra));
        }
        $risk = $maxRisk ?? $this->maxRisk;
        if ($risk->order() > $this->maxRisk->order()) {
            throw new \InvalidArgumentException('child permissions cannot increase max_risk');
        }
        // An explicitly passed empty allowlist narrows the permission set to
        // "deny everything" (H2): empty no longer silently inherits the
        // parent's unrestricted semantics.
        $childUnrestricted = $this->unrestricted && !($explicitlyPassed && $requested === []);
        return new self(
            allowed: $requested,
            denied: array_values(array_unique(array_merge($this->denied, $denied ?? []))),
            maxRisk: $risk,
            unrestricted: $childUnrestricted,
        );
    }
}
