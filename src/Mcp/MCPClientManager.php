<?php

declare(strict_types=1);

namespace LightAgent\Mcp;

use LightAgent\Tools\ToolFunction;
use LightAgent\Tools\ToolRegistry;

/**
 * Enhanced MCP client manager (faithful port of mcp_client_manager.py).
 *
 * The Python version delegates transport/session to the official MCP SDK;
 * the PHP port implements the protocol surface directly (JsonRpc +
 * StdioTransport/HttpTransport). The manager logic — tool discovery,
 * registration, namespacing, reconnect, sanitized failure receipts — is
 * ported 1:1.
 */
class MCPClientManager
{
    /**
     * Names that can be represented in an OpenAI function schema. A name
     * outside this grammar makes the provider reject the WHOLE request
     * with a 400, so a malformed server-side name must never reach the
     * schema (M-14/V5-35).
     */
    private const OPENAI_TOOL_NAME_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /** @var array<string, object> server name -> transport (StdioTransport|HttpTransport) */
    public array $serverSessions = [];

    /** @var list<array{server: string, phase: string, error_type: string, message: string}> */
    public array $lastMcpErrors = [];

    /** @var array<string, array<string, string>> server -> public tool names */
    protected array $registeredTools = [];

    /** @var array<string, string> public tool name -> server */
    protected array $toolOwners = [];

    /** @var array<string, array<string, string>> server -> raw MCP tool name -> public name */
    protected array $rawToolNames = [];

    /**
     * Credential values handed out by the credential provider. They are
     * redacted from every receipt and every tool result, because a pattern
     * alone cannot recognise an opaque token inside free text (M-15/V5-36).
     *
     * @var list<string>
     */
    protected array $credentialSecrets = [];

    /**
     * @param array<string, mixed> $config mcpServers config
     * @param (callable(string, array<string, mixed>): mixed)|null $credentialProvider
     */
    public function __construct(
        public array $config,
        public readonly ToolRegistry $toolRegistry,
        public readonly ?\Closure $credentialProvider = null,
    ) {
    }

    /**
     * Create (and initialize) a session for one server.
     * Open extension point: tests override to inject fake transports
     * (Python: manager._create_session replacement).
     *
     * @param array<string, mixed> $config
     */
    public function createSession(string $serverName, array $config): object
    {
        $headers = is_array($config['headers'] ?? null) ? $config['headers'] : [];
        if ($this->credentialProvider !== null) {
            $supplied = ($this->credentialProvider)($serverName, $config);
            if (is_array($supplied)) {
                $headers = array_merge($headers, $supplied);
                $this->rememberCredentialValues($supplied);
            }
        }
        // Credential headers coming from the configuration are secrets too.
        $this->rememberCredentialValues($headers, credentialHeadersOnly: true);

        $timeout = (float) ($config['timeout'] ?? 60.0);
        $transportName = strtolower((string) ($config['transport'] ?? ''));
        if (in_array($transportName, ['streamable-http', 'streamable_http', 'http'], true)) {
            $transport = new HttpTransport(
                url: (string) $config['url'],
                headers: $headers,
                timeout: $timeout,
                mode: 'streamable-http',
            );
            $transport->initialize();
        } elseif (isset($config['url'])) {
            $transport = new HttpTransport(
                url: (string) $config['url'],
                headers: $headers,
                timeout: $timeout,
                mode: 'sse',
            );
            $transport->initialize();
        } else {
            $transport = new StdioTransport(
                command: (string) $config['command'],
                args: is_array($config['args'] ?? null) ? $config['args'] : [],
                env: is_array($config['env'] ?? null) ? $config['env'] : null,
                requestTimeout: $timeout,
            );
            $transport->start();
            $transport->request(JsonRpc::METHOD_INITIALIZE, JsonRpc::initializeParams());
            $transport->notify(JsonRpc::NOTIFICATION_INITIALIZED);
        }

        $this->serverSessions[$serverName] = $transport;
        return $transport;
    }

    public function cleanup(): void
    {
        foreach ($this->serverSessions as $transport) {
            if ($transport instanceof StdioTransport || $transport instanceof HttpTransport) {
                $transport->stop();
            }
        }
        $this->serverSessions = [];
    }

