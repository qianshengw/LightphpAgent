<?php

declare(strict_types=1);

namespace LightAgent\Tests\Llm;

use LightAgent\Llm\LlmException;
use LightAgent\Llm\OpenAiClient;
use LightAgent\Llm\ToolCallAssembler;
use LightAgent\Async\AsyncRuntime;
use PHPUnit\Framework\TestCase;
use function Amp\async;
use function Amp\delay;

/**
 * End-to-end streaming test against a local HTTP server speaking
 * OpenAI-compatible SSE. This exercises the real curl transport.
 *
 * @group integration
 */
final class OpenAiClientStreamTest extends TestCase
{
    private const HOST = '127.0.0.1';
    private const PORT = 18923;

    private static ?int $serverPid = null;
    private static $serverProc = null;

    public static function setUpBeforeClass(): void
    {
        // Launch a minimal PHP built-in server speaking OpenAI-compatible API.
        // Routing is by query string (?case=plain|stream|error401), which the
        // client appends via baseUrl (e.g. http://host:port/v1?case=plain).
        $router = sys_get_temp_dir() . '/lightagent_test_router.php';
        file_put_contents($router, <<<'PHP'
<?php
$uri = $_SERVER['REQUEST_URI'] ?? '/';
header('Content-Type: application/json');
if (str_contains($uri, 'case=stream')) {
    header('Content-Type: text/event-stream');
    $chunks = [
        ['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_e2e', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":']]]], 'finish_reason' => null],
        ['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => ' "Shanghai"}']]]], 'finish_reason' => null],
        ['delta' => [], 'finish_reason' => 'tool_calls'],
        ['delta' => [], 'finish_reason' => null], // usage-only tail
    ];
    $i = 0;
    foreach ($chunks as $c) {
        $payload = ['id' => 'chatcmpl-e2e', 'choices' => [$c]];
        if ($i === count($chunks) - 1) {
            $payload['usage'] = ['prompt_tokens' => 12, 'completion_tokens' => 3, 'total_tokens' => 15];
            unset($payload['choices']);
        }
        echo 'data: ' . json_encode($payload) . "\n\n";
        flush();
        usleep(20000);
        $i++;
    }
    echo "data: [DONE]\n\n";
    flush();
    exit;
}
if (str_contains($uri, 'case=error401')) {
    http_response_code(401);
    echo json_encode(['error' => ['message' => 'invalid api key']]);
    exit;
}
if (str_contains($uri, 'case=plain')) {
    echo json_encode([
        'id' => 'chatcmpl-plain',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'plain answer'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 2, 'total_tokens' => 3],
    ]);
    exit;
}
http_response_code(500);
echo 'boom';
PHP);
        // php -S with a router; spawn detached
        $cmd = [PHP_BINARY, '-S', self::HOST . ':' . self::PORT, $router];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            self::fail('failed to start test HTTP server');
        }
        // Close our ends of the server's stdout/stderr pipes immediately:
        // keeping them open would make proc_close() block if the server
        // ever writes, and the pipes themselves keep the child referenced.
        foreach ($pipes as $p) {
            fclose($p);
        }
        self::$serverPid = proc_get_status($proc)['pid'];
        self::$serverProc = $proc;
        // Wait for readiness
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
            // Kill by PID (proc_close can hang on pipes); fall back to proc_terminate.
            exec('taskkill /PID ' . self::$serverPid . ' /T /F 2>nul', $out, $code);
            if (self::$serverProc !== null && is_resource(self::$serverProc)) {
                proc_terminate(self::$serverProc);
                proc_close(self::$serverProc);
            }
            self::$serverPid = null;
            self::$serverProc = null;
        }
    }

    private function client(string $case = 'plain'): OpenAiClient
    {
        return new OpenAiClient(
            apiKey: 'test-key',
            baseUrl: 'http://' . self::HOST . ':' . self::PORT . '/v1?case=' . $case,
            timeout: 10,
        );
    }

    public function test_non_streaming_plain_completion(): void
    {
        $completion = $this->client('plain')->chat()->create([
            'model' => 'test-model',
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'stream' => false,
        ]);

        self::assertInstanceOf(\LightAgent\Llm\ChatCompletion::class, $completion);
        self::assertSame('chatcmpl-plain', $completion->id);
        self::assertSame('plain answer', $completion->choices[0]['message']->content);
        self::assertSame(3, $completion->usage['total_tokens']);
    }

    public function test_streaming_chunks_and_tool_assembly(): void
    {
        $stream = $this->client('stream')->chat()->create([
            'model' => 'test-model',
            'messages' => [['role' => 'user', 'content' => 'weather?']],
            'stream' => true,
        ]);

        self::assertIsIterable($stream);

        $assembler = new ToolCallAssembler();
        $chunkCount = 0;
        $lastChunk = null;
        foreach ($stream as $chunk) {
            $chunkCount++;
            $lastChunk = $chunk;
            if (($chunk->choices[0] ?? null) !== null) {
                $assembler->consumeChunk($chunk->choices[0]);
            }
        }

        self::assertSame(4, $chunkCount);
        $calls = $assembler->getToolCalls();
        self::assertCount(1, $calls);
        self::assertSame('get_weather', $calls[0]['name']);
        self::assertSame('{"city": "Shanghai"}', $calls[0]['arguments']);

        // The last chunk is the usage-only tail (no choices);
        // finish_reason arrives on the chunk before it.
        self::assertNotNull($lastChunk);
        self::assertSame([], $lastChunk->choices);
        self::assertNotNull($lastChunk->usage);
        self::assertSame(15, $lastChunk->usage['total_tokens']);
    }

    public function test_http_error_maps_to_llm_exception_with_status(): void
    {
        $this->expectException(LlmException::class);
        $client = $this->client('error401');
        $client->chat()->create([
            'model' => 'test-model',
            'messages' => [['role' => 'user', 'content' => 'x']],
        ]);
    }
}
