<?php

declare(strict_types=1);

namespace LightAgent\Mcp;

use Amp\DeferredFuture;
use Revolt\EventLoop;
use Throwable;

/**
 * Stdio JSON-RPC transport for MCP servers (subprocess with piped stdio).
 *
 * Wire format: newline-delimited JSON-RPC 2.0 frames (matches the framing
 * stdio_client in the Python SDK uses).
 *
 * Runs inside the Amp/Revolt event loop:
 *  - stdout is drained by an `EventLoop::onReadable` watcher,
 *  - stderr is drained by a SECOND watcher into a bounded tail buffer: an
 *    unread stderr pipe fills up and blocks the child forever (M-16),
 *  - stdin is non-blocking; frames are buffered and flushed by an
 *    `EventLoop::onWritable` watcher so a slow reader can never block the
 *    event loop (M-17),
 *  - every request has a deadline, so a hung server cannot hang the agent.
 */
final class StdioTransport
{
    /**
     * Maximum number of bytes buffered from stdout before the peer is
     * considered to have violated the newline-delimited framing (M-18).
     */
    public const MAX_READ_BUFFER = 8 * 1024 * 1024;

    /**
     * Number of stderr bytes kept for diagnostics (tail only).
     */
    public const MAX_STDERR_BUFFER = 64 * 1024;

    /**
     * Default deadline (seconds) for a single JSON-RPC request.
     */
    public const DEFAULT_REQUEST_TIMEOUT = 60.0;

    /** @var resource|null */
    private $proc = null;
    /** @var resource|null */
    private $stdin = null;
    /** @var resource|null */
    private $stdout = null;
    /** @var resource|null */
    private $stderr = null;
    private ?string $readWatcherId = null;
    private ?string $stderrWatcherId = null;
    private ?string $writeWatcherId = null;

    /** Unconsumed stdout bytes (complete lines are cut off at $readOffset). */
    private string $readBuffer = '';
    /** Offset of the first unconsumed byte inside $readBuffer. */
    private int $readOffset = 0;

    /** Bounded tail of the child's stderr output (diagnostics only). */
    private string $stderrBuffer = '';

    /** Bytes that still have to be pushed into the child's stdin. */
    private string $writeBuffer = '';

    /** @var array<int, DeferredFuture> pending request id -> deferred */
    private array $pending = [];
    private int $nextId = 1;

    /** @var list<array<string, mixed>> notifications received (unused surface) */
    private array $notifications = [];

    private ?DeferredFuture $exitDeferred = null;

    /** Set once the transport is unusable; every later call fails fast. */
    private ?McpException $fatalError = null;

    /**
     * @param array<string, string>|null $env Variables merged over the
     *        inherited child environment (V5-28).
     */
    public function __construct(
        public readonly string $command,
        public readonly array $args = [],
        public readonly ?array $env = null,
        public readonly float $requestTimeout = self::DEFAULT_REQUEST_TIMEOUT,
    ) {
    }

    /**
     * Spawn the subprocess and start the read loop. Must be called from a
     * fiber inside Async::run()/the event loop.
     */
    public function start(): void
    {
        if ($this->proc !== null) {
            return;
        }
        $cmd = array_merge([$this->command], $this->args);
        $env = $this->env;
        if ($env !== null) {
            // proc_open REPLACES the whole child environment when an $env
            // argument is given. The Python SDK MERGES the configured
            // variables over the inherited ones, and servers routinely need
            // PATH/HOME/SystemRoot — replacing the environment left the
            // child with `PATH=false HOME=false` (V5-28).
            $inherited = getenv();
            if (is_array($inherited)) {
                $env = array_merge($inherited, $env);
            }
        }
        // proc_open descriptor direction (PHP manual, verified on Windows):
        //   ['pipe','r'] -> the CHILD reads from it, the PARENT writes to it
        //   ['pipe','w'] -> the CHILD writes to it, the PARENT reads from it
        // The parent writes the child's stdin and reads the child's
        // stdout/stderr, so fd 0 is 'r' and fd 1/2 are 'w'. The previous
        // mapping was inverted, which silently discarded every server reply
        // and made writes to stdin fail with errno=9 (Bad file descriptor) —
        // the MCP stdio transport could never complete a single request.
        // This is the same mapping PythonExecutorTool already uses.
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, null, $env, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            throw new McpException("failed to spawn MCP server: {$this->command}");
        }
        $this->proc = $proc;
        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        $this->stderr = $pipes[2];

