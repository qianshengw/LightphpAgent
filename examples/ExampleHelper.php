<?php

declare(strict_types=1);

/**
 * Shared example helpers: local OpenAI-compatible mock server + common tools.
 *
 * Every example runs fully offline against a local HTTP mock, so no API key
 * is required. The mock answers in two phases (stream tool-call leg and a
 * plain final leg) to exercise the complete agent tool loop.
 */

final class ExampleHelper
{
    public const HOST = '127.0.0.1';
    public static int $port = 19001;

    /** @var resource|null */
    private static $server = null;
    private static ?string $routerPath = null;

    /**
     * Start the local mock provider. Returns the base URL to use as base_url.
     */
    public static function startMockServer(string $case = 'tools'): string
    {
        self::$routerPath = sys_get_temp_dir() . '/la_example_router.php';
        $finalContent = 'Mock answer: the requested operation completed successfully.';
        file_put_contents(self::$routerPath, <<<PHP
<?php
\$body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
\$uri = \$_SERVER['REQUEST_URI'] ?? '/';
\$wantsStream = !empty(\$body['stream']);
if (\$wantsStream) {
    header('Content-Type: text/event-stream');
    \$toolName = '{$case}_tool';
    if ('{$case}' === 'tools') { \$toolName = 'get_weather'; }
    \$chunks = [
        ['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_ex', 'function' => ['name' => \$toolName, 'arguments' => '{"city_name":']]]], 'finish_reason' => null],
        ['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '"mock-value"}']]]], 'finish_reason' => null],
        ['delta' => [], 'finish_reason' => 'tool_calls'],
    ];
    foreach (\$chunks as \$c) {
        echo 'data: ' . json_encode(['id' => 'chatcmpl-ex', 'choices' => [\$c]]) . "\\n\\n";
        flush();
        usleep(15000);
    }
    echo "data: [DONE]\\n\\n";
    flush();
    exit;
}
header('Content-Type: application/json');
echo json_encode([
    'id' => 'chatcmpl-final',
    'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => '{$finalContent}'], 'finish_reason' => 'stop']],
    'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 10, 'total_tokens' => 18],
]);
PHP);

        // Find a free port
        $port = self::$port;
        while (true) {
            $fp = @fsockopen(self::HOST, $port, $errno, $errstr, 0.2);
            if ($fp === false) {
                break;
            }
            fclose($fp);
            $port++;
        }
        self::$port = $port;

        $cmd = [PHP_BINARY, '-S', self::HOST . ':' . $port, self::$routerPath];
        self::$server = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        foreach ($pipes as $p) {
            fclose($p);
        }
        for ($i = 0; $i < 50; $i++) {
            $fp = @fsockopen(self::HOST, $port, $errno, $errstr, 0.2);
            if ($fp !== false) {
                fclose($fp);
                return 'http://' . self::HOST . ':' . $port . '/v1?case=' . $case;
            }
            usleep(100000);
        }
        throw new \RuntimeException('mock server failed to start');
    }

    public static function stopMockServer(): void
    {
        if (self::$server !== null) {
            $status = proc_get_status(self::$server);
            if (($status['running'] ?? false) && PHP_OS_FAMILY === 'Windows') {
                // `php -S` is started through proc_open with bypass_shell, so
                // the PID is the server itself; /T also reaps its children.
                @exec('taskkill /PID ' . (int) $status['pid'] . ' /T /F 2>nul');
            }
            // proc_close() waits for the child, so a child that survived the
            // kill would block the script: terminate it explicitly first.
            // (On non-Windows platforms `taskkill` does not exist at all —
            // without this the example would hang forever on Linux.)
            @proc_terminate(self::$server);
            @proc_close(self::$server);
            self::$server = null;
        }
        if (self::$routerPath !== null && is_file(self::$routerPath)) {
            unlink(self::$routerPath);
        }
    }

    public static function header(string $title): void
    {
        echo "\n=== {$title} ===\n";
    }

    public static function done(string $name): void
    {
        echo "\n[example {$name} completed]\n";
    }
}
