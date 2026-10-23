<?php

declare(strict_types=1);

namespace LightAgent\Knowledge;

use LightAgent\Capabilities\BaseCapabilityProvider;
use LightAgent\Capabilities\CapabilityRisk;
use LightAgent\Capabilities\CapabilitySpec;

/**
 * Expose MCP tools as capabilities (knowledge.py MCPProviderAdapter).
 */
final class McpProviderAdapter extends BaseCapabilityProvider
{
    /**
     * @param list<string>|null $toolNames
     */
    public function __construct(
        private readonly \LightAgent\Mcp\MCPClientManager $manager,
        ?array $toolNames = null,
    ) {
        $this->name = 'mcp';
        $this->version = '1';
        $specs = [];
        foreach ($toolNames ?? [] as $name) {
            $specs[] = new CapabilitySpec(
                "mcp.{$name}",
                network: true,
                execute: true,
                risk: CapabilityRisk::SENSITIVE,
                cancellable: true,
            );
        }
        parent::__construct($specs);
    }

    public function invoke(string $capability, mixed ...$arguments): mixed
    {
        $toolName = substr($capability, 4); // strip "mcp."
        return $this->manager->callTool($toolName, $arguments);
    }
}
