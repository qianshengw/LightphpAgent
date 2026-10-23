<?php

declare(strict_types=1);

namespace LightAgent\Connectors;

use LightAgent\Skills\Skill;
use LightAgent\Tools\ToolRegistry;

/**
 * Connector manifest + offline validation utilities (port of connectors.py).
 */

/**
 * Declarative bundle of existing LightAgent extension primitives.
 * A manifest is metadata only — validating it never starts an MCP server,
 * imports an optional provider SDK, or invokes a tool or hook.
 */
final class ConnectorManifest
{
    /**
     * @param list<callable|object> $tools
     * @param list<Skill|string> $skills
     * @param array<string, array<string, mixed>> $mcpServers
     * @param list<callable|object> $hooks
     * @param array<string, mixed> $memoryAdapters
     * @param array<string, list<string>> $extras
     * @param list<string> $docs
     */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $description = '',
        public array $tools = [],
        public array $skills = [],
        public array $mcpServers = [],
        public array $hooks = [],
        public array $memoryAdapters = [],
        public array $extras = [],
        public array $docs = [],
    ) {
    }
}

