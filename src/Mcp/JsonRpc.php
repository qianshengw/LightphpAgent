<?php

declare(strict_types=1);

namespace LightAgent\Mcp;

/**
 * JSON-RPC 2.0 message codec + MCP protocol constants.
 *
 * Implements the wire subset of the MCP (Model Context Protocol) that
 * mcp_client_manager.py exercises via the official Python SDK:
 *   initialize / notifications/initialized / tools/list / tools/call.
 */
final class JsonRpc
{
    // MCP protocol version negotiated by the Python SDK (mcp 1.10.1)
    public const PROTOCOL_VERSION = '2024-11-05';
    public const CLIENT_INFO = ['name' => 'LightAgent-PHP', 'version' => \LightAgent\Version::VERSION];

    public const METHOD_INITIALIZE = 'initialize';
    public const NOTIFICATION_INITIALIZED = 'notifications/initialized';
    public const METHOD_TOOLS_LIST = 'tools/list';
    public const METHOD_TOOLS_CALL = 'tools/call';

    private function __construct()
    {
    }

    /**
     * Build a request frame (with id).
     *
     * @param array<string, mixed>|null $params
     * @return array<string, mixed>
     */
    public static function request(string $method, ?array $params = null, ?int $id = null): array
    {
        $frame = [
            'jsonrpc' => '2.0',
            'method' => $method,
        ];
        if ($params !== null) {
            $frame['params'] = $params;
        }
        if ($id !== null) {
            $frame['id'] = $id;
        }
        return $frame;
    }

    /**
     * Build a notification frame (no id).
     *
     * @param array<string, mixed>|null $params
     * @return array<string, mixed>
     */
    public static function notification(string $method, ?array $params = null): array
    {
        $frame = [
            'jsonrpc' => '2.0',
            'method' => $method,
        ];
        if ($params !== null) {
            $frame['params'] = $params;
        }
        return $frame;
    }

    /**
     * Encode a frame as one LSP-style newline-delimited JSON line.
     *
     * @param array<string, mixed> $frame
     */
    public static function encode(array $frame): string
    {
        $json = json_encode($frame, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new McpException('failed to encode JSON-RPC frame');
        }
        return $json . "\n";
    }

    /**
     * Decode a newline-delimited JSON line into a frame; returns null for
     * blank/keep-alive lines.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            throw new McpException('invalid JSON-RPC frame: ' . substr($line, 0, 120));
        }
        return $decoded;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function initializeParams(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [],
            'clientInfo' => self::CLIENT_INFO,
        ];
    }

    /**
     * @param array<string, mixed> $frame
     */
    public static function isResponse(array $frame): bool
    {
        return array_key_exists('id', $frame)
            && (array_key_exists('result', $frame) || array_key_exists('error', $frame));
    }
}
