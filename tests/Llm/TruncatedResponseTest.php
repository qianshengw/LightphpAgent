<?php

declare(strict_types=1);

namespace LightAgent\Tests\Llm;

use LightAgent\Llm\LlmException;
use LightAgent\Llm\OpenAiClient;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for the truncated-response handling (H-1).
 *
 * curl reports "transfer closed with N bytes remaining to read" as
 * CURLE_PARTIAL_FILE and hands back the partial body, which is a VALID
 * prefix of the answer — in the worst case even valid JSON. Without the
 * errno check a cut-off response was indistinguishable from a complete one,
 * so the agent returned the truncated answer as a successful run.
 *
 * (The Python original gets this for free: the official SDK raises
 * APIConnectionError.)
 */
final class TruncatedResponseTest extends TestCase
{
    private static string $serverScript;
    private static string $workDir;

    public static function setUpBeforeClass(): void
    {
        self::$workDir = sys_get_temp_dir() . '/la_trunc_' . bin2hex(random_bytes(4));
        mkdir(self::$workDir, 0777, true);
        self::$serverScript = self::$workDir . '/trunc_server.php';

        // A server that announces a Content-Length it never fulfils and
        // then closes the connection — a cut-off response.
        file_put_contents(self::$serverScript, <<<'PHP'
        <?php
        $port = (int) ($argv[1] ?? 0);
        $server = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
        if ($server === false) {
            fwrite(STDERR, "bind failed: {$errstr}\n");
            exit(1);
        }
        $deadline = microtime(true) + 30;
        while (microtime(true) < $deadline) {
            $read = [$server];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 200000) === false) {
                continue;
            }
            $conn = @stream_socket_accept($server, 1);
            if ($conn === false) {
                continue;
            }
            // Read the request headers.
            $buf = '';
            while (!str_contains($buf, "\r\n\r\n")) {
                $chunk = @fread($conn, 4096);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $buf .= $chunk;
            }
            // Announce 4096 bytes, deliver 64, then hang up.
            @fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n"
                . "Content-Length: 4096\r\n\r\n");
            @fwrite($conn, '{"choices":[{"message":{"content":"partial');
            @fclose($conn);
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

    public function test_a_truncated_response_is_reported_as_a_failure(): void
    {
        $port = self::freePort();
        $process = self::startServer($port);
        try {
            $client = new OpenAiClient(
                apiKey: 'test-key',
                baseUrl: "http://127.0.0.1:{$port}/v1",
                timeout: 10.0,
            );

            $this->expectException(LlmException::class);
            $this->expectExceptionMessageMatches('/truncated response/');
            $client->rawRequest([
                'model' => 'gpt-4o-mini',
                'messages' => [['role' => 'user', 'content' => 'hi']],
            ]);
        } finally {
            self::stopServer($process);
        }
    }

    public function test_a_truncated_stream_is_reported_as_a_failure(): void
    {
        $port = self::freePort();
        $process = self::startServer($port);
        try {
            $client = new OpenAiClient(
                apiKey: 'test-key',
                baseUrl: "http://127.0.0.1:{$port}/v1",
                timeout: 10.0,
            );

            $failure = null;
            try {
                foreach ($client->streamRequest([
                    'model' => 'gpt-4o-mini',
                    'messages' => [['role' => 'user', 'content' => 'hi']],
                    'stream' => true,
                ]) as $_payload) {
                    // consume
                }
            } catch (LlmException $error) {
                $failure = $error;
            }

            self::assertNotNull($failure, 'a truncated stream must not be reported as a success');
            self::assertStringContainsString('truncated response', $failure->getMessage());
        } finally {
            self::stopServer($process);
        }
    }

    /**
     * @return resource
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
        @proc_terminate($process);
        @proc_close($process);
    }

    /**
     * Ask the OS for a free port.
     *
     * Binding to port 0 lets the OS hand out a port that is free by
     * construction — far more reliable than probing a port range.
     */
    private static function freePort(): int
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            self::fail('no free port for the probe server');
        }
        $name = (string) stream_socket_get_name($server, false);
        @fclose($server);
        $colon = strrpos($name, ':');
        $port = $colon === false ? 0 : (int) substr($name, $colon + 1);
        if ($port <= 0) {
            self::fail('no free port for the probe server');
        }
        return $port;
    }
}
