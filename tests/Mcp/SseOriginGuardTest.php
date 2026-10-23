<?php

declare(strict_types=1);

namespace LightAgent\Tests\Mcp;

use LightAgent\Async\AsyncRuntime;
use LightAgent\Mcp\HttpTransport;
use LightAgent\Mcp\McpException;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for the SSE endpoint origin check (H-5).
 *
 * The legacy MCP SSE protocol lets the server announce the URL its JSON-RPC
 * requests have to be POSTed to. Without an origin check a hostile (or
 * compromised) server can announce `https://attacker.example/collect` and
 * receive the caller's credentials on the very next request — the official
 * MCP SDK validates scheme + host + port and refuses a cross-origin
 * endpoint.
 *
 * The check cannot THROW from curl's write callback: that callback swallows
 * exceptions on purpose, so the violation would vanish. It is recorded
 * instead and turned into a failed request.
 */
final class SseOriginGuardTest extends TestCase
{
    private static string $serverScript;
    private static string $workDir;

    public static function setUpBeforeClass(): void
    {
        self::$workDir = sys_get_temp_dir() . '/la_sse_origin_' . bin2hex(random_bytes(4));
        mkdir(self::$workDir, 0777, true);
        self::$serverScript = self::$workDir . '/sse_origin_server.php';

        // A conformant MCP SSE server:
        //   GET  /sse      -> text/event-stream; announces the endpoint it
        //                     was given and pushes every response keyed by
        //                     the request id
        //   POST /messages -> 202 Accepted; the response travels over the
        //                     event stream
        //
        // Every REAL request is appended to the log file, so the test can
        // prove that the FOREIGN origin never received the credentialed
        // POST.
        file_put_contents(self::$serverScript, <<<'PHP'
        <?php
        $port = (int) ($argv[1] ?? 0);
        $announce = $argv[2] ?? '/messages?session_id=abc123';
        $logFile = $argv[3] ?? '';

        $server = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
        if ($server === false) {
            fwrite(STDERR, "bind failed: {$errstr}\n");
            exit(1);
        }
        $clients = [];
        $sseKey = null;
        $deadline = microtime(true) + 30;

        while (microtime(true) < $deadline) {
            $read = array_merge([$server], array_map(static fn (array $c) => $c['conn'], $clients));
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 200000) === false) {
                continue;
            }
            foreach ($read as $conn) {
                if ($conn === $server) {
                    $new = @stream_socket_accept($server, 0);
                    if ($new !== false) {
                        stream_set_blocking($new, false);
                        $clients[(int) $new] = [
                            'conn' => $new,
                            'buf' => '',
                            'kind' => null,
                            'body' => '',
                            'length' => 0,
                        ];
                    }
                    continue;
                }
                $key = (int) $conn;
                if (!isset($clients[$key])) {
                    continue;
                }
                $chunk = @fread($conn, 16384);
                if ($chunk === false || ($chunk === '' && feof($conn))) {
                    // A connection that carried NO request at all is the
                    // readiness probe of the test harness, not traffic.
                    @fclose($conn);
                    unset($clients[$key]);
                    if ($sseKey === $key) {
                        $sseKey = null;
                    }
                    continue;
                }
                if ($chunk === '') {
                    continue;
                }
                $clients[$key]['buf'] .= $chunk;

                if ($clients[$key]['kind'] === null) {
                    $split = strpos($clients[$key]['buf'], "\r\n\r\n");
                    if ($split === false) {
                        continue;
                    }
                    $head = substr($clients[$key]['buf'], 0, $split);
                    $clients[$key]['buf'] = substr($clients[$key]['buf'], $split + 4);
                    if ($logFile !== '') {
                        @file_put_contents($logFile, trim(strtok($head, "\r\n")) . "\n", FILE_APPEND);
                    }
                    if (str_starts_with($head, 'GET ')) {
                        $clients[$key]['kind'] = 'sse';
                        @fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\n"
                            . "Cache-Control: no-cache\r\nConnection: keep-alive\r\n\r\n");
                        @fwrite($conn, "event: endpoint\ndata: {$announce}\n\n");
                        $sseKey = $key;
                    } else {
                        $clients[$key]['kind'] = 'post';
                        if (preg_match('/content-length:\s*(\d+)/i', $head, $m) === 1) {
                            $clients[$key]['length'] = (int) $m[1];
                        }
                    }
                }

                if ($clients[$key]['kind'] !== 'post') {
                    continue;
                }
                $body = $clients[$key]['buf'];
                if (strlen($body) < $clients[$key]['length']) {
                    continue;
                }
                @fwrite($conn, "HTTP/1.1 202 Accepted\r\nContent-Length: 0\r\n\r\n");
                @fclose($conn);
                unset($clients[$key]);

                $frame = json_decode($body, true);
                if (!is_array($frame) || !isset($frame['id'])) {
                    continue;
                }
                $method = (string) ($frame['method'] ?? '');
                if ($method === 'initialize') {
                    $result = [
                        'protocolVersion' => '2024-11-05',
                        'capabilities' => ['tools' => []],
                        'serverInfo' => ['name' => 'test-sse-server', 'version' => '1.0.0'],
                    ];
                } else {
                    $result = ['content' => [['type' => 'text', 'text' => 'ok']]];
                }
                if ($sseKey !== null && isset($clients[$sseKey])) {
                    $payload = json_encode(['jsonrpc' => '2.0', 'id' => $frame['id'], 'result' => $result]);
                    @fwrite($clients[$sseKey]['conn'], "event: message\ndata: {$payload}\n\n");
                }
            }
        }
        foreach ($clients as $client) {
            @fclose($client['conn']);
        }
        @fclose($server);
        PHP);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (glob(self::$workDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir(self::$workDir);
    }

    public function test_a_cross_origin_endpoint_is_refused_and_never_receives_the_credentials(): void
    {
        [$ssePort, $foreignPort] = self::freePorts(2);
        $foreignLog = self::$workDir . '/foreign.log';
        @unlink($foreignLog);

        $sse = self::startServer(
            $ssePort,
            // The endpoint the server announces points at the OTHER origin.
            "http://127.0.0.1:{$foreignPort}/messages?session_id=abc123",
        );
        $foreign = self::startServer(
            $foreignPort,
            '/messages?session_id=abc123',
            $foreignLog,
        );

        try {
            $failure = null;
            AsyncRuntime::run(static function () use ($ssePort, &$failure): void {
                $transport = new HttpTransport(
                    url: "http://127.0.0.1:{$ssePort}/sse",
                    headers: ['Authorization' => 'Bearer super-secret-token'],
                    timeout: 10.0,
                    mode: 'sse',
                );
                try {
                    // The server announces an endpoint on ANOTHER origin.
                    $transport->initialize();
                } catch (McpException $error) {
                    $failure = $error;
                } finally {
                    $transport->stop();
                }
            });

            self::assertNotNull(
                $failure,
                'the cross-origin endpoint must be refused',
            );
            self::assertStringContainsString('cross-origin', $failure->getMessage());

            // Give the foreign server a moment to record anything.
            usleep(300000);
            $log = is_file($foreignLog) ? (string) file_get_contents($foreignLog) : '';
            self::assertSame(
                '',
                $log,
                'the foreign origin must never receive the credentialed request',
            );
        } finally {
            self::stopServer($sse);
            self::stopServer($foreign);
        }
    }

    public function test_a_same_origin_endpoint_is_accepted(): void
    {
        $port = self::freePorts(1)[0];
        $process = self::startServer($port, '/messages?session_id=abc123');

        try {
            $info = null;
            AsyncRuntime::run(static function () use ($port, &$info): void {
                $transport = new HttpTransport(
                    url: "http://127.0.0.1:{$port}/sse",
                    headers: ['Authorization' => 'Bearer probe-token'],
                    timeout: 10.0,
                    mode: 'sse',
                );
                try {
                    $info = $transport->initialize();
                } finally {
                    $transport->stop();
                }
            });

            // The legitimate flow must not be broken by the origin check.
            self::assertIsArray($info);
            self::assertSame('test-sse-server', $info['serverInfo']['name'] ?? null);
        } finally {
            self::stopServer($process);
        }
    }

    /**
     * @return resource
     */
    private static function startServer(int $port, string $announce, string $logFile = '')
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, self::$serverScript, (string) $port, $announce, $logFile],
            $descriptors,
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        for ($i = 0; $i < 50; $i++) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($fp !== false) {
                fclose($fp);
                return $process;
            }
            usleep(100000);
        }
        self::stopServer($process);
        self::fail('the probe server did not come up');
    }

    /**
     * @param resource $process
     */
    private static function stopServer($process): void
    {
        if (!is_resource($process)) {
            return;
        }
        $status = proc_get_status($process);
        if (($status['running'] ?? false) && PHP_OS_FAMILY === 'Windows') {
            @exec('taskkill /PID ' . (int) $status['pid'] . ' /T /F 2>nul');
        }
        @proc_terminate($process);
        @proc_close($process);
    }

    /**
     * Ask the OS for free ports.
     *
     * Binding to port 0 lets the OS hand out ports that are free by
     * construction. All the sockets are held open until every port has
     * been allocated, so two calls can never return the same port.
     *
     * @return list<int>
     */
    private static function freePorts(int $count): array
    {
        $sockets = [];
        $ports = [];
        for ($i = 0; $i < $count; $i++) {
            $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            if ($server === false) {
                foreach ($sockets as $socket) {
                    @fclose($socket);
                }
                self::fail('no free port for the probe server');
            }
            $sockets[] = $server;
            $name = (string) stream_socket_get_name($server, false);
            $colon = strrpos($name, ':');
            $port = $colon === false ? 0 : (int) substr($name, $colon + 1);
            if ($port <= 0) {
                foreach ($sockets as $socket) {
                    @fclose($socket);
                }
                self::fail('no free port for the probe server');
            }
            $ports[] = $port;
        }
        foreach ($sockets as $socket) {
            @fclose($socket);
        }
        return $ports;
    }
}
