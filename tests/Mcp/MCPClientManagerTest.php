<?php

declare(strict_types=1);

namespace LightAgent\Tests\Mcp;

use LightAgent\Mcp\MCPClientManager;
use LightAgent\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Mirrors tests/test_mcp_client_manager.py using injected fake transports.
 * (Python replaces manager._create_session; PHP overrides createSession().)
 */
final class MCPClientManagerTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function mcpTool(string $name = 'search_docs'): array
    {
        return [
            'name' => $name,
            'description' => 'Search documentation',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['query' => ['type' => 'string', 'title' => 'Query']],
                'required' => ['query'],
            ],
        ];
    }

    public function test_call_preserves_sanitized_failure_receipt(): void
    {
        $stub = new class extends MCPClientManager {
            public function __construct()
            {
                parent::__construct(
                    [
                        'mcpServers' => [
                            'broken' => ['disabled' => false, 'command' => 'missing-command', 'args' => []],
                        ],
                    ],
                    new ToolRegistry(),
                );
            }

            public function createSession(string $serverName, array $config): never
            {
                throw new \RuntimeException('connection failed with Bearer secret-token and sk-1234567890abcdef');
            }
        };

        try {
            $stub->callTool('search_docs', [], targetServer: 'broken');
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $message = $e->getMessage();
            self::assertStringContainsString('MCP诊断', $message);
            self::assertStringContainsString('broken::call_tool:search_docs -> RuntimeException', $message);
            self::assertStringNotContainsString('secret-token', $message);
            self::assertStringNotContainsString('sk-1234567890abcdef', $message);
        }

        self::assertSame([
            [
                'server' => 'broken',
                'phase' => 'call_tool:search_docs',
                'error_type' => 'RuntimeException',
                'message' => 'connection failed with Bearer [redacted] and [redacted]',
            ],
        ], $stub->lastMcpErrors);
    }

    public function test_call_reconnects_and_succeeds_after_transient_failure(): void
    {
        $sessions = [
            new FailingTransport(new \RuntimeException('temporary disconnect')),
            new FakeSessionTransport([self::mcpTool()], result: 'found'),
        ];

        $manager = new ReconnectingManagerStub($sessions);
        $result = $manager->callTool('search_docs', ['query' => 'runtime'], targetServer: 'docs');

        self::assertSame(['server' => 'docs', 'tool' => 'search_docs', 'result' => 'found'], $result);
        self::assertSame(['docs', 'docs'], $manager->attempts);
        self::assertSame('RuntimeException', $manager->lastMcpErrors[0]['error_type'] ?? 'RuntimeException');
        // V5-30: after the transient failure the session is re-created and
        // the SUCCESSFUL call keeps the healthy transport alive. The old
        // expectation (an empty session map) locked in the "reconnect is a
        // re-spawn" behaviour, which lost the server state and paid a fresh
        // initialize + tools/list round trip for every single tool call.
        self::assertArrayHasKey('docs', $manager->serverSessions);
    }

    public function test_refresh_replaces_namespaced_tools_without_duplicates(): void
    {
        $config = [
            'mcpServers' => [
                'docs one' => [
                    'command' => 'unused',
                    'args' => [],
                    'namespace' => 'docs-one',
                    'namespace_tools' => true,
                ],
                'docs-two' => [
                    'command' => 'unused',
                    'args' => [],
                    'namespace_tools' => true,
                ],
            ],
        ];
        $registry = new ToolRegistry();
        $sessions = [
            'docs one' => new FakeSessionTransport([self::mcpTool()]),
            'docs-two' => new FakeSessionTransport([self::mcpTool()]),
        ];

        $manager = new InjectedSessionManagerStub($config, $registry, $sessions);

        self::assertTrue($manager->registerMcpTool());
        self::assertSame(
            ['docs-one__search_docs', 'docs-two__search_docs'],
            array_keys($registry->functionMappings),
        );

        $manager->injected['docs one'] = new FakeSessionTransport([self::mcpTool(), self::mcpTool('read_page')]);
        self::assertTrue($manager->refreshTools('docs one'));

        $names = array_map(
            static fn (array $schema): string => (string) $schema['function']['name'],
            $registry->openaiFunctionSchemas,
        );
        // Python asserts set equality; order is non-deterministic across refresh.
        self::assertEqualsCanonicalizing(
            ['docs-one__search_docs', 'docs-one__read_page', 'docs-two__search_docs'],
            $names,
        );
        self::assertCount(count($names), array_unique($names));
        self::assertSame(['docs one', 'docs-two'], array_keys($manager->config['mcpServers']));
    }

    public function test_streamable_http_uses_credential_provider(): void
    {
        $captured = [];
        $initialized = false;

        $manager = new CredentialManagerStub($captured);

        $session = $manager->createSession('remote', $manager->config['mcpServers']['remote']);
        self::assertTrue($session instanceof RecordingHttpTransport);
        self::assertSame('https://mcp.example.test', $captured['url'] ?? null);
        self::assertSame(
            ['X-Client' => 'LightAgent', 'Authorization' => 'Bearer dynamic-token'],
            $captured['headers'] ?? null,
        );
        self::assertTrue($manager->initialized);
        $manager->cleanup();
    }

    /**
     * M-14/V5-35: a server may publish tool names that are not
     * representable in an OpenAI function schema. The name has to be
     * sanitised and a name that still cannot be represented has to be
     * rejected instead of poisoning the whole provider request.
     */
    public function test_tool_names_are_sanitised_and_validated(): void
    {
        $config = [
            'mcpServers' => [
                'srv' => ['command' => 'unused', 'args' => []],
            ],
        ];
        $registry = new ToolRegistry();
        $sessions = [
            'srv' => new FakeSessionTransport([
                [
                    'name' => 'foo bar',
                    'description' => 'spaces are not allowed',
                    'inputSchema' => ['type' => 'object', 'properties' => []],
                ],
                [
                    'name' => 'good_name',
                    'description' => 'fine',
                    'inputSchema' => ['type' => 'object', 'properties' => []],
                ],
                [
                    'name' => str_repeat('x', 80),
                    'description' => 'too long for an OpenAI function name',
                    'inputSchema' => ['type' => 'object', 'properties' => []],
                ],
            ]),
        ];

        $manager = new InjectedSessionManagerStub($config, $registry, $sessions);
        self::assertTrue($manager->registerMcpTool());

        $names = array_map(
            static fn (array $schema): string => (string) $schema['function']['name'],
            $registry->openaiFunctionSchemas,
        );
        self::assertSame(['foo_bar', 'good_name'], $names);
        foreach ($names as $name) {
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,64}$/', $name);
        }

        // The over-long name is rejected and reported in the receipt.
        $phases = array_column($manager->lastMcpErrors, 'phase');
        self::assertNotEmpty(
            array_filter($phases, static fn (string $phase): bool => str_starts_with($phase, 'register_tool:')),
            'the rejected tool must be reported',
        );
    }

    /**
     * M-15/V5-36: credentials must not survive in the receipts — neither
     * the well known shapes nor an opaque value handed out by the
     * credential provider.
     */
    public function test_credentials_are_redacted_from_receipts(): void
    {
        $cases = [
            'Bearer abc123def' => 'abc123def',
            'sk-abc123' => 'sk-abc123',
            'sk-1234567890abcdef' => 'sk-1234567890abcdef',
            'ghp_ABCDEF123456' => 'ghp_ABCDEF123456',
            'ak_live_99887766' => 'ak_live_99887766',
            'pk_test_55667788' => 'pk_test_55667788',
            'Basic dXNlcjpwYXNz' => 'dXNlcjpwYXNz',
            'x-api-key: 9f8a7b6c5d4e' => '9f8a7b6c5d4e',
            'https://example.test/mcp?api_key=SECRET123' => 'SECRET123',
            'https://user:sup3rSecret@example.test/v1' => 'sup3rSecret',
            'MCP_API_KEY=abcd1234' => 'abcd1234',
        ];
        foreach ($cases as $message => $secret) {
            $clean = MCPClientManager::sanitizeErrorMessage((string) $message);
            self::assertStringNotContainsString((string) $secret, $clean, (string) $message);
        }

        // An opaque value cannot be recognised by a pattern, so the value
        // based layer has to catch it.
        $clean = MCPClientManager::sanitizeErrorMessage('failed for Zx9!Qw2', ['Zx9!Qw2']);
        self::assertStringNotContainsString('Zx9!Qw2', $clean);
    }

    /**
     * V4-19: the tool RESULT is handed to the model, so it needs the same
     * redaction as the error messages.
     */
    public function test_tool_results_are_redacted(): void
    {
        $secret = 'super-secret-value';
        $manager = new LeakySessionManagerStub(
            new FakeSessionTransport([self::mcpTool()], result: "leaked Authorization: Bearer {$secret}"),
            $secret,
        );
        self::assertTrue($manager->registerMcpTool());

        $response = $manager->toolRegistry->functionMappings['search_docs']->invoke(['query' => 'x']);
        self::assertStringNotContainsString($secret, (string) $response['result']);
        self::assertStringContainsString('[redacted]', (string) $response['result']);
    }

    /**
     * V5-30: a tool call must reuse the running server. "Reconnect" used to
     * be a re-spawn, which lost the server state and paid a fresh
     * initialize + tools/list round trip for every single call.
     */
    public function test_tool_calls_reuse_the_running_session(): void
    {
        $manager = new CountingSessionManagerStub(new FakeSessionTransport([self::mcpTool()], result: 'ok'));
        self::assertTrue($manager->registerMcpTool());
        self::assertSame(1, $manager->sessionsCreated);

        $manager->toolRegistry->functionMappings['search_docs']->invoke(['query' => 'x']);
        $manager->toolRegistry->functionMappings['search_docs']->invoke(['query' => 'y']);

        self::assertSame(1, $manager->sessionsCreated, 'a tool call must not re-spawn the MCP server');
        self::assertArrayHasKey('docs', $manager->serverSessions);
    }
}