        stream_set_blocking($this->stdout, false);
        stream_set_blocking($this->stderr, false);
        // Non-blocking stdin: a blocking fwrite could freeze the whole
        // event loop when the child stopped reading (M-17).
        stream_set_blocking($this->stdin, false);

        // Drive stdout reads from the event loop.
        $this->readWatcherId = EventLoop::onReadable($this->stdout, function (string $id): void {
            $this->pumpStdout();
        });

        // Drain stderr as well. An unread stderr pipe fills up after a few
        // kilobytes and blocks the child inside write(2), so the server
        // stops answering requests entirely (M-16).
        $this->stderrWatcherId = EventLoop::onReadable($this->stderr, function (string $id): void {
            $this->pumpStderr();
        });
    }

    private function pumpStdout(): void
    {
        if ($this->stdout === null) {
            return;
        }
        $chunk = fread($this->stdout, 65536);
        if (is_string($chunk) && $chunk !== '') {
            $this->readBuffer .= $chunk;
            if (strlen($this->readBuffer) > self::MAX_READ_BUFFER) {
                // No newline in 8 MB: the peer is not speaking the
                // newline-delimited framing. Fail the pending requests
                // instead of growing the buffer without bound (M-18).
                $this->readBuffer = '';
                $this->readOffset = 0;
                $this->failPending(
                    'MCP server sent more than ' . self::MAX_READ_BUFFER
                    . ' bytes without a newline (protocol violation)',
                );
                return;
            }
        }

        // Consume complete lines with an offset instead of re-copying the
        // whole buffer for every line (that was O(n^2) on large payloads).
        while (true) {
            $pos = strpos($this->readBuffer, "\n", $this->readOffset);
            if ($pos === false) {
                break;
            }
            $line = substr($this->readBuffer, $this->readOffset, $pos - $this->readOffset);
            $this->readOffset = $pos + 1;
            $this->handleLine($line);
        }

        // Compact the consumed prefix, but only in large steps so the copy
        // is amortised over many chunks.
        if ($this->readOffset >= 65536) {
            $this->readBuffer = substr($this->readBuffer, $this->readOffset);
            $this->readOffset = 0;
        }

        if (feof($this->stdout)) {
            $this->finishAll();
        }
    }

    /**
     * Drain the child's stderr into a bounded tail buffer.
     */
    private function pumpStderr(): void
    {
        if ($this->stderr === null) {
            return;
        }
        $chunk = fread($this->stderr, 65536);
        if (is_string($chunk) && $chunk !== '') {
            $this->stderrBuffer .= $chunk;
            if (strlen($this->stderrBuffer) > self::MAX_STDERR_BUFFER) {
                // Keep the tail only: the buffer is bounded so a chatty
                // server cannot exhaust the parent's memory.
                $this->stderrBuffer = substr($this->stderrBuffer, -self::MAX_STDERR_BUFFER);
            }
        }
        if (feof($this->stderr)) {
            // Stop watching a closed pipe, otherwise the watcher keeps
            // firing on the permanent EOF condition.
            $this->cancelStderrWatcher();
        }
    }

    /**
     * The tail of the child's stderr output (diagnostics only).
     */
    public function stderrTail(): string
    {
        return $this->stderrBuffer;
    }

    private function handleLine(string $line): void
    {
        try {
            $frame = JsonRpc::decode($line);
        } catch (McpException) {
            return; // ignore malformed lines (server logs etc.)
        }
        if ($frame === null) {
            return;
        }
        if (JsonRpc::isResponse($frame)) {
            $id = (int) $frame['id'];
            $deferred = $this->pending[$id] ?? null;
            if ($deferred === null) {
                return;
            }
            unset($this->pending[$id]);
            if ($deferred->isComplete()) {
                return;
            }
            if (isset($frame['error'])) {
                $deferred->error(new McpRemoteException($frame['error']));
            } else {
                $deferred->complete($frame['result'] ?? []);
            }
            return;
        }
        // Notification / server->client request surface (not used by manager)
        $this->notifications[] = $frame;
    }

    private function finishAll(): void
    {
        $this->cancelWatchers();
        $this->failPending('MCP server closed the pipe with pending requests');
        $this->completeExit();
    }

    /**
     * Settle every pending request with an error.
     *
     * The deferreds are settled (not merely dropped) so no awaiting fiber
     * stays suspended forever and no Future is left dangling (M-19).
     */
    private function failPending(string $message): void
    {
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as $deferred) {
            if (!$deferred->isComplete()) {
                $deferred->error(new McpException($message));
            }
        }
    }

    private function completeExit(): void
    {
        if ($this->exitDeferred !== null) {
            $exit = $this->exitDeferred;
            $this->exitDeferred = null;
            if (!$exit->isComplete()) {
                $exit->complete(null);
            }
        }
    }

    /**
     * Send a JSON-RPC request and await its result (suspends the fiber).
     *
     * @param array<string, mixed>|null $params
     * @return array<string, mixed>
     */
    public function request(string $method, ?array $params = null): array
    {
        $id = $this->nextId++;
        $deferred = new DeferredFuture();
        // The deferred is settled on every path, but the future must ALSO be
        // ignored: an abandoned deferred that is errored later would make
        // Amp\Internal\FutureState::__destruct queue an UnhandledFutureError
        // into the event loop, and the uncaught throwable kills the WHOLE
        // process instead of failing one request (C-3).
        $deferred->getFuture()->ignore();
        $this->pending[$id] = $deferred;

        try {
            // The write MUST happen inside the try: a broken pipe used to
            // escape before the deferred was awaited, leaving an orphaned
            // future behind (C-3).
            $this->write(JsonRpc::encode(JsonRpc::request($method, $params, $id)));
        } catch (Throwable $e) {
            unset($this->pending[$id]);
            $this->settle($deferred, $e);
            throw $e;
        }

        try {
            return $deferred->getFuture()->await(new \Amp\TimeoutCancellation($this->requestTimeout));
        } catch (\Amp\CancelledException $e) {
            unset($this->pending[$id]);
            $this->settle($deferred, $e);
            if ($e->getPrevious() instanceof \Amp\TimeoutException) {
                throw new McpException(
                    "MCP request '{$method}' timed out after " . sprintf('%g', $this->requestTimeout)
                    . 's (the server did not answer)',
                    0,
                    $e,
                );
            }
            throw $e;
        } catch (Throwable $e) {
            unset($this->pending[$id]);
            $this->settle($deferred, $e);
            throw $e;
        }
    }

    /**
     * Settle a deferred exactly once, ignoring the result.
     */
    private function settle(DeferredFuture $deferred, Throwable $error): void
    {
        if (!$deferred->isComplete()) {
            $deferred->error($error);
        }
    }

    /**
     * @param array<string, mixed>|null $params
     */
    public function notify(string $method, ?array $params = null): void
    {
        $this->write(JsonRpc::encode(JsonRpc::notification($method, $params)));
    }

    private function write(string $data): void
    {
        if ($this->fatalError !== null) {
            throw $this->fatalError;
        }
        if ($this->stdin === null) {
            throw new McpException('transport not started');
        }
        $this->writeBuffer .= $data;
        $this->flushWrites();
    }

    /**
     * Push as much of the pending write buffer into the child's stdin as
     * the pipe accepts right now.
     */
    private function flushWrites(): void
    {
        if ($this->stdin === null || $this->writeBuffer === '') {
            return;
        }
        while ($this->writeBuffer !== '') {
            $written = @fwrite($this->stdin, $this->writeBuffer);
            if ($written === false || $written === 0) {
                // The pipe is full (EAGAIN) or the peer is gone. When the
                // child already exited the pipe is broken for good: report
                // it instead of retrying forever.
                if ($this->childHasExited()) {
                    $this->failFatal('failed writing to MCP server stdin: the server process exited');
                }
                break;
            }
            $this->writeBuffer = substr($this->writeBuffer, $written);
        }

        if ($this->writeBuffer === '') {
            $this->cancelWriteWatcher();
            return;
        }
        $this->ensureWriteWatcher();
    }

    /**
     * Flush pending writes once the pipe reports itself writable again.
     */
    private function pumpWrites(): void
    {
        if ($this->stdin === null) {
            $this->cancelWriteWatcher();
            return;
        }
        $written = @fwrite($this->stdin, $this->writeBuffer);
        if ($written === false || $written === 0) {
            if ($this->childHasExited()) {
                $this->failFatal('failed writing to MCP server stdin: the server process exited');
            }
            return;
        }
        $this->writeBuffer = substr($this->writeBuffer, $written);
        if ($this->writeBuffer === '') {
            $this->cancelWriteWatcher();
            return;
        }
        $this->flushWrites();
    }

    private function ensureWriteWatcher(): void
    {
        if ($this->writeWatcherId !== null || $this->stdin === null) {
            return;
        }
        $this->writeWatcherId = EventLoop::onWritable($this->stdin, function (string $id): void {
            $this->pumpWrites();
        });
    }

    private function cancelWriteWatcher(): void
    {
        if ($this->writeWatcherId !== null) {
            EventLoop::cancel($this->writeWatcherId);
            $this->writeWatcherId = null;
        }
    }

    private function cancelStderrWatcher(): void
    {
        if ($this->stderrWatcherId !== null) {
            EventLoop::cancel($this->stderrWatcherId);
            $this->stderrWatcherId = null;
        }
    }

    private function cancelWatchers(): void
    {
        if ($this->readWatcherId !== null) {
            EventLoop::cancel($this->readWatcherId);
            $this->readWatcherId = null;
        }
        $this->cancelStderrWatcher();
        $this->cancelWriteWatcher();
    }

    /**
     * Mark the transport as broken and fail every pending request.
     */
    private function failFatal(string $message): void
    {
        if ($this->fatalError === null) {
            $this->fatalError = new McpException($message);
        }
        $this->failPending($message);
    }

    private function childHasExited(): bool
    {
        if ($this->proc === null) {
            return true;
        }
        $status = @proc_get_status($this->proc);
        if (!is_array($status)) {
            return true;
        }
        return !($status['running'] ?? false);
    }

    /**
     * Wait until the subprocess exits (bounded by the given timeout seconds).
     */
    public function waitExit(float $timeout = 5.0): void
    {
        if ($this->proc === null || $this->exitDeferred === null) {
            $this->exitDeferred ??= new DeferredFuture();
            $this->exitDeferred->getFuture()->ignore();
        }
        try {
            $this->exitDeferred->getFuture()->await(new \Amp\TimeoutCancellation($timeout));
        } catch (\Amp\CancelledException) {
            // timed out waiting for exit; force-kill in stop()
        }
    }

    public function stop(): void
    {
        $this->cancelWatchers();
        // Settle the pending requests BEFORE dropping them: a fiber awaiting
        // a response would stay suspended forever and the process would die
        // with "Event loop terminated without resuming the current
        // suspension" (M-19).
        $this->failPending('MCP transport stopped with pending requests');
        $this->completeExit();
        if ($this->proc !== null) {
            $status = proc_get_status($this->proc);
            if (is_array($status) && $status['running']) {
                $pid = (int) $status['pid'];
                if (PHP_OS_FAMILY === 'Windows') {
                    exec('taskkill /PID ' . $pid . ' /T /F 2>nul');
                    // Fallback for the case where taskkill is unavailable or
                    // fails: proc_close() waits for the child, so a child
                    // that survives the kill would block the shutdown.
                    $status = proc_get_status($this->proc);
                    if (is_array($status) && $status['running']) {
                        proc_terminate($this->proc);
                    }
                } else {
                    proc_terminate($this->proc);
                }
            }
            foreach ([$this->stdin, $this->stdout, $this->stderr] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->proc);
        }
        $this->proc = $this->stdin = $this->stdout = $this->stderr = null;
        $this->readBuffer = '';
        $this->readOffset = 0;
        $this->writeBuffer = '';
        $this->pending = [];
    }

    public function __destruct()
    {
        try {
            $this->stop();
        } catch (\Throwable) {
            // The event loop may already be gone during shutdown.
        }
    }
}
