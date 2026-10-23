<?php

declare(strict_types=1);

/**
 * Example 09 — Dynamic tool creation.
 * Python counterpart: example/09.create_tools.py
 * Python generates .py tool files; the PHP port demonstrates the equivalent:
 * defining and registering a new tool programmatically at runtime.
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/ExampleHelper.php';

use LightAgent\Core\LightAgent;
use LightAgent\Tools\ToolFunction;

error_reporting(E_ALL & ~E_DEPRECATED);

ExampleHelper::header('09 — Dynamic tool creation');
$baseUrl = ExampleHelper::startMockServer('plain');

$agent = new LightAgent([
    'model' => 'gpt-4o-mini',
    'api_key' => 'example-key',
    'base_url' => $baseUrl,
    'auto_discover_skills' => false,
]);

echo "tools before: " . implode(', ', array_keys($agent->toolRegistry->functionMappings)) . "\n";

// "Generate" a new tool at runtime (Python would LLM-generate a .py file;
// here we construct the ToolFunction inline as the PHP equivalent)
$newTool = new ToolFunction(
    callable: static fn (string $cityName): string => "Weather in {$cityName}: 22°C, sunny",
    toolInfo: [
        'tool_name' => 'get_weather_dynamic',
        'tool_title' => 'Dynamic Weather Tool',
        'tool_description' => 'Fetch weather for a city (created at runtime)',
        'tool_params' => [
            ['name' => 'city_name', 'type' => 'string', 'description' => 'City name', 'required' => true],
        ],
    ],
);

$agent->loadTools([$newTool]);
echo "tools after: " . implode(', ', array_keys($agent->toolRegistry->functionMappings)) . "\n";

// Verify it's callable via getTool
$retrieved = $agent->getTool('get_weather_dynamic');
echo "\ngetTool('get_weather_dynamic') -> " . $retrieved->invoke(['city_name' => 'Tokyo']) . "\n";

// Validate the registry schema
$diagnostics = $agent->validateTools();
echo "registry diagnostics: " . (json_encode($diagnostics) ?: '[]') . "\n";

ExampleHelper::stopMockServer();
ExampleHelper::done('09');
