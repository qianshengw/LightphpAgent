<?php

declare(strict_types=1);

namespace LightAgent\Tests\Mcp;

use LightAgent\Async\AsyncRuntime;
use LightAgent\Mcp\McpException;
use LightAgent\Mcp\StdioTransport;
use PHPUnit\Framework\TestCase;
use function Amp\async;
use function Amp\delay;

/**
 * Real end-to-end stdio transport test against a fixture MCP server
 * (tests/Mcp/fixture/test_mcp_server.php).
 *
 * @group integration
 */
final class StdioTransportIntegrationTest extends TestCase
{
    private static string $serverScript;

    public static function setUpBeforeClass(): void
    {
        self::$serverScript = __DIR__ . '/fixture/test_mcp_server.php';
        self::assertFileExists(self::$serverScript);
    }

    /**
     * Detect whether a proc_open'd child's stdout reaches the parent.
     */
    private static function childStdoutReachable(): bool
    {
        $childScript = tempnam(sys_get_temp_dir(), 'mcp_gate_') . '.php';
        file_put_contents($childScript, '<?php echo "pipe-ok";');
        $descriptors = [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open([PHP_BINARY, $childScript], $descriptors, $pipes);
        usleep(500000);
        stream_set_blocking($pipes[1], false);
        $out = stream_get_contents($pipes[1]);
        $status = proc_get_status($proc);
        foreach ($pipes as $p) {
            fclose($p);
        }
        proc_close($proc);
        unlink($childScript);
        return $out === 'pipe-ok' || $status['running'];
    }

    public function test_full_protocol_lifecycle(): void
    {
        // Environment gate: on some Windows hosts an EDR/AV hook breaks
        // anonymous-pipe inheritance for proc_open'd children (child runs,
        // but its stdout bytes never reach the parent). When that happens
        // the stdio transport cannot be validated on this host — skip with
        // an explicit receipt instead of failing the suite.
        if (!self::childStdoutReachable()) {
            self::markTestSkipped(
                'Windows host breaks proc_open stdout pipe inheritance (EDR/AV interference); '
                . 'stdio transport is validated on unmodified environments. MCP logic is '
                . 'covered by MCPClientManagerTest and the HTTP transports.',
            );
        }

        AsyncRuntime::run(function (): void {
            $transport = new StdioTransport(
                command: PHP_BINARY,
                args: [self::$serverScript],
            );
            try {
                $transport->start();

                // initialize handshake
                $init = $transport->request('initialize', [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => [],
                    'clientInfo' => ['name' => 'LightAgent-PHP-Test', 'version' => '1.0.0'],
                ]);
                self::assertSame('test-mcp-server', $init['serverInfo']['name'] ?? null);

                $transport->notify('notifications/initialized');

                // tools/list
                $tools = $transport->request('tools/list');
                $names = array_column($tools['tools'] ?? [], 'name');
                self::assertSame(['add_numbers'], $names);

                // tools/call
                $result = $transport->request('tools/call', [
                    'name' => 'add_numbers',
                    'arguments' => ['a' => 20, 'b' => 22],
                ]);
                $text = $result['content'][0]['text'] ?? '';
                self::assertSame(['sum' => 42], json_decode($text, true));
            } finally {
                $transport->stop();
            }
        });
    }

    public function test_concurrent_requests_keep_ids_straight(): void
    {
        if (!self::childStdoutReachable()) {
            self::markTestSkipped('Windows host breaks proc_open stdout pipe inheritance; see lifecycle test.');
        }

        AsyncRuntime::run(function (): void {
            $transport = new StdioTransport(
                command: PHP_BINARY,
                args: [self::$serverScript],
            );
            try {
                $transport->start();
                $transport->request('initialize', [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => [],
                    'clientInfo' => ['name' => 't', 'version' => '0'],
                ]);
                $transport->notify('notifications/initialized');

                // Fire two sequential calls; ids must route to correct futures.
                $r1 = $transport->request('tools/list');
                $r2 = $transport->request('tools/list');
                self::assertSame('add_numbers', $r1['tools'][0]['name']);
                self::assertSame('add_numbers', $r2['tools'][0]['name']);
            } finally {
                $transport->stop();
            }
        });
    }

    public function test_manager_end_to_end_stdio(): void
    {
        if (!self::childStdoutReachable()) {
            self::markTestSkipped('Windows host breaks proc_open stdout pipe inheritance; see lifecycle test.');
        }

        AsyncRuntime::run(function (): void {
            $registry = new \LightAgent\Tools\ToolRegistry();
            $manager = new \LightAgent\Mcp\MCPClientManager(
                [
                    'mcpServers' => [
                        'test' => [
                            'command' => PHP_BINARY,
                            'args' => [self::$serverScript],
                        ],
                    ],
                ],
                $registry,
            );

            self::assertTrue($manager->registerMcpTool());

            // Registered under raw name (no namespace config)
            self::assertArrayHasKey('add_numbers', $registry->functionMappings);
            $schema = $registry->openaiFunctionSchemas[0];
            self::assertSame('add_numbers', $schema['function']['name']);
            self::assertSame(['a', 'b'], $schema['function']['parameters']['required']);

            // Validate registered tool_info parsed from inputSchema
            $info = $registry->functionInfo['add_numbers'];
            self::assertSame('Add two numbers', $info['tool_description']);
            self::assertSame('number', $info['tool_params'][0]['type']);
            self::assertTrue($info['tool_params'][0]['required']);

            // Call through the registered tool mapping
            $tool = $registry->functionMappings['add_numbers'];
            $response = $tool->invoke(['a' => 1, 'b' => 2]);
            self::assertSame(
                ['server' => 'test', 'tool' => 'add_numbers', 'result' => '{"sum":3}'],
                $response,
            );
        });
    }

    /**
     * Write a throwaway MCP server script into the system temp directory.
     */
    private static function writeFixture(string $code): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mcp_fix_') . '.php';
        file_put_contents($path, $code);
        return $path;
    }

