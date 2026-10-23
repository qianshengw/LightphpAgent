<?php

declare(strict_types=1);

namespace LightAgent\Security;

use LightAgent\Support\BooleanValue;
use LightAgent\Support\Timestamp;
/**
 * Versioned approval bound to one canonical operation and security identity
 * (port of security.py ApprovalToken).
 */
final class ApprovalToken
{
    public function __construct(
        public readonly string $operation,
        public readonly string $argumentsDigest,
        public readonly string $policyVersion,
        public string $tokenId = '',
        public readonly ?string $tenantId = null,
        public readonly ?string $projectId = null,
        public readonly ?string $runId = null,
        public readonly ?string $taskId = null,
        public readonly ?string $attemptId = null,
        public readonly ?string $resource = null,
        public string $issuedAt = '',
        public readonly ?string $expiresAt = null,
        public readonly bool $reusable = false,
        public ?string $consumedAt = null,
        public readonly int $schemaVersion = SecurityUtils::APPROVAL_TOKEN_SCHEMA_VERSION,
        /** @var array<string, mixed> */
        public readonly array $metadata = [],
    ) {
        if ($this->tokenId === '') {
            $this->tokenId = bin2hex(random_bytes(16));
        }
        if ($this->issuedAt === '') {
            $this->issuedAt = SecurityUtils::utcNow();
        }
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed>|null $metadata
     */
    public static function issue(
        string $operation,
        array $arguments,
        SecurityContext $context,
        float $ttlSeconds = 300,
        ?string $resource = null,
        bool $reusable = false,
        ?array $metadata = null,
    ): self {
        if (trim($operation) === '') {
            throw new \InvalidArgumentException('approval operation must not be empty');
        }
        if ($ttlSeconds <= 0) {
            throw new \InvalidArgumentException('ttl_seconds must be positive');
        }
        $expires = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("+{$ttlSeconds} seconds");
        return new self(
            operation: $operation,
            argumentsDigest: SecurityUtils::canonicalDigest($arguments),
            policyVersion: $context->policyVersion,
            tenantId: $context->tenantId,
            projectId: $context->projectId,
            runId: $context->runId,
            taskId: $context->taskId,
            attemptId: $context->attemptId,
            resource: $resource,
            // Millisecond precision: DateTimeInterface::ATOM truncates to
            // whole seconds, so a sub-second TTL would expire immediately (T8).
            expiresAt: Timestamp::format($expires),
            reusable: $reusable,
            metadata: $metadata ?? [],
        );
    }

    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC')) >= SecurityUtils::parseUtc($this->expiresAt);
    }

    /**
     * Verify the token against the current operation (security.py verify).
     *
     * Every rejection is a `PermissionError` (README §13): the token did
     * not authorise THIS operation, in THIS context. It stays a
     * `\RuntimeException` subclass, so existing `catch (\RuntimeException)`
     * handlers keep working.
     *
     * @param array<string, mixed> $arguments
     *
     * @throws PermissionError when the token does not authorise the call
     */
    public function verify(
        string $operation,
        array $arguments,
        SecurityContext $context,
        ?string $resource = null,
        bool $consume = true,
    ): void {
        if ($this->schemaVersion !== SecurityUtils::APPROVAL_TOKEN_SCHEMA_VERSION) {
            throw new \RuntimeException('unsupported approval token schema');
        }
        if ($this->isExpired()) {
            throw new PermissionError('approval token has expired');
        }
        if ($this->consumedAt !== null && !$this->reusable) {
            throw new PermissionError('approval token has already been consumed');
        }
        $expected = [
            $this->operation,
            $this->argumentsDigest,
            $this->policyVersion,
            $this->tenantId,
            $this->projectId,
            $this->runId,
            $this->taskId,
            $this->attemptId,
            $this->resource,
        ];
        $actual = [
            $operation,
            SecurityUtils::canonicalDigest($arguments),
            $context->policyVersion,
            $context->tenantId,
            $context->projectId,
            $context->runId,
            $context->taskId,
            $context->attemptId,
            $resource,
        ];
        if ($expected !== $actual) {
            throw new PermissionError('approval token does not match the current operation');
        }
        if ($consume && !$this->reusable) {
            $this->consumedAt = SecurityUtils::utcNow();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'operation' => $this->operation,
            'arguments_digest' => $this->argumentsDigest,
            'policy_version' => $this->policyVersion,
            'token_id' => $this->tokenId,
            'tenant_id' => $this->tenantId,
            'project_id' => $this->projectId,
            'run_id' => $this->runId,
            'task_id' => $this->taskId,
            'attempt_id' => $this->attemptId,
            'resource' => $this->resource,
            'issued_at' => $this->issuedAt,
            'expires_at' => $this->expiresAt,
            'reusable' => $this->reusable,
            'consumed_at' => $this->consumedAt,
            'schema_version' => $this->schemaVersion,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromDict(array $value): self
    {
        return new self(
            operation: (string) $value['operation'],
            argumentsDigest: (string) $value['arguments_digest'],
            policyVersion: (string) $value['policy_version'],
            tokenId: (string) ($value['token_id'] ?? '') ?: bin2hex(random_bytes(16)),
            tenantId: isset($value['tenant_id']) ? (string) $value['tenant_id'] : null,
            projectId: isset($value['project_id']) ? (string) $value['project_id'] : null,
            runId: isset($value['run_id']) ? (string) $value['run_id'] : null,
            taskId: isset($value['task_id']) ? (string) $value['task_id'] : null,
            attemptId: isset($value['attempt_id']) ? (string) $value['attempt_id'] : null,
            resource: isset($value['resource']) ? (string) $value['resource'] : null,
            issuedAt: (string) ($value['issued_at'] ?? '') ?: SecurityUtils::utcNow(),
            expiresAt: isset($value['expires_at']) ? (string) $value['expires_at'] : null,
            // Strict boolean parsing (H-11): `(bool) 'false'` is TRUE in PHP,
            // so a token restored from JSON with `"reusable": "false"`
            // became REUSABLE and the single-use guarantee was gone.
            reusable: BooleanValue::parse($value['reusable'] ?? false),
            consumedAt: isset($value['consumed_at']) ? (string) $value['consumed_at'] : null,
            schemaVersion: (int) ($value['schema_version'] ?? SecurityUtils::APPROVAL_TOKEN_SCHEMA_VERSION),
            metadata: is_array($value['metadata'] ?? null) ? $value['metadata'] : [],
        );
    }
}

