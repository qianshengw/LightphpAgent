<?php

declare(strict_types=1);

namespace LightAgent\Tests\Llm;

use LightAgent\Llm\LlmException;
use LightAgent\Llm\OpenAiClient;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that the streaming transport delivers chunks progressively.
 *
 * The mock server emits one SSE event every 250ms. If the client buffers the
 * whole response body before parsing it, every chunk is observed at the same
 * (final) instant. If the transport streams, the first chunk arrives almost
 * immediately and the last one roughly a second later.
 *
 * @group integration
 */
final class StreamingTransportTest extends TestCase
{
    private const HOST = '127.0.0.1';
    private const PORT = 18931;
    private const CHUNK_DELAY_US = 250000;
    private const CHUNK_COUNT = 5;

    private static ?int $serverPid = null;
    private static $serverProc = null;

    public static function setUpBeforeClass(): void
    {
        $router = sys_get_temp_dir() . '/lightagent_streaming_transport_router.php';
        file_put_contents($router, <<<'PHP'
<?php
$uri = $_SERVER['REQUEST_URI'] ?? '/';
if (str_contains($uri, 'case=error401')) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => ['message' => 'invalid api key']]);
    exit;
}
if (str_contains($uri, 'case=plain')) {
    header('Content-Type: application/json');
    echo json_encode([
        'id' => 'chatcmpl-plain',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'plain answer'], 'finish_reason' => 'stop']],
    ]);
    exit;
}
header('Content-Type: text/event-stream');
for ($i = 1; $i <= 5; $i++) {
    echo 'data: ' . json_encode([
        'id' => 'chatcmpl-stream',
        'choices' => [['index' => 0, 'delta' => ['content' => "chunk{$i} "], 'finish_reason' => null]],
    ]) . "\n\n";
    flush();
    usleep(250000);
}
echo "data: [DONE]\n\n";
flush();
exit;
PHP);

        // output_buffering=0 is required: the CLI server SAPI buffers stdout
        // by default, which would mask the client's own streaming behaviour.
        $cmd = [
            PHP_BINARY,
            '-d', 'output_buffering=0',
            '-d', 'implicit_flush=1',
            '-S', self::HOST . ':' . self::PORT,
            $router,
        ];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            self::fail('failed to start test HTTP server');
        }
        foreach ($pipes as $p) {
            fclose($p);
        }
        self::$serverPid = proc_get_status($proc)['pid'];
        self::$serverProc = $proc;

        for ($i = 0; $i < 50; $i++) {
            $fp = @fsockopen(self::HOST, self::PORT, $errno, $errstr, 0.2);
            if ($fp !== false) {
                fclose($fp);
                return;
            }
            usleep(100000);
        }
        self::fail('test HTTP server did not become ready');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverPid !== null) {
            exec('taskkill /PID ' . self::$serverPid . ' /T /F 2>nul', $out, $code);
            if (self::$serverProc !== null && is_resource(self::$serverProc)) {
                proc_terminate(self::$serverProc);
                proc_close(self::$serverProc);
            }
            self::$serverPid = null;
            self::$serverProc = null;
        }
    }

    private function client(string $case = 'stream'): OpenAiClient
    {
        return new OpenAiClient(
            apiKey: 'test-key',
            baseUrl: 'http://' . self::HOST . ':' . self::PORT . '/v1?case=' . $case,
            timeout: 30,
        );
    }

    public function test_chunks_are_delivered_incrementally(): void
    {
        $stream = $this->client('stream')->chat()->create([
            'model' => 'test-model',
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'stream' => true,
        ]);

        $arrivals = [];
        $start = microtime(true);
        foreach ($stream as $chunk) {
            $delta = $chunk->choices[0]['delta'] ?? [];
            if (($delta['content'] ?? null) === null) {
                continue;
            }
            $arrivals[] = (microtime(true) - $start) * 1000;
        }

        self::assertCount(self::CHUNK_COUNT, $arrivals, 'expected every content chunk to be observed');

        $first = $arrivals[0];
        $last = $arrivals[count($arrivals) - 1];
        $spread = $last - $first;

        // The server spaces chunks 250ms apart, so the first and last content
        // chunk are ~1s apart. A buffered transport would collapse them.
        self::assertGreaterThan(
            500.0,
            $spread,
            sprintf('chunks were not delivered incrementally (first=%.1fms last=%.1fms)', $first, $last),
        );
        self::assertLessThan(
            500.0,
            $first,
            sprintf('first chunk did not arrive promptly (%.1fms)', $first),
        );
    }

    public function test_stream_content_is_assembled_in_order(): void
    {
        $stream = $this->client('stream')->chat()->create([
            'model' => 'test-model',
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'stream' => true,
        ]);

        $text = '';
        foreach ($stream as $chunk) {
            $delta = $chunk->choices[0]['delta'] ?? [];
            $text .= (string) ($delta['content'] ?? '');
        }

        self::assertSame('chunk1 chunk2 chunk3 chunk4 chunk5 ', $text);
    }

    public function test_http_error_on_stream_maps_to_llm_exception(): void
    {
        $client = $this->client('error401');
        $stream = $client->chat()->create([
            'model' => 'test-model',
            'messages' => [['role' => 'user', 'content' => 'x']],
            'stream' => true,
        ]);

        $this->expectException(LlmException::class);
        foreach ($stream as $_) {
            // The exception surfaces while consuming the stream.
        }
    }

    public function test_abandoning_the_stream_early_closes_cleanly(): void
    {
        $client = $this->client('stream');
        $stream = $client->chat()->create([
            'model' => 'test-model',
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'stream' => true,
        ]);

        $seen = 0;
        foreach ($stream as $_) {
            $seen++;
            if ($seen === 1) {
                // Simulate the user interrupting: abandon the generator while
                // the transfer is still in flight.
                break;
            }
        }
        unset($stream);

        self::assertSame(1, $seen);

        // A fresh client must remain usable afterwards (no leaked handle/state).
        $completion = $this->client('plain')->chat()->create([
            'model' => 'test-model',
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'stream' => false,
        ]);
        self::assertInstanceOf(\LightAgent\Llm\ChatCompletion::class, $completion);
    }
}
