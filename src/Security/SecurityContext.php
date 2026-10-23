<?php

declare(strict_types=1);

namespace LightAgent\Security;

use LightAgent\Capabilities\CapabilityRisk;
use LightAgent\Capabilities\PermissionSet;
use LightAgent\Capabilities\RuntimeContext;
use LightAgent\Support\BooleanValue;

/**
 * Trusted runtime identity with a narrowing-only permission snapshot
 * (security.py SecurityContext, frozen dataclass).
 */
final class SecurityContext
{
    /**
     * The deadline as an ABSOLUTE UTC instant.
     *
     * A relative deadline ("+5 minutes") is resolved ONCE, at construction
     * time, and stored in its resolved form. Re-parsing a relative string
     * on every comparison made the deadline move with the clock: a child
     * context inherited a deadline that looked LATER than its parent's
     * (V5-10) and `isExpired()` never became true (V5-11).
     */
    public readonly ?string $deadline;

    /**
     * @param list<string> $resources
     * @param list<string> $approvalTokenIds
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly ?string $userId = null,
        public readonly ?string $tenantId = null,
        public readonly ?string $projectId = null,
        public readonly ?string $sessionId = null,
        public readonly ?string $runId = null,
        public readonly ?string $taskId = null,
        public readonly ?string $attemptId = null,
        public readonly ?string $agentId = null,
        public readonly ?string $parentAgentId = null,
        public readonly PermissionSet $permissions = new PermissionSet(),
        public readonly array $resources = [],
        public readonly bool $networkAllowed = false,
        public readonly bool $sandboxRequired = false,
        ?string $deadline = null,
        public readonly string $policyVersion = '1',
        public readonly array $approvalTokenIds = [],

        public readonly array $metadata = [],
        public readonly int $schemaVersion = SecurityUtils::SECURITY_CONTEXT_SCHEMA_VERSION,
        // True when the resource list was explicitly narrowed to empty, which
        // means "no resource may be accessed" (H3) instead of "unrestricted".
        private readonly bool $resourcesRestricted = false,
    ) {
        // Older schemas are accepted so that payloads persisted before the
        // `unrestricted` flag existed can still be loaded (fromDict() then
        // applies the legacy semantics for them). A payload from a NEWER
        // schema is rejected — this build cannot know its rules.
        if ($this->schemaVersion > SecurityUtils::SECURITY_CONTEXT_SCHEMA_VERSION) {
            throw new \InvalidArgumentException("unsupported SecurityContext schema version: {$this->schemaVersion}");
        }
        // Resolve the deadline exactly once. An absolute deadline keeps the
        // caller's spelling; a relative one becomes the instant it denotes
        // right now, so every later comparison sees the same point in time.
        $this->deadline = $deadline === null ? null : SecurityUtils::resolveDeadline($deadline);
    }

    public function isExpired(): bool
    {
        if ($this->deadline === null) {
            return false;
        }
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC')) >= SecurityUtils::parseUtc($this->deadline);
    }

    /**
     * Derive a narrowed child context (security.py SecurityContext.narrow).
     *
     * @param list<string>|null $allowedCapabilities
     * @param list<string>|null $deniedCapabilities
     * @param list<string>|null $resources
     * @param array<string, mixed>|null $metadata
     */
    public function narrow(
        ?string $taskId = null,
        ?string $attemptId = null,
        ?string $agentId = null,
        ?array $allowedCapabilities = null,
        ?array $deniedCapabilities = null,
        ?CapabilityRisk $maxRisk = null,
        ?array $resources = null,
        ?bool $networkAllowed = null,
        ?bool $sandboxRequired = null,
        ?string $deadline = null,
        ?array $metadata = null,
    ): self {
        $requestedResources = $resources !== null ? array_values($resources) : $this->resources;
        // The add-resources guard covers BOTH allowlist parents and deny-all
        // parents (R12): when resourcesRestricted is true the parent grants
        // no resources, so ANY explicitly requested resource is an escape.
        if ($this->resourcesRestricted && $requestedResources !== []) {
            throw new \InvalidArgumentException('child security context cannot add resources: the parent boundary grants none');
        }
        if ($this->resources !== [] && array_diff($requestedResources, $this->resources) !== []) {
            throw new \InvalidArgumentException('child security context cannot add resources');
        }
        // Explicitly narrowing to an empty resource list restricts the child
        // to "no resources" (H3); it never re-opens the parent boundary.
        $childResourcesRestricted = $resources !== null
            ? $requestedResources === []
            : $this->resourcesRestricted;
        $requestedNetwork = $networkAllowed ?? $this->networkAllowed;
        if ($requestedNetwork && !$this->networkAllowed) {
            throw new \InvalidArgumentException('child security context cannot enable network access');
        }
        $requestedSandbox = $sandboxRequired ?? $this->sandboxRequired;
        if ($this->sandboxRequired && !$requestedSandbox) {
            throw new \InvalidArgumentException('child security context cannot remove the sandbox requirement');
        }
        // The parent's deadline is already an absolute instant (it was
        // resolved once, at construction). A child that does not name a
        // deadline inherits that instant verbatim, so the comparison below
        // is between two identical points in time and never reports a
        // spurious extension (V5-10). A child that DOES name a deadline
        // gets it resolved now; a deadline later than the parent's is
        // rejected.
        $resolvedDeadline = $deadline !== null
            ? SecurityUtils::resolveDeadline($deadline)
            : $this->deadline;
        if ($this->deadline !== null && $resolvedDeadline !== null) {
            $parentDeadline = SecurityUtils::parseUtc($this->deadline);
            $childDeadline = SecurityUtils::parseUtc($resolvedDeadline);
            if ($childDeadline > $parentDeadline) {
                throw new \InvalidArgumentException('child security context cannot extend the deadline');
            }
        }
        return new self(
            userId: $this->userId,
            tenantId: $this->tenantId,
            projectId: $this->projectId,
            sessionId: $this->sessionId,
            runId: $this->runId,
            taskId: $taskId ?? $this->taskId,
            attemptId: $attemptId ?? $this->attemptId,
            agentId: $agentId ?? $this->agentId,
            parentAgentId: $this->agentId ?? $this->parentAgentId,
            permissions: $this->permissions->narrow(
                allowed: $allowedCapabilities,
                denied: $deniedCapabilities,
                maxRisk: $maxRisk,
            ),
            resources: $requestedResources,
            networkAllowed: $requestedNetwork,
            sandboxRequired: $requestedSandbox,
            deadline: $resolvedDeadline,
            metadata: array_merge($this->metadata, $metadata ?? []),
            resourcesRestricted: $childResourcesRestricted,
            // T3: the Python original builds the child with
            // `dataclasses.replace(self, ...)`, which keeps every field the
            // call did NOT mention. The PHP port rebuilt the object from
            // scratch and silently reset the policy version, the granted
            // approval token ids and the schema version to their defaults.
            // A child context therefore claimed policy version "1" and
            // carried no approvals — the DAG policy digest of a child run no
            // longer matched the parent's.
            policyVersion: $this->policyVersion,
            approvalTokenIds: $this->approvalTokenIds,
            schemaVersion: $this->schemaVersion,
        );
    }