    /**
     * C-3: a server that dies must not take the whole process down. The
     * orphaned deferred used to escalate into an UnhandledFutureError that
     * was rethrown from the event loop and killed the agent.
     */
    public function test_server_death_yields_a_clean_exception(): void
    {
        if (!self::childStdoutReachable()) {
            self::markTestSkipped('Windows host breaks proc_open stdout pipe inheritance; see lifecycle test.');
        }

        $script = self::writeFixture('<?php exit(0);');
        try {
            AsyncRuntime::run(static function () use ($script): void {
                $transport = new StdioTransport(
                    command: PHP_BINARY,
                    args: [$script],
                    requestTimeout: 5.0,
                );
                try {
                    $transport->start();
                    usleep(400000); // let the child exit
                    $transport->request('initialize');
                    self::fail('expected an McpException');
                } catch (McpException $e) {
                    self::assertNotSame('', $e->getMessage());
                } finally {
                    $transport->stop();
                }
            });
        } finally {
            @unlink($script);
        }
    }

    /**
     * M-16: the child's stderr has to be drained. An unread stderr pipe
     * fills up and blocks the server inside write(2), so it stops serving
     * MCP requests entirely.
     */
    public function test_stderr_flood_does_not_stall_the_server(): void
    {
        if (!self::childStdoutReachable()) {
            self::markTestSkipped('Windows host breaks proc_open stdout pipe inheritance; see lifecycle test.');
        }

        $script = self::writeFixture(<<<'PHP'
<?php
$line = fgets(STDIN);
$frame = json_decode((string) $line, true);
fwrite(STDERR, str_repeat('E', 1024 * 1024));
fwrite(STDOUT, json_encode(['jsonrpc' => '2.0', 'id' => $frame['id'] ?? null, 'result' => ['ok' => true]]) . "\n");
flush();
PHP);
        try {
            AsyncRuntime::run(static function () use ($script): void {
                $transport = new StdioTransport(
                    command: PHP_BINARY,
                    args: [$script],
                    requestTimeout: 8.0,
                );
                try {
                    $transport->start();
                    $started = microtime(true);
                    $result = $transport->request('initialize');
                    $elapsed = microtime(true) - $started;
                    self::assertTrue($result['ok'] ?? false);
                    self::assertLessThan(5.0, $elapsed, 'the server must not stall on stderr');
                } finally {
                    $transport->stop();
                }
            });
        } finally {
            @unlink($script);
        }
    }

    /**
     * M-17: a request has a deadline, a hung server must not hang the
     * agent forever.
     */
    public function test_request_has_a_deadline(): void
    {
        if (!self::childStdoutReachable()) {
            self::markTestSkipped('Windows host breaks proc_open stdout pipe inheritance; see lifecycle test.');
        }

        $script = self::writeFixture('<?php while (true) { usleep(200000); }');
        try {
            AsyncRuntime::run(static function () use ($script): void {
                $transport = new StdioTransport(
                    command: PHP_BINARY,
                    args: [$script],
                    requestTimeout: 1.0,
                );
                try {
                    $transport->start();
                    $started = microtime(true);
                    $transport->request('initialize');
                    self::fail('expected an McpException');
                } catch (McpException $e) {
                    self::assertStringContainsString('timed out', $e->getMessage());
                    self::assertLessThan(5.0, microtime(true) - $started);
                } finally {
                    $transport->stop();
                }
            });
        } finally {
            @unlink($script);
        }
    }

    /**
     * M-19: stop() has to settle the pending requests. Dropping them left
     * the awaiting fiber suspended forever and the process died with
     * "Event loop terminated without resuming the current suspension".
     */
    public function test_stop_settles_pending_requests(): void
    {
        if (!self::childStdoutReachable()) {
            self::markTestSkipped('Windows host breaks proc_open stdout pipe inheritance; see lifecycle test.');
        }

        $script = self::writeFixture('<?php while (true) { usleep(200000); }');
        try {
            AsyncRuntime::run(static function () use ($script): void {
                $transport = new StdioTransport(
                    command: PHP_BINARY,
                    args: [$script],
                    requestTimeout: 30.0,
                );
                $transport->start();
                $outcome = 'none';
                $worker = async(static function () use ($transport, &$outcome): void {
                    try {
                        $transport->request('initialize');
                        $outcome = 'resolved';
                    } catch (\Throwable $e) {
                        $outcome = $e::class . ': ' . $e->getMessage();
                    }
                });
                delay(0.3);
                $transport->stop();
                $worker->await();
                self::assertStringContainsString('McpException', $outcome);
            });
        } finally {
            @unlink($script);
        }
    }
}
