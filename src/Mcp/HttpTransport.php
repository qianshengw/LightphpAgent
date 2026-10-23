<?php

declare(strict_types=1);

namespace LightAgent\Mcp;

use Amp\DeferredFuture;
use Revolt\EventLoop;

/**
 * HTTP-based JSON-RPC transports for MCP servers.
 *
 * Streamable HTTP (2025 spec): POST JSON-RPC frames to the server URL;
 * responses are JSON (or SSE-streamed, which we aggregate).
 *
 * SSE (legacy 2024-11-05 spec): GET an event stream to receive the
 * endpoint event, then POST JSON-RPC frames to that endpoint; responses
 * arrive over the SSE stream keyed by request id.
 *
 * Every transfer is driven by `curl_multi` from the event loop, so a slow
 * MCP server never freezes the sibling fibers of the agent (H-16).
 */
final class HttpTransport
{
    /** @var array<int, array<string, mixed>> completed responses keyed by request id */
    private array $responses = [];
    private int $nextId = 1;
    private ?string $sseEndpoint = null;

    /**
     * Poll interval (seconds) used to drive the curl_multi transfers.
     *
     * PHP does not expose the libcurl socket to the event loop, so the
     * transfer is pumped from a timer. The interval is short enough to keep
     * the latency negligible and long enough not to burn the CPU.
     */
    private const POLL_INTERVAL = 0.005;

    /** Maximum number of body bytes kept from a single HTTP response. */
    private const MAX_BODY = 16 * 1024 * 1024;

    /** Deadline (seconds) for receiving the SSE `endpoint` event. */
    private const SSE_ENDPOINT_TIMEOUT = 10.0;

    /** @var \CurlHandle|null Persistent SSE GET stream. */
    private $sseHandle = null;

    /** @var \CurlMultiHandle|null Multi handle owning the SSE GET stream. */
    private $sseMulti = null;

    private ?string $sseWatcherId = null;

    /** Raw (unparsed) bytes received on the SSE stream. */
    private string $sseBuffer = '';

    /** Deferred resolved once the SSE `endpoint` event arrived. */
    private ?DeferredFuture $sseEndpointDeferred = null;

    /** @var array<int, DeferredFuture> SSE request id -> deferred */
    private array $sseWaiters = [];

    private bool $sseStreamEnded = false;
    private string $sseStreamError = '';