    /**
     * Auto-register tools from all enabled MCP servers.
     */
    public function registerMcpTool(): bool
    {
        $this->lastMcpErrors = [];
        $registeredCount = 0;
        $enabled = [];
        foreach (($this->config['mcpServers'] ?? []) as $name => $config) {
            if (!is_array($config) || !empty($config['disabled'])) {
                continue;
            }
            $enabled[] = [$name, $config];
        }

        foreach ($enabled as [$serverName, $config]) {
            try {
                $transport = $this->createSession($serverName, $config);
                $toolsResponse = $transport->request(JsonRpc::METHOD_TOOLS_LIST);
                $tools = is_array($toolsResponse['tools'] ?? null) ? $toolsResponse['tools'] : [];
                echo "🔍 Registering MCP tools for server : {$serverName} ...\n";

                foreach ($tools as $tool) {
                    try {
                        $rawName = (string) ($tool['name'] ?? '');
                        $publicName = $this->publicToolName($serverName, $config, $rawName);
                        // A name that cannot be represented in an OpenAI
                        // function schema makes the provider reject the
                        // whole request, so such a tool is rejected here
                        // instead of poisoning the request (M-14/V5-35).
                        if (preg_match(self::OPENAI_TOOL_NAME_PATTERN, $publicName) !== 1) {
                            throw new McpException(
                                "MCP tool name `{$rawName}` cannot be represented as an OpenAI function name"
                                . ' (allowed: A-Z a-z 0-9 _ - , max 64 characters)',
                            );
                        }
                        $inputSchema = is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : [];
                        $properties = is_array($inputSchema['properties'] ?? null) ? $inputSchema['properties'] : [];
                        $requiredFields = is_array($inputSchema['required'] ?? null) ? $inputSchema['required'] : [];
                        $description = (string) ($tool['description'] ?? '');

                        $toolInfo = [
                            'tool_name' => $publicName,
                            'tool_description' => $description,
                            'tool_params' => [],
                        ];
                        foreach ($properties as $paramName => $paramSchema) {
                            $toolInfo['tool_params'][] = [
                                'name' => (string) $paramName,
                                'type' => (string) (($paramSchema['type'] ?? null) ?? 'string'),
                                'description' => (string) (($paramSchema['title'] ?? null) ?? ''),
                                'required' => in_array((string) $paramName, array_map('strval', $requiredFields), true),
                            ];
                        }

                        $this->toolRegistry->functionInfo[$publicName] = $toolInfo;
                        $this->toolRegistry->functionMappings[$publicName] = new ToolFunction(
                            callable: fn (array $params = []): array => $this->callTool(
                                toolName: $rawName,
                                arguments: $params,
                                targetServer: $serverName,
                            ),
                            toolInfo: $toolInfo,
                        );

                        $openaiSchema = [
                            'type' => 'function',
                            'function' => [
                                'name' => $publicName,
                                'description' => $description,
                                'parameters' => [
                                    'type' => 'object',
                                    'properties' => array_map(
                                        static fn (array $v): array => [
                                            'type' => (string) (($v['type'] ?? null) ?? 'string'),
                                            'description' => (string) (($v['title'] ?? null) ?? ''),
                                        ],
                                        array_map(
                                            static fn ($v): array => is_array($v) ? $v : [],
                                            $properties,
                                        ),
                                    ),
                                    'required' => $requiredFields,
                                ],
                            ],
                        ];
                        $this->toolRegistry->openaiFunctionSchemas[] = $openaiSchema;
                        $this->registeredTools[$serverName][$publicName] = $publicName;
                        $this->toolOwners[$publicName] = $serverName;
                        $this->rawToolNames[$serverName][$rawName] = $publicName;
                        $registeredCount++;
                        echo "✅ The registered MCP tool : {$rawName}\n";
                    } catch (\Throwable $e) {
                        $this->recordError($serverName, 'register_tool:' . (string) ($tool['name'] ?? 'unknown'), $e);
                        continue;
                    }
                }
            } catch (\Throwable $e) {
                $this->recordError($serverName, 'register_server', $e);
                continue;
            }
        }

        // The sessions stay open on purpose: tearing them down here forced
        // every single tool call to re-spawn the server process (V5-30).
        // cleanup() is the explicit shutdown path.
        return $registeredCount > 0;
    }

    /**
     * Refresh MCP tool lists without leaving duplicate registrations.
     */
    public function refreshTools(?string $serverName = null): bool
    {
        $targets = $serverName !== null ? [$serverName] : array_keys($this->registeredTools);
        foreach ($targets as $target) {
            foreach (array_keys($this->registeredTools[$target] ?? []) as $name) {
                unset($this->toolRegistry->functionInfo[$name]);
                unset($this->toolRegistry->functionMappings[$name]);
                unset($this->toolOwners[$name]);
                $this->toolRegistry->openaiFunctionSchemas = array_values(array_filter(
                    $this->toolRegistry->openaiFunctionSchemas,
                    static fn (array $schema): bool => (($schema['function']['name'] ?? null) !== $name),
                ));
            }
            unset($this->registeredTools[$target]);
            unset($this->rawToolNames[$target]);
        }

        if ($serverName === null) {
            return $this->registerMcpTool();
        }
        $original = $this->config['mcpServers'];
        $rebuilt = [];
        foreach ($original as $name => $config) {
            if (!is_array($config)) {
                continue;
            }
            $rebuilt[(string) $name] = array_merge($config, ['disabled' => (string) $name !== $serverName]);
        }
        $this->config['mcpServers'] = $rebuilt;
        try {
            return $this->registerMcpTool();
        } finally {
            $this->config['mcpServers'] = $original;
        }
    }