/**
 * Manager stub reconnecting through a queue of sessions.
 */
final class ReconnectingManagerStub extends MCPClientManager
{
    /** @var list<object> */
    private array $sessions;
    private int $index = 0;

    /** @var list<string> */
    public array $attempts = [];

    public function __construct(array $sessions)
    {
        $this->sessions = array_values($sessions);
        parent::__construct(
            ['mcpServers' => ['docs' => ['command' => 'unused', 'args' => [], 'reconnect_attempts' => 1]]],
            new ToolRegistry(),
        );
    }

    public function createSession(string $serverName, array $config): object
    {
        $this->attempts[] = $serverName;
        $session = $this->sessions[$this->index++];
        $this->serverSessions[$serverName] = $session;
        return $session;
    }
}

/**
 * Manager stub using injected transports.
 */
final class InjectedSessionManagerStub extends MCPClientManager
{
    /** @var array<string, object> */
    public array $injected;

    /**
     * @param array<string, mixed> $config
     * @param array<string, object> $injected
     */
    public function __construct(array $config, ToolRegistry $registry, array $injected)
    {
        $this->injected = $injected;
        parent::__construct($config, $registry);
    }

    public function createSession(string $serverName, array $config): object
    {
        $session = $this->injected[$serverName];
        $this->serverSessions[$serverName] = $session;
        return $session;
    }
}

