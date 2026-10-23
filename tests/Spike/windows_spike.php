<?php

declare(strict_types=1);

require __DIR__ . '/../../autoload.php';

use Amp\Future;
use LightAgent\Async\Async;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\delay;

$fail = static function (string $msg): never {
    fwrite(STDERR, "SPIKE FAIL: {$msg}\n");
    exit(1);
};

$log = static function (string $msg): void {
    fwrite(STDOUT, $msg . "\n");
};

// Spike 1: basic async + Fiber scheduling
Async::run(static function () use ($log, $fail): void {
    $futures = [];
    foreach ([1, 2, 3] as $i) {
        $futures[] = async(static function () use ($i): int {
            delay(0.01 * $i);
            return $i * 10;
        });
    }

    $results = Future\await($futures);
    if ($results !== [10, 20, 30]) {
        $fail('await results mismatch: ' . json_encode($results));
    }
    $log('[spike1] async/await OK: ' . json_encode($results));
});

// Spike 2: cancellation + timeout
Async::run(static function () use ($log, $fail): void {
    $started = Amp\now();
    try {
        (async(static function (): void {
            delay(5);
        }))->await(new Amp\TimeoutCancellation(0.05));
        $fail('timeout did not fire');
    } catch (Amp\CancelledException) {
        $elapsed = Amp\now() - $started;
        if ($elapsed > 0.5) {
            $fail('cancellation too slow: ' . $elapsed);
        }
        $log('[spike2] cancellation/timeout OK (' . sprintf('%.3fs', $elapsed) . ')');
    }
});

// Spike 3: EventLoop stream readability on Windows pipes (proc_open interop)
Async::run(static function () use ($log, $fail): void {
    // Use a temp script file: inline `-r` quoting is unreliable through cmd.exe on Windows.
    $childScript = tempnam(sys_get_temp_dir(), 'spike_child_') . '.php';
    file_put_contents($childScript, '<?php echo "hello-from-child";');
    $cmd = [PHP_BINARY, $childScript];

    $proc = proc_open(
        $cmd,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true],
    );
    if (!is_resource($proc)) {
        $fail('proc_open failed');
    }

    stream_set_blocking($pipes[1], false);

    $data = '';
    $done = false;
    $suspension = EventLoop::getSuspension();
    $readId = '';
    $finished = false;

    $finish = static function () use (&$data, &$done, &$finished, $suspension, $pipes, $proc, $readId, $childScript, $log, $fail): void {
        if ($finished) {
            return;
        }
        $finished = true;
        $done = true;
        if ($readId !== '') {
            EventLoop::cancel($readId);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        unlink($childScript);
        $suspension->resume(null);
    };

    $readId = EventLoop::onReadable($pipes[1], static function () use (&$data, &$done, $finish, $pipes): void {
        if ($done) {
            return;
        }
        $chunk = fread($pipes[1], 8192);
        if (is_string($chunk) && $chunk !== '') {
            $data .= $chunk;
        }
        if (feof($pipes[1])) {
            $finish();
        }
    });

    EventLoop::delay(5, static function () use (&$done, $fail): void {
        if (!$done) {
            $fail('pipe read timed out after 5s');
        }
    });

    $suspension->suspend();

    if (!$finished) {
        $fail('pipe read never finished');
    }
    if ($data !== 'hello-from-child') {
        $fail('pipe read mismatch: ' . var_export($data, true));
    }
    $log('[spike3] proc_open + onReadable OK: ' . $data);
});

$log('[spike] ALL PASSED on ' . PHP_OS_FAMILY . ' / PHP ' . PHP_VERSION);
