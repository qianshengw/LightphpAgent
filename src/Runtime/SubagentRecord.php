<?php

declare(strict_types=1);

namespace LightAgent\Runtime;

use LightAgent\Capabilities\PermissionSet;

/**
 * Subagent registration record (runtime.py SubagentRecord).
 */
final class SubagentRecord
{
    public string $status = 'ready';

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $agentId,
        public readonly string $name,
        public readonly ?string $parentAgentId,
        public readonly int $depth,
        public readonly PermissionSet $permissions,
        public readonly bool $persistent = false,
        public array $metadata = [],
    ) {
    }
}