/**
 * Manager stub counting how often a session was created (V5-30).
 */
final class CountingSessionManagerStub extends MCPClientManager
{
    public int $sessionsCreated = 0;

    public function __construct(private readonly FakeSessionTransport $transport)
    {
        parent::__construct(
            ['mcpServers' => ['docs' => ['command' => 'unused', 'args' => []]]],
            new ToolRegistry(),
        );
    }

    public function createSession(string $serverName, array $config): object
    {
        $this->sessionsCreated++;
        $this->serverSessions[$serverName] = $this->transport;
        return $this->transport;
    }
}

/**
 * Manager stub whose server echoes a credential into the tool result and
 * whose credential provider hands that credential out (V4-19/M-15).
 */
final class LeakySessionManagerStub extends MCPClientManager
{
    public function __construct(
        private readonly FakeSessionTransport $transport,
        private readonly string $secret,
    ) {
        parent::__construct(
            ['mcpServers' => ['docs' => ['command' => 'unused', 'args' => []]]],
            new ToolRegistry(),
            static fn (string $serverName, array $config): array => ['Authorization' => 'Bearer ' . $secret],
        );
    }

    public function createSession(string $serverName, array $config): object
    {
        if ($this->credentialProvider !== null) {
            $supplied = ($this->credentialProvider)($serverName, $config);
            if (is_array($supplied)) {
                $this->rememberCredentialValues($supplied);
            }
        }
        $this->serverSessions[$serverName] = $this->transport;
        return $this->transport;
    }
}

