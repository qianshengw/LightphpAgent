<?php

declare(strict_types=1);

/**
 * Example 01 — Single agent (basic conversation).
 *
 * Python counterpart: example/01.single_agent.py
 * Creates a LightAgent and runs a query. Uses the local mock provider,
 * so it runs fully offline.
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/ExampleHelper.php';

use LightAgent\Core\LightAgent;

error_reporting(E_ALL & ~E_DEPRECATED);

ExampleHelper::header('01 — Single agent basic conversation');
$baseUrl = ExampleHelper::startMockServer('plain');

$agent = new LightAgent([
    'model' => 'gpt-4o-mini',
    'api_key' => 'example-key',
    'base_url' => $baseUrl,
    'auto_discover_skills' => false,
]);

$response = $agent->run('Hello, who are you?');
echo 'response: ' . $response . "\n";
echo 'type: ' . get_debug_type($response) . "\n";

ExampleHelper::stopMockServer();
ExampleHelper::done('01');
