<?php

declare(strict_types=1);

namespace LightAgent\Mcp;

/**
 * JSON-RPC error returned by a server (error object in a response frame).
 */
final class McpRemoteException extends McpException
{
    /**
     * @param array<string, mixed> $error
     */
    public function __construct(public readonly array $error)
    {
        $code = (int) ($error['code'] ?? -32603);
        $message = (string) ($error['message'] ?? 'remote error');
        parent::__construct("JSON-RPC error {$code}: {$message}");
    }
}