    public function isResourceRestricted(): bool
    {
        return $this->resourcesRestricted;
    }

    public function toRuntimeContext(): RuntimeContext
    {
        return new RuntimeContext(
            sessionId: $this->sessionId,
            agentId: $this->agentId,
            userId: $this->userId,
            tenantId: $this->tenantId,
            projectId: $this->projectId,
            runId: $this->runId,
            taskId: $this->taskId,
            attemptId: $this->attemptId,
            permissions: $this->permissions,
            securityContext: $this,
            metadata: array_merge($this->metadata, [
                'tenant_id' => $this->tenantId,
                'project_id' => $this->projectId,
                'task_id' => $this->taskId,
                'attempt_id' => $this->attemptId,
                'policy_version' => $this->policyVersion,
                'security_context_schema' => $this->schemaVersion,
            ]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'user_id' => $this->userId,
            'tenant_id' => $this->tenantId,
            'project_id' => $this->projectId,
            'session_id' => $this->sessionId,
            'run_id' => $this->runId,
            'task_id' => $this->taskId,
            'attempt_id' => $this->attemptId,
            'agent_id' => $this->agentId,
            'parent_agent_id' => $this->parentAgentId,
            'permissions' => [
                'allowed' => $this->permissions->allowed,
                'denied' => $this->permissions->denied,
                'max_risk' => $this->permissions->maxRisk->value,
                // Round-trip the empty-list semantics (A2): unrestricted true
                // means "no allowlist gate", false means "deny everything".
                'unrestricted' => $this->permissions->isUnrestricted(),
            ],
            'resources' => array_values($this->resources),
            // Round-trip the empty-resources semantics (R1): true means
            // "explicitly narrowed to no resources" (deny all), absent/false
            // means the legacy "empty = unrestricted".
            'resources_restricted' => $this->resourcesRestricted,
            'network_allowed' => $this->networkAllowed,
            'sandbox_required' => $this->sandboxRequired,
            'deadline' => $this->deadline,
            'policy_version' => $this->policyVersion,
            'approval_token_ids' => array_values($this->approvalTokenIds),
            'metadata' => $this->metadata,
            'schema_version' => $this->schemaVersion,
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromDict(array $value): self
    {
        $permissions = self::parsePermissions($value);
        return new self(
            userId: isset($value['user_id']) ? (string) $value['user_id'] : null,
            tenantId: isset($value['tenant_id']) ? (string) $value['tenant_id'] : null,
            projectId: isset($value['project_id']) ? (string) $value['project_id'] : null,
            sessionId: isset($value['session_id']) ? (string) $value['session_id'] : null,
            runId: isset($value['run_id']) ? (string) $value['run_id'] : null,
            taskId: isset($value['task_id']) ? (string) $value['task_id'] : null,
            attemptId: isset($value['attempt_id']) ? (string) $value['attempt_id'] : null,
            agentId: isset($value['agent_id']) ? (string) $value['agent_id'] : null,
            parentAgentId: isset($value['parent_agent_id']) ? (string) $value['parent_agent_id'] : null,
            permissions: $permissions,
            resources: is_array($value['resources'] ?? null) ? array_values($value['resources']) : [],
            // Strict boolean parsing (T6): `(bool) 'false'` is TRUE in PHP, so
            // a payload spelling these flags as the string 'false' turned
            // "no network" into "network allowed" and "sandbox required"
            // into "no sandbox needed". A *missing* key keeps the safe
            // default (false).
            networkAllowed: array_key_exists('network_allowed', $value)
                ? BooleanValue::parse($value['network_allowed'])
                : false,
            sandboxRequired: array_key_exists('sandbox_required', $value)
                ? BooleanValue::parse($value['sandbox_required'])
                : false,
            deadline: isset($value['deadline']) ? (string) $value['deadline'] : null,
            policyVersion: (string) ($value['policy_version'] ?? '1'),
            approvalTokenIds: is_array($value['approval_token_ids'] ?? null) ? array_values($value['approval_token_ids']) : [],
            metadata: is_array($value['metadata'] ?? null) ? $value['metadata'] : [],
            schemaVersion: (int) ($value['schema_version'] ?? SecurityUtils::SECURITY_CONTEXT_SCHEMA_VERSION),
            // Restore the deny-all boundary flag (R1); legacy payloads
            // without the key keep the unrestricted default (false).
            resourcesRestricted: (bool) ($value['resources_restricted'] ?? false),
        );
    }

    /**
     * Parse the `permissions` block of a serialised context.
     *
     * The parser is deliberately strict and version-aware, because every
     * ambiguity here used to resolve in favour of the CALLER:
     *
     *  - A `permissions` value that is not a map cannot describe a
     *    permission set. The old code read `$permissions['allowed']` on it
     *    and the `??` swallowed the illegal-offset TypeError, so both keys
     *    read as NULL and the "no allowlist" branch granted EVERY
     *    capability. Such a payload now fails closed (H-1).
     *  - The `unrestricted` flag is parsed with the strict boolean helper:
     *    `(bool) 'false'` is TRUE in PHP, so a payload spelling the flag as
     *    the string 'false' turned "deny everything" into "allow
     *    everything" (H-1).
     *  - A payload that names NEITHER an allowlist nor a denylist says
     *    nothing about which capabilities are granted. Only a payload from
     *    an OLDER schema may keep the legacy "unrestricted" meaning (R2);
     *    a current/newer schema — and a payload without any version — must
     *    load as deny-all (B6).
     *
     * @param array<string, mixed> $value
     */
    private static function parsePermissions(array $value): PermissionSet
    {
        $raw = $value['permissions'] ?? null;
        if ($raw instanceof PermissionSet) {
            return $raw;
        }
        if (!is_array($raw)) {
            // Missing or malformed: fail closed.
            return PermissionSet::denyAll();
        }

        $payloadVersion = array_key_exists('schema_version', $value)
            ? (int) $value['schema_version']
            : null;
        // Payloads written BEFORE the `unrestricted` flag existed never had
        // a "deny everything" state — an empty allowlist always meant
        // "unrestricted" back then — so they keep the legacy meaning (R2).
        // The deny-all state is always written explicitly by
        // narrow(allowed: []).
        $legacyPayload = $payloadVersion !== null
            && $payloadVersion < SecurityUtils::SECURITY_CONTEXT_SCHEMA_VERSION;

        $maxRisk = CapabilityRisk::from((string) ($raw['max_risk'] ?? CapabilityRisk::DESTRUCTIVE->value));
        $allowed = is_array($raw['allowed'] ?? null) ? array_values($raw['allowed']) : [];
        $denied = is_array($raw['denied'] ?? null) ? array_values($raw['denied']) : [];

        if (!array_key_exists('allowed', $raw) && !array_key_exists('denied', $raw)) {
            // Neither an allowlist nor a denylist.
            return new PermissionSet(
                allowed: [],
                denied: [],
                maxRisk: $maxRisk,
                unrestricted: $legacyPayload,
            );
        }

        // The serialised 'unrestricted' flag wins when present (A2) and is
        // parsed STRICTLY.
        $unrestricted = array_key_exists('unrestricted', $raw)
            ? BooleanValue::parse($raw['unrestricted'])
            : $legacyPayload;
        return new PermissionSet(
            allowed: $allowed,
            denied: $denied,
            maxRisk: $maxRisk,
            unrestricted: $unrestricted,
        );
    }
}