    /**
     * Build the public (model-facing) name of an MCP tool.
     *
     * The namespace AND the tool name are sanitised with the same rule:
     * a server may publish a tool called "foo bar", and such a name is not
     * representable in an OpenAI function schema — it used to be written
     * into the registry verbatim and made the whole provider request fail
     * with a 400 (M-14/V5-35).
     *
     * @param array<string, mixed> $config
     */
    private function publicToolName(string $serverName, array $config, string $toolName): string
    {
        $namespace = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($config['namespace'] ?? $serverName)) ?? $serverName;
        $sanitised = preg_replace('/[^A-Za-z0-9_-]/', '_', $toolName) ?? '';
        $namespaced = "{$namespace}__{$sanitised}";
        if (
            !empty($config['namespace_tools'])
            || (isset($this->toolRegistry->functionMappings[$sanitised])
                && ($this->toolOwners[$sanitised] ?? null) !== $serverName)
        ) {
            return $namespaced;
        }
        return $sanitised;
    }

    /**
     * Generic tool invocation with reconnect support.
     *
     * @param array<string, mixed> $arguments
     * @return array{server: string, tool: string, result: string}
     */
    public function callTool(string $toolName, array $arguments, ?string $targetServer = null): array
    {
        $this->lastMcpErrors = [];
        $enabled = [];
        foreach (($this->config['mcpServers'] ?? []) as $name => $config) {
            if (!is_array($config) || !empty($config['disabled'])) {
                continue;
            }
            if ($targetServer !== null && $name !== $targetServer) {
                continue;
            }
            $enabled[] = [$name, $config];
        }

        foreach ($enabled as [$serverName, $config]) {
            $maxAttempts = max(1, (int) ($config['reconnect_attempts'] ?? 0) + 1);
            for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
                try {
                    $transport = $this->serverSessions[$serverName] ?? null;
                    if ($transport === null) {
                        $transport = $this->createSession($serverName, $config);
                    }

                    $toolsResponse = $transport->request(JsonRpc::METHOD_TOOLS_LIST);
                    $tools = is_array($toolsResponse['tools'] ?? null) ? $toolsResponse['tools'] : [];
                    $available = [];
                    foreach ($tools as $t) {
                        if (is_array($t) && isset($t['name'])) {
                            $available[(string) $t['name']] = $t;
                        }
                    }

                    if (isset($available[$toolName])) {
                        $schema = is_array($available[$toolName]['inputSchema'] ?? null)
                            ? $available[$toolName]['inputSchema']
                            : [];
                        $this->validateArguments($arguments, $schema);

                        $result = $transport->request(JsonRpc::METHOD_TOOLS_CALL, [
                            'name' => $toolName,
                            'arguments' => $arguments,
                        ]);
                        $content = is_array($result['content'] ?? null) ? $result['content'] : [];
                        $text = '';
                        if (isset($content[0]['text'])) {
                            $text = (string) $content[0]['text'];
                        }
                        // The tool RESULT is handed to the model, so it needs
                        // the same redaction as the error messages: a server
                        // that echoes a credential into its result would leak
                        // it into the conversation (V4-19).
                        $text = self::sanitizeErrorMessage($text, $this->credentialSecrets);
                        // A healthy transport is NOT torn down here: the
                        // "reconnect" used to be a re-spawn, which lost the
                        // server state and paid a fresh initialize +
                        // tools/list round trip for every single call
                        // (V5-30).
                        return [
                            'server' => $serverName,
                            'tool' => $toolName,
                            'result' => $text,
                        ];
                    }
                } catch (\Throwable $e) {
                    $this->recordError($serverName, "call_tool:{$toolName}", $e);
                    // Only the ERROR path tears the session down: the next
                    // attempt re-creates it and retries.
                    $this->cleanup();
                    if ($attempt + 1 >= $maxAttempts) {
                        break;
                    }
                }
            }
        }

        throw new \InvalidArgumentException($this->formatToolNotFound($toolName));
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $schema
     */
    private function validateArguments(array $arguments, array $schema): void
    {
        $requiredFields = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        foreach ($requiredFields as $field) {
            $field = (string) $field;
            if (!array_key_exists($field, $arguments)) {
                throw new \InvalidArgumentException("缺少必要参数: {$field}");
            }
        }
    }

    /**
     * Remember credential values so they can be redacted later on.
     *
     * @param array<int|string, mixed> $values
     */
    protected function rememberCredentialValues(array $values, bool $credentialHeadersOnly = false): void
    {
        foreach ($values as $name => $value) {
            if (!is_string($value)) {
                continue;
            }
            if ($credentialHeadersOnly && !self::isCredentialHeaderName((string) $name)) {
                continue;
            }
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            $this->credentialSecrets[] = $value;
            // "Bearer <token>" also exposes the bare token.
            if (preg_match('/^(?:Bearer|Basic|Token)\s+(\S+)$/i', $value, $matches) === 1) {
                $this->credentialSecrets[] = $matches[1];
            }
        }
    }

    /**
     * Whether a header name looks like it carries a credential.
     */
    private static function isCredentialHeaderName(string $name): bool
    {
        return preg_match(
            '/(?:^|[-_])(?:authorization|api[-_]?key|apikey|access[-_]?token|auth[-_]?token|token|secret|password|passwd|pwd|credential|cookie)(?:$|[-_])/i',
            $name,
        ) === 1;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $schema
     */
    private function recordError(string $serverName, string $phase, \Throwable $error): void
    {
        $this->lastMcpErrors[] = [
            'server' => $serverName,
            'phase' => $phase,
            'error_type' => $error::class,
            'message' => self::sanitizeErrorMessage($error->getMessage(), $this->credentialSecrets),
        ];
    }

    private function formatToolNotFound(string $toolName): string
    {
        if ($this->lastMcpErrors === []) {
            return "工具 {$toolName} 在可用服务器中未找到";
        }
        $details = implode('; ', array_map(
            static fn (array $item): string => "{$item['server']}::{$item['phase']} -> {$item['error_type']}: {$item['message']}",
            array_slice($this->lastMcpErrors, 0, 3),
        ));
        if (count($this->lastMcpErrors) > 3) {
            $details .= '; ... ' . (count($this->lastMcpErrors) - 3) . ' more';
        }
        return "工具 {$toolName} 在可用服务器中未找到。MCP诊断: {$details}";
    }

    /**
     * Redact credentials from a message.
     *
     * Two layers are applied (M-15/V5-36):
     *
     *  1. The credential VALUES handed out by the credential provider are
     *     replaced wherever they appear. A pattern cannot recognise an
     *     opaque token inside free text, so the value-based layer is the
     *     one that actually closes the leak.
     *  2. Pattern rules cover the common shapes: `Bearer <tok>`,
     *     `Basic <b64>`, `sk-`/`ghp_`/`ak_`/`pk_` prefixed keys,
     *     `key=`/`token=`/`secret=`/`password=` in query strings, headers
     *     and environment assignments, and the userinfo part of a URL.
     *
     * @param list<string> $secrets
     */
    public static function sanitizeErrorMessage(string $message, array $secrets = []): string
    {
        $message = (string) $message;

        // 1. Exact credential values.
        foreach ($secrets as $secret) {
            if (!is_string($secret)) {
                continue;
            }
            $secret = trim($secret);
            // Very short values would match everywhere and destroy the
            // message without protecting anything meaningful.
            if (strlen($secret) < 4) {
                continue;
            }
            $message = str_replace($secret, '[redacted]', $message);
        }

        // 2. URL userinfo: https://user:password@host
        $message = (string) preg_replace('#(://[^\s/:@]+):([^\s/:@]+)@#', '$1:[redacted]@', $message);

        // 3. Authorization schemes.
        $message = (string) preg_replace(
            '/\b(Bearer|Basic|Token)(\s+)[A-Za-z0-9._~+\/=:-]+/i',
            '$1$2[redacted]',
            $message,
        );

        // 4. key=/token=/secret=/password= in query strings, headers and
        //    environment assignments.
        $message = (string) preg_replace(
            '/\b((?:[A-Za-z0-9]+[_-])*(?:api[_-]?key|access[_-]?token|auth[_-]?token|session[_-]?token|secret[_-]?key|client[_-]?secret|private[_-]?key|secret|password|passwd|pwd|credential|token|key)s?)(\s*[:=]\s*)("[^"]*"|\'[^\']*\'|[^\s,;&"\')\]}]+)/i',
            '$1$2[redacted]',
            $message,
        );

        // 5. Well-known credential prefixes (short keys included: `sk-abc123`
        //    used to survive the old 8-character minimum).
        $message = (string) preg_replace(
            '/\b(?:sk|pk|ak|rk|ghp|gho|ghu|ghs|ghr|glpat|xox[abprs]|npm|pat)[-_][A-Za-z0-9_-]{3,}/i',
            '[redacted]',
            $message,
        );

        return $message;
    }
}