/**
 * HTTP transport that records initialize() args instead of networking.
 */
final class RecordingHttpTransport
{
    public function __construct(
        public readonly string $url,
        public readonly array $headers = [],
        public readonly float $timeout = 60.0,
        public readonly string $mode = 'streamable-http',
    ) {
    }

    public function initialize(): array
    {
        return [];
    }

    public function request(string $method, ?array $params = null): array
    {
        return [];
    }

    public function notify(string $method, ?array $params = null): void
    {
    }

    public function stop(): void
    {
    }
}

/**
 * Manager stub capturing credential-provider headers.
 */
final class CredentialManagerStub extends MCPClientManager
{
    /** @var array<string, mixed> */
    public array $captured = [];
    public bool $initialized = false;

    /**
     * @param array<string, mixed> $capturedRef
     */
    public function __construct(array &$capturedRef)
    {
        $this->capturedRef = &$capturedRef;
        parent::__construct(
            [
                'mcpServers' => [
                    'remote' => [
                        'transport' => 'streamable-http',
                        'url' => 'https://mcp.example.test',
                        'headers' => ['X-Client' => 'LightAgent'],
                    ],
                ],
            ],
            new ToolRegistry(),
            credentialProvider: function (string $serverName, array $config): array {
                \PHPUnit\Framework\Assert::assertSame('remote', $serverName);
                return ['Authorization' => 'Bearer dynamic-token'];
            },
        );
    }

    /** @var array<string, mixed> */
    private array $capturedRef;

    public function createSession(string $serverName, array $config): object
    {
        $headers = is_array($config['headers'] ?? null) ? $config['headers'] : [];
        if ($this->credentialProvider !== null) {
            $supplied = ($this->credentialProvider)($serverName, $config);
            if (is_array($supplied)) {
                $headers = array_merge($headers, $supplied);
            }
        }
        $this->capturedRef = ['url' => (string) $config['url'], 'headers' => $headers];
        $this->initialized = true;
        $transport = new RecordingHttpTransport(url: (string) $config['url'], headers: $headers);
        $this->serverSessions[$serverName] = $transport;
        return $transport;
    }
}

/**
 * Transport throwing on every request (Python FakeSession(list_error=...)).
 */
final class FailingTransport
{
    public function __construct(private readonly \Throwable $error)
    {
    }

    public function initialize(): never
    {
        throw $this->error;
    }

    public function request(string $method, ?array $params = null): never
    {
        throw $this->error;
    }

    public function notify(string $method, ?array $params = null): void
    {
    }

    public function stop(): void
    {
    }
}

/**
 * In-memory transport (Python FakeSession).
 */
final class FakeSessionTransport
{
    public function __construct(
        private readonly array $tools,
        private readonly string $result = 'ok',
    ) {
    }

    public function initialize(): array
    {
        return [];
    }

    public function request(string $method, ?array $params = null): array
    {
        if ($method === 'tools/list') {
            return ['tools' => $this->tools];
        }
        if ($method === 'tools/call') {
            return ['content' => [['text' => $this->result]]];
        }
        return [];
    }

    public function notify(string $method, ?array $params = null): void
    {
    }

    public function stop(): void
    {
    }
}