    /**
     * Set when the SSE server announced a cross-origin endpoint.
     *
     * A security violation cannot be signalled by THROWING from curl's write
     * callback — that callback swallows exceptions on purpose, so the
     * violation would vanish. It is recorded here instead and turned into a
     * failed request.
     */
    private ?string $sseSecurityError = null;
    private string $sseContentType = '';

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $url,
        public readonly array $headers = [],
        public readonly float $timeout = 60.0,
        public readonly string $mode = 'streamable-http', // or 'sse'
    ) {
    }

    /**
     * Perform the MCP initialize handshake and return the server info.
     *
     * @return array<string, mixed>
     */
    public function initialize(): array
    {
        if ($this->mode === 'sse') {
            // The legacy SSE protocol needs the event stream BEFORE the
            // first request: the message endpoint is only known once the
            // server announced it on the stream (H-15).
            $this->ensureSseEndpoint();
            $result = $this->sseRequest(JsonRpc::METHOD_INITIALIZE, JsonRpc::initializeParams());
            $this->postNotification(JsonRpc::NOTIFICATION_INITIALIZED);
            return $result;
        }

        $result = $this->request(JsonRpc::METHOD_INITIALIZE, JsonRpc::initializeParams());
        // notifications/initialized follows the initialize response
        $this->postNotification(JsonRpc::NOTIFICATION_INITIALIZED);
        return $result;
    }

    /**
     * @param array<string, mixed>|null $params
     * @return array<string, mixed>
     */
    public function request(string $method, ?array $params = null): array
    {
        if ($this->mode === 'sse') {
            return $this->sseRequest($method, $params);
        }

        $id = $this->nextId++;
        $frame = JsonRpc::request($method, $params, $id);
        $raw = $this->post($frame);
        $decoded = json_decode($raw, true);

        if (is_array($decoded) && isset($decoded['id']) && (int) $decoded['id'] === $id) {
            if (isset($decoded['error'])) {
                throw new McpRemoteException($decoded['error']);
            }
            return is_array($decoded['result'] ?? null) ? $decoded['result'] : [];
        }

        // SSE-aggregated response: scan lines for our id
        if (is_string($raw) && $raw !== '') {
            foreach (explode("\n", $raw) as $line) {
                if (!str_starts_with($line, 'data:')) {
                    continue;
                }
                $data = trim(substr($line, 5));
                $candidate = json_decode($data, true);
                if (is_array($candidate) && isset($candidate['id']) && (int) $candidate['id'] === $id) {
                    if (isset($candidate['error'])) {
                        throw new McpRemoteException($candidate['error']);
                    }
                    return is_array($candidate['result'] ?? null) ? $candidate['result'] : [];
                }
            }
        }

        throw new McpException("no JSON-RPC response for {$method} (id={$id})");
    }

    /**
     * @param array<string, mixed>|null $params
     */
    public function notify(string $method, ?array $params = null): void
    {
        $this->postNotification($method, $params);
    }

    /**
     * @param array<string, mixed>|null $params
     */
    private function postNotification(string $method, ?array $params = null): void
    {
        if ($this->mode === 'sse') {
            $this->ensureSseEndpoint();
            $this->postToSseEndpoint(JsonRpc::notification($method, $params));
            return;
        }
        $this->post(JsonRpc::notification($method, $params), expectBody: false);
    }

    // ------------------------------------------------------------------
    // Legacy SSE protocol
    // ------------------------------------------------------------------

    /**
     * Open the event stream and wait for the `endpoint` event.
     *
     * The stream is read INCREMENTALLY: a conformant MCP SSE server keeps
     * the connection open for the whole session, so buffering the body
     * until the transfer ends could never return (H-15).
     */
    private function ensureSseEndpoint(): void
    {
        if ($this->sseEndpoint !== null) {
            return;
        }
        if ($this->sseStreamEnded) {
            throw new McpException($this->sseFailureMessage());
        }

        $this->startSseStream();

        $deferred = new DeferredFuture();
        // The future is settled explicitly on every path; ignoring it makes
        // sure an abandoned deferred can never escalate into an
        // UnhandledFutureError that kills the process.
        $deferred->getFuture()->ignore();
        $this->sseEndpointDeferred = $deferred;

        try {
            $this->sseEndpoint = (string) $deferred->getFuture()->await(
                new \Amp\TimeoutCancellation(self::SSE_ENDPOINT_TIMEOUT),
            );
        } catch (\Amp\CancelledException) {
            $this->sseEndpointDeferred = null;
            throw new McpException(sprintf(
                'MCP SSE endpoint event not received from %s within %ss. '
                . 'The server either does not speak the legacy MCP SSE protocol '
                . 'or it never announced its message endpoint — configure '
                . '`transport: streamable-http` for a modern MCP server.',
                $this->url,
                sprintf('%g', self::SSE_ENDPOINT_TIMEOUT),
            ));
        } catch (McpException $e) {
            $this->sseEndpointDeferred = null;
            throw $e;
        }
        $this->sseEndpointDeferred = null;
        if ($this->sseSecurityError !== null) {
            throw new McpException($this->sseSecurityError);
        }
    }

    /**
     * Start the long-lived GET stream that carries the server events.
     */
    private function startSseStream(): void
    {
        if ($this->sseMulti !== null) {
            return;
        }

        $headers = self::normalizeHeaders($this->headers);
        $headers['Accept'] = 'text/event-stream';

        $ch = curl_init($this->url);
        if ($ch === false) {
            throw new McpException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => self::headerLines($headers),
            CURLOPT_RETURNTRANSFER => false,
            // The stream stays open for the whole session.
            CURLOPT_TIMEOUT => 0,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($handle, string $chunk): int {
            try {
                $this->onSseChunk($chunk);
            } catch (\Throwable) {
                // A malformed event must never abort the transfer.
            }
            return strlen($chunk);
        });

        $mh = curl_multi_init();
        curl_multi_add_handle($mh, $ch);

        $this->sseHandle = $ch;
        $this->sseMulti = $mh;
        $this->sseStreamEnded = false;
        $this->sseWatcherId = EventLoop::repeat(self::POLL_INTERVAL, function (): void {
            $this->pumpSse();
        });
    }

    /**
     * Pump the SSE transfer once.
     */
    private function pumpSse(): void
    {
        if ($this->sseMulti === null) {
            return;
        }
        $running = null;
        curl_multi_exec($this->sseMulti, $running);
        if ($running !== 0) {
            return;
        }

        $status = $this->sseHandle !== null ? (int) curl_getinfo($this->sseHandle, CURLINFO_RESPONSE_CODE) : 0;
        $error = $this->sseHandle !== null ? (string) curl_error($this->sseHandle) : '';
        $this->finishSseStream($status, $error);
    }

    /**
     * Parse the raw SSE bytes and dispatch the events.
     */
    private function onSseChunk(string $chunk): void
    {
        $this->sseBuffer .= str_replace("\r\n", "\n", $chunk);
        while (($pos = strpos($this->sseBuffer, "\n\n")) !== false) {
            $block = substr($this->sseBuffer, 0, $pos);
            $this->sseBuffer = substr($this->sseBuffer, $pos + 2);
            $this->handleSseBlock($block);
        }
    }

    /**
     * Handle one SSE event block.
     */
    private function handleSseBlock(string $block): void
    {
        $event = 'message';
        $data = [];
        foreach (explode("\n", $block) as $line) {
            if ($line === '' || str_starts_with($line, ':')) {
                continue; // comment / keep-alive
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                $field = $line;
                $value = '';
            } else {
                $field = substr($line, 0, $colon);
                $value = ltrim(substr($line, $colon + 1));
            }
            if ($field === 'event') {
                $event = $value;
            } elseif ($field === 'data') {
                $data[] = $value;
            }
        }

        $payload = implode("\n", $data);
        if ($payload === '') {
            return;
        }

        if ($event === 'endpoint') {
            // SAME-ORIGIN CHECK. The server announces the URL that receives
            // the JSON-RPC frames, and those frames are sent WITH the
            // configured headers — including `Authorization: Bearer …`.
            //
            // The official MCP SDK validates the announced endpoint's origin
            // (scheme + host + port) and refuses a cross-origin one. Without
            // that check a compromised or hostile server can announce
            // `endpoint: https://attacker.example/collect` and receive the
            // caller's credentials on the very next request.
            //
            // The violation is RECORDED, not thrown: this code runs inside
            // curl's write callback, which swallows exceptions (a malformed
            // event must not abort the transfer). Throwing here would be
            // silently discarded and the check would never fire.
            $candidate = str_starts_with($payload, 'http') ? $payload : $this->absoluteUrl($payload);
            if (!self::isSameOrigin($this->url, $candidate)) {
                $this->sseSecurityError = sprintf(
                    'MCP SSE server announced a cross-origin endpoint (%s) while the server is %s; '
                    . 'refusing to send credentials to it',
                    $candidate,
                    $this->url,
                );
                $this->sseEndpoint = null;
                if ($this->sseEndpointDeferred !== null && !$this->sseEndpointDeferred->isComplete()) {
                    $deferred = $this->sseEndpointDeferred;
                    $this->sseEndpointDeferred = null;
                    $deferred->error(new McpException($this->sseSecurityError));
                }
                return;
            }
            $this->sseEndpoint = $candidate;
            if ($this->sseEndpointDeferred !== null && !$this->sseEndpointDeferred->isComplete()) {
                $deferred = $this->sseEndpointDeferred;
                $this->sseEndpointDeferred = null;
                $deferred->complete($this->sseEndpoint);
            }
            return;
        }

        // A JSON-RPC response pushed by the server.
        $decoded = json_decode($payload, true);
        if (!is_array($decoded) || !isset($decoded['id'])) {
            return;
        }
        $id = (int) $decoded['id'];
        $deferred = $this->sseWaiters[$id] ?? null;
        if ($deferred === null) {
            return;
        }
        unset($this->sseWaiters[$id]);
        if ($deferred->isComplete()) {
            return;
        }
        if (isset($decoded['error'])) {
            $deferred->error(new McpRemoteException($decoded['error']));
        } else {
            $deferred->complete(is_array($decoded['result'] ?? null) ? $decoded['result'] : []);
        }
    }

    /**
     * The SSE stream ended: settle everything that is still waiting.
     */
    private function finishSseStream(int $status, string $error): void
    {
        // The content type has to be captured BEFORE the handle is
        // released: it tells a non-SSE server apart from a broken stream.
        if ($this->sseHandle !== null) {
            $this->sseContentType = (string) curl_getinfo($this->sseHandle, CURLINFO_CONTENT_TYPE);
        }
        if ($this->sseWatcherId !== null) {
            EventLoop::cancel($this->sseWatcherId);
            $this->sseWatcherId = null;
        }
        if ($this->sseMulti !== null && $this->sseHandle !== null) {
            curl_multi_remove_handle($this->sseMulti, $this->sseHandle);
            curl_multi_close($this->sseMulti);
        }
        $this->sseMulti = null;
        $this->sseHandle = null;
        $this->sseStreamEnded = true;
        $this->sseStreamError = $error;

        if ($this->sseEndpointDeferred !== null && !$this->sseEndpointDeferred->isComplete()) {
            $deferred = $this->sseEndpointDeferred;
            $this->sseEndpointDeferred = null;
            $deferred->error(new McpException($this->sseFailureMessage($status)));
        }

        foreach ($this->sseWaiters as $id => $deferred) {
            unset($this->sseWaiters[$id]);
            if (!$deferred->isComplete()) {
                $deferred->error(new McpException($this->sseFailureMessage($status)));
            }
        }
    }

    /**
     * A human readable explanation of why the SSE stream did not work out.
     */
    private function sseFailureMessage(int $status = 0): string
    {
        $contentType = $this->sseContentType;
        if ($contentType !== '' && !str_contains(strtolower($contentType), 'text/event-stream')) {
            return sprintf(
                'The server at %s answered with Content-Type `%s` instead of `text/event-stream`: '
                . 'it does not speak the legacy MCP SSE protocol. Configure '
                . '`transport: streamable-http` for a modern MCP server.',
                $this->url,
                $contentType,
            );
        }
        return sprintf(
            'The MCP SSE stream at %s ended (HTTP %d%s) before the exchange completed.',
            $this->url,
            $status,
            $this->sseStreamError !== '' ? ', ' . $this->sseStreamError : '',
        );
    }

    /**
     * Perform one JSON-RPC request over the legacy SSE protocol.
     *
     * The response does NOT come back in the POST body: the server
     * acknowledges the POST with 202 and pushes the response over the
     * long-lived GET stream, keyed by the request id.
     *
     * @param array<string, mixed>|null $params
     * @return array<string, mixed>
     */
    private function sseRequest(string $method, ?array $params = null): array
    {
        $this->ensureSseEndpoint();

        $id = $this->nextId++;
        $deferred = new DeferredFuture();
        $deferred->getFuture()->ignore();
        $this->sseWaiters[$id] = $deferred;

        try {
            $this->postToSseEndpoint(JsonRpc::request($method, $params, $id));
        } catch (\Throwable $e) {
            unset($this->sseWaiters[$id]);
            throw $e;
        }

        try {
            return $deferred->getFuture()->await(new \Amp\TimeoutCancellation($this->timeout));
        } catch (\Amp\CancelledException $e) {
            unset($this->sseWaiters[$id]);
            throw new McpException(
                "MCP SSE request '{$method}' timed out after " . sprintf('%g', $this->timeout)
                . 's (no response arrived on the event stream)',
                0,
                $e,
            );
        } catch (\Throwable $e) {
            unset($this->sseWaiters[$id]);
            throw $e;
        }
    }

    /**
     * POST a frame to the endpoint announced on the SSE stream.
     *
     * @param array<string, mixed> $frame
     */
    private function postToSseEndpoint(array $frame): void
    {
        if ($this->sseSecurityError !== null) {
            // The server tried to redirect the credentialed POSTs to another
            // host. Nothing is sent there, ever.
            throw new McpException($this->sseSecurityError);
        }
        $url = $this->sseEndpoint ?? $this->url;
        $headers = self::normalizeHeaders($this->headers);
        $headers['Content-Type'] = 'application/json';
        $headers['Accept'] = 'application/json';

        $ch = curl_init($url);
        if ($ch === false) {
            throw new McpException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => JsonRpc::encode($frame),
            CURLOPT_HTTPHEADER => self::headerLines($headers),
            CURLOPT_TIMEOUT => (int) max(1, ceil($this->timeout)),
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);

        $result = $this->transfer($ch, $this->timeout);
        if ($result['error'] !== '') {
            throw new McpException("MCP HTTP error: {$result['error']}");
        }
        if ($result['status'] >= 400) {
            throw new McpException("MCP HTTP {$result['status']}: " . substr($result['body'], 0, 200));
        }
    }

    /**
     * True when two URLs share the same origin (scheme, host and port).
     *
     * The default ports are normalised, so `https://h/x` and
     * `https://h:443/y` are the same origin while `https://h/x` and
     * `http://h/x` are not.
     */
    private static function isSameOrigin(string $a, string $b): bool
    {
        $pa = parse_url($a);
        $pb = parse_url($b);
        if (!is_array($pa) || !is_array($pb)) {
            return false;
        }
        $schemeA = strtolower((string) ($pa['scheme'] ?? 'http'));
        $schemeB = strtolower((string) ($pb['scheme'] ?? 'http'));
        if ($schemeA !== $schemeB) {
            return false;
        }
        $hostA = strtolower((string) ($pa['host'] ?? ''));
        $hostB = strtolower((string) ($pb['host'] ?? ''));
        if ($hostA === '' || $hostA !== $hostB) {
            return false;
        }
        $defaultPort = ['http' => 80, 'https' => 443][$schemeA] ?? 0;
        $portA = (int) ($pa['port'] ?? $defaultPort);
        $portB = (int) ($pb['port'] ?? $defaultPort);
        return $portA === $portB;
    }

    private function absoluteUrl(string $path): string
    {
        $parts = parse_url($this->url);
        $base = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? 'localhost')
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($path, '/')) {
            return $base . $path;
        }
        $dir = isset($parts['path']) ? rtrim(dirname($parts['path']), '/') : '';
        return $base . $dir . '/' . $path;
    }

    /**
     * @param array<string, mixed> $frame
     */
    private function post(array $frame, bool $expectBody = true): string
    {
        $url = $this->mode === 'sse' ? ($this->sseEndpoint ?? $this->url) : $this->url;
        // Header names and values must be sent as "Name: value" lines. The
        // previous code appended pre-rendered strings to the associative
        // header map and then zipped array_keys() with array_values(),
        // which turned the Content-Type line into a header literally named
        // "0" and left curl to fall back to its default
        // application/x-www-form-urlencoded (C-5).
        $headers = self::normalizeHeaders($this->headers);
        $headers['Content-Type'] = 'application/json';
        $headers['Accept'] = $expectBody ? 'application/json, text/event-stream' : 'application/json';

        $ch = curl_init($url);
        if ($ch === false) {
            throw new McpException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => JsonRpc::encode($frame),
            CURLOPT_HTTPHEADER => self::headerLines($headers),
            CURLOPT_TIMEOUT => (int) max(1, ceil($this->timeout)),
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);

        $result = $this->transfer($ch, $this->timeout);
        if ($result['error'] !== '') {
            throw new McpException("MCP HTTP error: {$result['error']}");
        }
        if ($result['status'] >= 400) {
            throw new McpException("MCP HTTP {$result['status']}: " . substr($result['body'], 0, 200));
        }
        return $result['body'];
    }

    /**
     * Run a curl transfer without blocking the event loop (H-16).
     *
     * The transfer is driven by `curl_multi` from a timer callback: PHP
     * does not expose the libcurl socket, and a blocking `curl_exec()`
     * froze EVERY sibling fiber for the whole duration of the request.
     *
     * @param \CurlHandle $ch
     * @return array{status: int, body: string, error: string}
     */
    private function transfer(\CurlHandle $ch, float $timeout): array
    {
        $body = '';
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) < self::MAX_BODY) {
                $body .= substr($chunk, 0, self::MAX_BODY - strlen($body));
            }
            return strlen($chunk);
        });

        if (\Fiber::getCurrent() === null) {
            // Outside a fiber the event loop is not running, so a suspending
            // transfer could never be resumed: use the blocking call.
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = $raw === false ? (string) curl_error($ch) : '';
            return ['status' => $status, 'body' => $body, 'error' => $error];
        }

        $mh = curl_multi_init();
        curl_multi_add_handle($mh, $ch);

        $deferred = new DeferredFuture();
        $deferred->getFuture()->ignore();
        $watcher = null;
        $curlError = '';

        $pump = function () use ($mh, $ch, &$watcher, $deferred, &$curlError): void {
            $running = null;
            curl_multi_exec($mh, $running);

            // Transport errors are reported through the multi handle, not
            // through curl_error() on the individual handle — under
            // curl_multi the per-handle error string stays empty.
            while (($info = curl_multi_info_read($mh)) !== false) {
                if (($info['msg'] ?? CURLMSG_DONE) !== CURLMSG_DONE) {
                    continue;
                }
                if (($info['handle'] ?? null) !== null && ($info['handle'] !== $ch)) {
                    continue;
                }
                $result = (int) ($info['result'] ?? CURLE_OK);
                if ($result !== CURLE_OK) {
                    $curlError = curl_strerror($result);
                    if ($curlError === '') {
                        $curlError = 'curl_multi error ' . $result;
                    }
                }
            }

            if ($running === 0 && !$deferred->isComplete()) {
                EventLoop::cancel((string) $watcher);
                $deferred->complete(true);
            }
        };

        $watcher = EventLoop::repeat(self::POLL_INTERVAL, $pump);
        $timeoutWatcher = EventLoop::delay($timeout, function () use ($deferred, $timeout): void {
            if ($deferred->isComplete()) {
                return;
            }
            $deferred->error(new McpException(
                'MCP HTTP request timed out after ' . sprintf('%g', $timeout) . 's',
            ));
        });

        try {
            $deferred->getFuture()->await();
        } finally {
            EventLoop::cancel((string) $watcher);
            EventLoop::cancel((string) $timeoutWatcher);
            curl_multi_remove_handle($mh, $ch);
            curl_multi_close($mh);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($curlError === '') {
            $curlError = (string) curl_error($ch);
        }
        return ['status' => $status, 'body' => $body, 'error' => $curlError];
    }

    public function stop(): void
    {
        $this->sseEndpoint = null;
        $this->responses = [];

        if ($this->sseWatcherId !== null) {
            EventLoop::cancel($this->sseWatcherId);
            $this->sseWatcherId = null;
        }
        if ($this->sseMulti !== null && $this->sseHandle !== null) {
            curl_multi_remove_handle($this->sseMulti, $this->sseHandle);
            curl_multi_close($this->sseMulti);
        }
        $this->sseMulti = null;
        $this->sseHandle = null;
        $this->sseStreamEnded = true;

        if ($this->sseEndpointDeferred !== null && !$this->sseEndpointDeferred->isComplete()) {
            $deferred = $this->sseEndpointDeferred;
            $this->sseEndpointDeferred = null;
            $deferred->error(new McpException('MCP SSE transport stopped'));
        }
        foreach ($this->sseWaiters as $id => $deferred) {
            unset($this->sseWaiters[$id]);
            if (!$deferred->isComplete()) {
                $deferred->error(new McpException('MCP SSE transport stopped'));
            }
        }
    }

    public function __destruct()
    {
        try {
            $this->stop();
        } catch (\Throwable) {
            // The event loop may already be gone during shutdown.
        }
    }

    /**
     * Fold the configured headers into one associative map.
     *
     * Both spellings are accepted:
     *   ['api-key' => 'secret']      (associative)
     *   ['api-key: secret']          (raw header lines)
     *
     * @param array<int|string, mixed> $headers
     * @return array<string, string>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (is_int($name) && is_string($value)) {
                $pos = strpos($value, ':');
                if ($pos === false) {
                    continue;
                }
                $name = trim(substr($value, 0, $pos));
                $value = trim(substr($value, $pos + 1));
            }
            if (!is_string($name) || trim($name) === '' || !is_string($value)) {
                continue;
            }
            $normalized[trim($name)] = trim($value);
        }
        return $normalized;
    }

    /**
     * Render a header map as the "Name: value" lines curl expects.
     *
     * @param array<string, string> $headers
     * @return list<string>
     */
    private static function headerLines(array $headers): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        return $lines;
    }
}
