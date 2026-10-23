<?php

declare(strict_types=1);

namespace LightAgent\Core;

/**
 * Multi-agent registry and dispatcher (port of core.py LightSwarm).
 */
final class LightSwarm
{
    /** @var array<string, LightAgent> */
    public array $agents = [];

    public function registerAgent(LightAgent ...$agents): void
    {
        foreach ($agents as $agent) {
            if (isset($this->agents[$agent->name])) {
                $agent->log('INFO', 'register_agent', ['agent_name' => $agent->name, 'status' => 'already_registered']);
            } else {
                $this->agents[$agent->name] = $agent;
                $agent->log('INFO', 'register_agent', ['agent_name' => $agent->name, 'status' => 'registered']);
            }
        }
    }

    /**
     * Run a registered agent.
     *
     * @param array<string, mixed> $runOptions
     */
    public function run(LightAgent $agent, string $query, bool $stream = false, array $runOptions = []): mixed
    {
        if (!isset($this->agents[$agent->name])) {
            throw new \InvalidArgumentException("Agent '{$agent->name}' not found.");
        }
        $runOptions['light_swarm'] = $this;
        $runOptions['stream'] = $stream;
        return $agent->run($query, $runOptions);
    }
}
