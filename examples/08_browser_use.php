<?php

declare(strict_types=1);

/**
 * Example 08 — Browser-use style custom tool.
 * Python counterpart: example/08.browser_use.py
 * Demonstrates registering a multi-param tool that simulates a browser fetch
 * (offline; real usage would wire a headless browser or HTTP client).
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/ExampleHelper.php';

use LightAgent\Core\LightAgent;
use LightAgent\Tools\ToolFunction;

error_reporting(E_ALL & ~E_DEPRECATED);

ExampleHelper::header('08 — Browser-use style tool');

$baseUrl = ExampleHelper::startMockServer('plain');

$agent = new LightAgent([
    'model' => 'gpt-4o-mini',
    'api_key' => 'example-key',
    'base_url' => $baseUrl,
    'auto_discover_skills' => false,
]);

$agent->registerTool(new ToolFunction(
    callable: static function (string $url, string $action = 'read'): array {
        // Simulated browser action — replace with a real HTTP client in production
        return [
            'url' => $url,
            'action' => $action,
            'status' => 200,
            'content_length' => strlen("simulated page at {$url}"),
            'snippet' => "simulated page at {$url}",
        ];
    },
    toolInfo: [
        'tool_name' => 'browser_navigate',
        'tool_description' => 'Navigate to a URL and optionally extract content',
        'tool_params' => [
            ['name' => 'url', 'type' => 'string', 'description' => 'Target URL', 'required' => true],
            ['name' => 'action', 'type' => 'string', 'description' => 'read/click/extract', 'required' => false],
        ],
    ],
));

// Direct dispatch (offline demo — a live agent loop needs a model)
$args = ['url' => 'https://example.com/page', 'action' => 'read'];
$result = $agent->toolDispatcher->dispatch('browser_navigate', $args);
echo "browser_navigate result:\n{$result}\n";

// Validation error path: missing required param
$badResult = $agent->toolDispatcher->dispatch('browser_navigate', []);
echo "\nmissing-param error (expected): " . substr((string) $badResult, 0, 80) . "\n";

ExampleHelper::stopMockServer();
ExampleHelper::done('08');
