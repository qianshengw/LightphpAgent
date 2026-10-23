<?php

/**
 * Minimal MCP stdio server for integration testing.
 * Speaks newline-delimited JSON-RPC 2.0 (MCP framing) on stdin/stdout.
 */

error_reporting(0);
ini_set('display_errors', '0');

stdin_loop:
while (true) {
    $line = fgets(STDIN);
    if ($line === false) {
        break;
    }
    $frame = json_decode(trim($line), true);
    if (!is_array($frame) || !isset($frame['method'])) {
        continue;
    }
    $method = $frame['method'];
    $id = $frame['id'] ?? null;

    if ($method === 'initialize') {
        respond($id, [
            'protocolVersion' => '2024-11-05',
            'capabilities' => ['tools' => []],
            'serverInfo' => ['name' => 'test-mcp-server', 'version' => '1.0.0'],
        ]);
    } elseif ($method === 'tools/list') {
        respond($id, [
            'tools' => [
                [
                    'name' => 'add_numbers',
                    'description' => 'Add two numbers',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'a' => ['type' => 'number', 'title' => 'A'],
                            'b' => ['type' => 'number', 'title' => 'B'],
                        ],
                        'required' => ['a', 'b'],
                    ],
                ],
            ],
        ]);
    } elseif ($method === 'tools/call') {
        $args = $frame['params']['arguments'] ?? [];
        $sum = ($args['a'] ?? 0) + ($args['b'] ?? 0);
        respond($id, [
            'content' => [['type' => 'text', 'text' => (string) json_encode(['sum' => $sum])]],
        ]);
    } elseif ($method === 'ping') {
        respond($id, []);
    }
    // notifications (no id): nothing to send
}

function respond(mixed $id, mixed $result): void
{
    fwrite(STDOUT, json_encode([
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => $result,
    ]) . "\n");
    fflush(STDOUT);
}
