<?php

declare(strict_types=1);

namespace LightAgent\Capabilities;

/**
 * Runtime identity context (capabilities.py RuntimeContext).
 */
final class RuntimeContext
{
    public function __construct(
        public ?string $runtimeId = null,
        public ?string $sessionId = null,
        public ?string $agentId = null,
        public ?string $userId = null,
        public ?string $tenantId = null,
        public ?string $projectId = null,
        public ?string $turnId = null,
        public ?string $runId = null,
        public ?string $taskId = null,
        public ?string $attemptId = null,
        public ?PermissionSet $permissions = null,
        public mixed $securityContext = null,
        /** @var array<string, mixed> */
        public array $metadata = [],
    ) {
    }
}
