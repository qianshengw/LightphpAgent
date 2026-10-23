<?php

declare(strict_types=1);

/**
 * Example 06 — Chat with history (multi-turn).
 * Python counterpart: example/06.chat_with_history.py
 * Demonstrates history threading via getHistory() and explicit history passing.
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/ExampleHelper.php';

use LightAgent\Core\LightAgent;

error_reporting(E_ALL & ~E_DEPRECATED);

ExampleHelper::header('06 — Chat with history');
$baseUrl = ExampleHelper::startMockServer('plain');

$agent = new LightAgent([
    'model' => 'gpt-4o-mini',
    'api_key' => 'example-key',
    'base_url' => $baseUrl,
    'auto_discover_skills' => false,
]);

// Turn 1
$response1 = $agent->run('What is the capital of France?');
echo "turn 1: {$response1}\n";

// getHistory returns the model-facing message list for this run
$history = $agent->getHistory();
echo "\nhistory entries after run: " . count($history) . "\n";
foreach ($history as $msg) {
    echo "  [{$msg['role']}] " . substr((string) $msg['content'], 0, 60) . "\n";
}

// Turn 2 passes explicit history (OpenAI message format)
$response2 = $agent->run('And what about Germany?', [
    'history' => [
        ['role' => 'user', 'content' => 'What is the capital of France?'],
        ['role' => 'assistant', 'content' => (string) $response1],
    ],
]);
echo "\nturn 2: {$response2}\n";

ExampleHelper::stopMockServer();
ExampleHelper::done('06');
