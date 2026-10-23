<?php

declare(strict_types=1);

namespace LightAgent\Capabilities;

/**
 * Capability adapters over the Agent's own primitives
 * (port of capabilities.py ToolProviderAdapter / MemoryProviderAdapter).
 */
final class ToolProviderAdapter extends BaseCapabilityProvider
{
    public function __construct(private readonly mixed $toolRegistry)
    {
        $this->name = 'tools';
        $this->version = '1';
        parent::__construct([]);
    }

    /**
     * Build the capability specs from the tool registry ON DEMAND.
     *
     * The specs used to be snapshotted in the constructor. The Agent
     * registers the built-in providers in its own constructor — long
     * before the application registers its own tools — so the snapshot
     * could never contain a user tool and `invokeCapability('tool.<name>')`
     * failed with "no Provider registered for capability" (V5-18).
     *
     * @return array<string, CapabilitySpec>
     */
    public function capabilities(): array
    {
        $specs = [];
        foreach ($this->toolRegistry->functionInfo as $name => $info) {
            $specs["tool.{$name}"] = new CapabilitySpec(
                name: "tool.{$name}",
                description: (string) ($info['tool_description'] ?? ''),
                execute: true,
                risk: CapabilityRisk::SENSITIVE,
                cancellable: true,
            );
        }
        return $specs;
    }

    public function invoke(string $capability, mixed ...$arguments): mixed
    {
        $name = substr($capability, 5); // strip "tool."
        $dispatcher = new \LightAgent\Tools\ToolDispatcher($this->toolRegistry);
        return $dispatcher->dispatch($name, $arguments);
    }
}
