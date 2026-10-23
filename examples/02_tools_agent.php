<?php

declare(strict_types=1);

/**
 * Example 02 — Tool-calling agent.
 *
 * Python counterpart: example/02.tools_agent.py — the dual-side comparison scenario.
 * Runs against the shared local mock provider, verifying the full loop:
 *   register tool → streaming tool_call → dispatch → tool result → final reply.
 *
 * Usage: php examples/02_tools_agent.php
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/ExampleHelper.php';

use LightAgent\Core\LightAgent;
use LightAgent\Llm\OpenAiClient;
use LightAgent\Llm\ToolCallAssembler;
use LightAgent\Tools\ToolFunction;

error_reporting(E_ALL & ~E_DEPRECATED);

ExampleHelper::header('02 — Tool-calling agent');
$baseUrl = ExampleHelper::startMockServer('tools');

// ---- Tool definition (mirrors the Python example) ------------------------

$getWeather = new ToolFunction(
    callable: static fn (string $cityName): string => "Query result: {$cityName} is sunny.",
    toolInfo: [
        'tool_name' => 'get_weather',
        'tool_description' => 'Get current weather information for the specified city.',
        'tool_params' => [
            ['name' => 'city_name', 'description' => 'The name of the city to query', 'type' => 'string', 'required' => true],
        ],
    ],
);

// ---- Agent init ----------------------------------------------------------

$agent = new LightAgent([
    'model' => 'qwen-turbo-2024-11-01',
    'api_key' => 'example-key',
    'base_url' => str_replace('case=tools', 'case=plain', $baseUrl),
    'auto_discover_skills' => false,
]);
$agent->registerTool($getWeather);

// ---- Phase 1: streaming tool-call detection ------------------------------

echo "=== Phase 1: streaming tool-call assembly ===\n";
$streamClient = new OpenAiClient(
    apiKey: 'example-key',
    baseUrl: $baseUrl,
    timeout: 10,
);
$stream = $streamClient->chat()->create([
    'model' => 'qwen-turbo-2024-11-01',
    'messages' => [['role' => 'user', 'content' => 'Please check the weather in Shanghai.']],
    'stream' => true,
]);
$assembler = new ToolCallAssembler();
foreach ($stream as $chunk) {
    if (($chunk->choices[0] ?? null) !== null) {
        $assembler->consumeChunk($chunk->choices[0]);
    }
}
$calls = $assembler->getToolCalls();
printf("assembled tool call: name=%s arguments=%s\n", $calls[0]['name'], $calls[0]['arguments']);

// Dispatch through the agent's tool dispatcher (mirrors Python tool loop)
$parsedArgs = $agent->parseToolArguments($calls[0]['arguments']);
$toolResult = $agent->toolDispatcher->dispatch($calls[0]['name'], $parsedArgs);
printf("dispatch result: %s\n", $toolResult);

// ---- Phase 2: non-stream final answer ------------------------------------

echo "\n=== Phase 2: agent.run() final reply ===\n";
$response = $agent->run('Please check the weather in Shanghai.');
printf("response: %s\n", $response);
printf("type: %s\n", get_debug_type($response));

// ---- Cleanup -------------------------------------------------------------

ExampleHelper::stopMockServer();
ExampleHelper::done('02');
