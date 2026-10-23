<?php

declare(strict_types=1);

/**
 * PHP port of example/02.tools_agent.py — the dual-side comparison scenario.
 *
 * Python side:
 *   agent = LightAgent(model=..., api_key=..., tools=[get_weather])
 *   response = agent.run("Please check the weather in Shanghai.")
 *
 * This example runs against a local mock OpenAI-compatible server that emits
 * a tool call and then a final answer, mirroring what a real provider would
 * do for this scenario. It verifies the full loop end to end:
 *   register tool → run → tool_call → dispatch → tool result → final reply.
 *
 * Usage: php examples/tools_agent.php
 */

require __DIR__ . '/../autoload.php';

use LightAgent\Core\LightAgent;
use LightAgent\Llm\OpenAiClient;
use LightAgent\Llm\ToolCallAssembler;
use LightAgent\Tools\ToolFunction;

error_reporting(E_ALL & ~E_DEPRECATED);

// ---- Local mock provider (OpenAI-compatible, streamable) -----------------

$router = sys_get_temp_dir() . '/lightagent_example_router.php';
file_put_contents($router, <<<'PHP'
<?php
$uri = $_SERVER['REQUEST_URI'] ?? '/';
header('Content-Type: application/json');
if (str_contains($uri, 'case=stream')) {
    header('Content-Type: text/event-stream');
    $chunks = [
        ['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_ex02', 'function' => ['name' => 'get_weather', 'arguments' => '{"city_na']]]], 'finish_reason' => null],
        ['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => 'me": "Shanghai"}']]]], 'finish_reason' => null],
        ['delta' => [], 'finish_reason' => 'tool_calls'],
    ];
    foreach ($chunks as $c) {
        echo 'data: ' . json_encode(['id' => 'chatcmpl-ex02', 'choices' => [$c]]) . "\n\n";
        flush();
    }
    echo "data: [DONE]\n\n";
    flush();
    exit;
}
if (str_contains($uri, 'case=plain')) {
    echo json_encode([
        'id' => 'chatcmpl-ex02-final',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Shanghai is sunny today (via get_weather tool).'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 8, 'total_tokens' => 18],
    ]);
    exit;
}
http_response_code(500);
echo 'boom';
PHP);

// First leg: stream=true URL; second leg (tool result loop) uses the same
// base URL — the mock switches on the `case` query parameter, so we run two
// phases: 1) stream tool_call, 2) non-stream final answer after tool result.
$port = 18951;
$cmd = [PHP_BINARY, '-S', "127.0.0.1:{$port}", $router];
$server = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $serverPipes, null, null, ['bypass_shell' => true]);
foreach ($serverPipes as $p) {
    fclose($p);
}
for ($i = 0; $i < 50; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($fp !== false) {
        fclose($fp);
        break;
    }
    usleep(100000);
}

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

// ---- Agent init (mirrors the Python example) -----------------------------

$agent = new LightAgent([
    'model' => 'qwen-turbo-2024-11-01',
    'api_key' => 'example-key',
    'base_url' => "http://127.0.0.1:{$port}/v1?case=plain",
    'auto_discover_skills' => false,
]);
$agent->registerTool($getWeather);

// ---- Phase 1: streaming tool-call detection (mock first leg) -------------

echo "=== Phase 1: streaming tool-call assembly ===\n";
$streamClient = new OpenAiClient(
    apiKey: 'example-key',
    baseUrl: "http://127.0.0.1:{$port}/v1?case=stream",
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

// ---- Phase 2: non-stream final answer (mock second leg) ------------------

echo "\n=== Phase 2: agent.run() final reply ===\n";
$response = $agent->run('Please check the weather in Shanghai.');
printf("response: %s\n", $response);
printf("type: %s\n", get_debug_type($response));

// ---- Cleanup -------------------------------------------------------------

$status = proc_get_status($server);
exec('taskkill /PID ' . $status['pid'] . ' /F 2>nul');
proc_close($server);
@unlink($router);

echo "\nexample 02 (PHP port) completed.\n";
