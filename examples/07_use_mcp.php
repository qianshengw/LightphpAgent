<?php

declare(strict_types=1);

/**
 * Example 07 — MCP tool integration (stdio transport).
 * Python counterpart: example/07.use_mcp.py
 * Registers tools from a local MCP server (bundled fixture) into the agent's
 * tool registry, then calls one through the registry.
 *
 * Requires a working stdio pipe environment (skip message on restricted hosts).
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/ExampleHelper.php';

use LightAgent\Async\AsyncRuntime;
use LightAgent\Core\LightAgent;
use LightAgent\Mcp\MCPClientManager;
use LightAgent\Tools\ToolRegistry;

error_reporting(E_ALL & ~E_DEPRECATED);

ExampleHelper::header('07 — MCP tool integration (stdio)');

$fixture = __DIR__ . '/../tests/Mcp/fixture/test_mcp_server.php';
if (!is_file($fixture)) {
    fwrite(STDERR, "MCP fixture server not found at {$fixture}\n");
    exit(1);
}

AsyncRuntime::run(function () use ($fixture): void {
    $registry = new ToolRegistry();
    $manager = new MCPClientManager(
        ['mcpServers' => ['demo' => ['command' => PHP_BINARY, 'args' => [$fixture]]]],
        $registry,
    );

    echo "registering MCP tools from local stdio server...\n";
    $ok = $manager->registerMcpTool();
    if (!$ok) {
        fwrite(STDERR, "MCP registration failed (pipe inheritance restriction on this host?)\n");
        return;
    }

    echo "registered tools: " . implode(', ', array_keys($registry->functionMappings)) . "\n";
    $schema = $registry->openaiFunctionSchemas[0] ?? null;
    if ($schema !== null) {
        echo "openai schema: " . json_encode($schema['function']['name']) . "\n";
    }

    // Call through the manager
    $result = $manager->callTool('add_numbers', ['a' => 20, 'b' => 22]);
    echo "call_tool result: {$result['result']}\n";
});

ExampleHelper::done('07');
