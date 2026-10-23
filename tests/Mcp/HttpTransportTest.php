<?php

declare(strict_types=1);

namespace LightAgent\Tests\Mcp;

use LightAgent\Async\AsyncRuntime;
use LightAgent\Mcp\HttpTransport;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

/**
 * HTTP transport regression tests.
 *
 *  H-15: the legacy MCP SSE protocol must complete `initialize()`. The
 *        previous implementation buffered the whole event stream and only
 *        returned when the transfer ENDED — a conformant SSE server keeps
 *        the stream open, so the handshake always failed with a 5 s
 *        timeout.
 *  H-16: an MCP HTTP call must not freeze the sibling fibers of the
 *        agent. The blocking `curl_exec()` froze the whole event loop for
 *        the duration of the request.
 */
final class HttpTransportTest extends TestCase
{
    private static string $serverScript;
    private static ?string $serverLog = null;

    public static function setUpBeforeClass(): void
    {
        $dir = sys_get_temp_dir() . '/mcp_http_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        self::$serverScript = $dir . '/sse_server.php';

        // A minimal but conformant MCP SSE server:
        //   GET  /sse      -> text/event-stream, announces the endpoint and
        //                     pushes every response keyed by the request id
        //   POST /messages -> 202 Accepted, the response travels over the
        //                     event stream
        //   POST /slow     -> a JSON answer after three seconds
        file_put_contents(self::$serverScript, <<<'PHP'
<?php
$port = (int) ($argv[1] ?? 0);
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
    $n = @stream_select($read, $write, $except, 0, 200000);
    if ($n === false || $n === 0) {
        continue;
    }

    foreach ($read as $conn) {
        if ($conn === $server) {
            $new = @stream_socket_accept($server, 0);
            if ($new !== false) {
                stream_set_blocking($new, false);
                $clients[(int) $new] = ['conn' => $new, 'buf' => '', 'kind' => null, 'body' => '', 'length' => 0, 'path' => ''];
            }
            continue;
        }

        $key = (int) $conn;
        if (!isset($clients[$key])) {
            continue;
        }
        $chunk = @fread($conn, 16384);
        if ($chunk === false || ($chunk === '' && feof($conn))) {
            fclose($conn);
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
            if (str_starts_with($head, 'GET ')) {
                $clients[$key]['kind'] = 'sse';
                @fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\n"
                    . "Cache-Control: no-cache\r\nConnection: keep-alive\r\n\r\n");
                @fwrite($conn, "event: endpoint\ndata: /messages?session_id=abc123\n\n");
                $sseKey = $key;
            } else {
                $clients[$key]['kind'] = 'post';
                $parts = explode(' ', explode("\r\n", $head)[0] ?? '');
                $clients[$key]['path'] = $parts[1] ?? '';
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

        if ($clients[$key]['path'] === '/slow') {
            sleep(3);
            $frame = json_decode($body, true);
            $payload = json_encode([
                'jsonrpc' => '2.0',
                'id' => is_array($frame) ? ($frame['id'] ?? null) : null,
                'result' => ['slow' => true],
            ]);
            @fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: "
                . strlen((string) $payload) . "\r\n\r\n" . $payload);
            fclose($conn);
            unset($clients[$key]);
            continue;
        }

        @fwrite($conn, "HTTP/1.1 202 Accepted\r\nContent-Length: 0\r\n\r\n");
        fclose($conn);
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
        } elseif ($method === 'tools/list') {
            $result = ['tools' => [['name' => 'add_numbers', 'description' => 'Add', 'inputSchema' => [
                'type' => 'object',
                'properties' => ['a' => ['type' => 'number'], 'b' => ['type' => 'number']],
                'required' => ['a', 'b'],
            ]]]];
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
fclose($server);
PHP);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (glob(dirname(self::$serverScript) . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir(dirname(self::$serverScript));
    }

    public function test_sse_initialize_completes_against_a_conformant_server(): void
    {
        $port = self::freePort();
        $process = self::startServer($port);
        try {
            AsyncRuntime::run(static function () use ($port): void {
                $transport = new HttpTransport(
                    url: "http://127.0.0.1:{$port}/sse",
                    headers: ['Authorization' => 'Bearer probe-token'],
                    timeout: 10.0,
                    mode: 'sse',
                );
                $started = microtime(true);
                try {
                    $info = $transport->initialize();
                    self::assertSame('test-sse-server', $info['serverInfo']['name'] ?? null);
                    self::assertLessThan(5.0, microtime(true) - $started, 'the SSE handshake must not time out');

                    // The response of the legacy SSE protocol arrives over
                    // the event stream, keyed by the request id.
                    $tools = $transport->request('tools/list');
                    self::assertSame(['add_numbers'], array_column($tools['tools'] ?? [], 'name'));
                } finally {
                    $transport->stop();
                }
            });
        } finally {
            self::stopServer($process);
        }
    }

    public function test_http_call_does_not_block_sibling_fibers(): void
    {
        $port = self::freePort();
        $process = self::startServer($port);
        try {
            AsyncRuntime::run(static function () use ($port): void {
                $transport = new HttpTransport(
                    url: "http://127.0.0.1:{$port}/slow",
                    timeout: 20.0,
                    mode: 'streamable-http',
                );

                $timerAt = null;
                $started = microtime(true);
                EventLoop::delay(1.0, static function () use (&$timerAt): void {
                    $timerAt = microtime(true);
                });

                try {
                    $transport->request('tools/list');
                } finally {
                    $transport->stop();
                }

                self::assertNotNull($timerAt, 'the timer must have fired');
                self::assertLessThan(
                    1.6,
                    $timerAt - $started,
                    'the MCP HTTP call must not freeze the event loop',
                );
            });
        } finally {
            self::stopServer($process);
        }
    }

    /**
     * @return resource|null
     */
    private static function startServer(int $port)
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, self::$serverScript, (string) $port],
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
        // proc_close() waits for the child, so a child that survived the
        // kill would block the test run: terminate it explicitly as well.
        @proc_terminate($process);
        @proc_close($process);
    }

    private static function freePort(): int
    {
        for ($port = 24600; $port < 24900; $port++) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($fp === false) {
                return $port;
            }
            fclose($fp);
        }
        self::fail('no free port for the probe server');
    }
}
