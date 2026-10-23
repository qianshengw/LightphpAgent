<?php

declare(strict_types=1);

/**
 * Example 04 — Multi-agent (LightSwarm).
 * Python counterpart: example/04.multi_agent.py
 * Two agents registered in a swarm; the entry agent's model decides whether
 * to transfer the task. Demonstrated offline via direct transfer inspection.
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/ExampleHelper.php';

use LightAgent\Core\LightAgent;
use LightAgent\Core\LightSwarm;

error_reporting(E_ALL & ~E_DEPRECATED);

ExampleHelper::header('04 — Multi-agent LightSwarm');

// Create two agents with distinct roles
$writer = new LightAgent([
    'name' => 'Writer',
    'instructions' => 'You write articles.',
    'model' => 'gpt-4o-mini',
    'api_key' => 'example-key',
    'auto_discover_skills' => false,
]);
$reviewer = new LightAgent([
    'name' => 'Reviewer',
    'instructions' => 'You review articles for quality.',
    'model' => 'gpt-4o-mini',
    'api_key' => 'example-key',
    'auto_discover_skills' => false,
]);

$swarm = new LightSwarm();
$swarm->registerAgent($writer, $reviewer);

echo "registered agents: " . implode(', ', array_keys($swarm->agents)) . "\n";

// Detect intent: Python uses an LLM call; here we show the detection contract
// by invoking detectIntent against a mock handoff phrasing. Without a live
// model we demonstrate the API surface instead.
echo "\nswarm.run('write an article', agent=Writer) requires a live model;\n";
echo "offline demo shows the transfer decision surface:\n";
echo "  - intent format: 'transfer to <AgentName>'\n";
echo "  - Writer can hand off to: " . implode(', ', array_diff(array_keys($swarm->agents), ['Writer'])) . "\n";

// Register a tool on Reviewer to prove cross-agent tool isolation
$reviewer->registerTool(new \LightAgent\Tools\ToolFunction(
    callable: static fn (string $doc): string => "reviewed: {$doc}",
    toolInfo: ['tool_name' => 'review_doc', 'tool_description' => 'Review a document', 'tool_params' => []],
));
echo "\nWriter tools: " . implode(', ', array_keys($writer->toolRegistry->functionMappings)) . "\n";
echo "Reviewer tools: " . implode(', ', array_keys($reviewer->toolRegistry->functionMappings)) . "\n";

ExampleHelper::done('04');
