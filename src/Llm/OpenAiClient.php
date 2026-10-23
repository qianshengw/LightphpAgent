<?php

declare(strict_types=1);

namespace LightAgent\Llm;

/**
 * OpenAI-compatible Chat Completions client over ext-curl.
 *
 * Port surface of the `openai.OpenAI` client as used by core.py:
 *   client.chat.completions.create(**params)
 *   - stream=False -> ChatCompletion
 *   - stream=True  -> iterator<ChatCompletionChunk>
 *
 * Error taxonomy mapping is done by the caller via ErrorTaxonomy::classify().
 */
final class OpenAiClient
{
    private readonly OpenAiCompletions $chat;

    /**
     * Set when the endpoint answered HTTP 400 for the `stream_options`
     * request field. The client then retried the stream without it, so the
     * provider sends no usage frame and the run cannot be charged against
     * the token budget.
     */
    public bool $streamOptionsUnsupported = false;

    /**
     * Number of stream frames that could not be decoded as JSON.
     *
     * The stream consumer used to `continue` on a decode failure, which
     * dropped the frame SILENTLY — a split payload looked exactly like a
     * provider that had nothing more to say. Counting them makes the loss
     * observable.
     */
    public int $malformedStreamFrames = 0;

    public function __construct(
        public readonly string $apiKey,
        public readonly string $baseUrl = 'https://api.openai.com/v1',
        public readonly float $timeout = 600.0,
        // M29: enterprise-environment escapes — custom CA bundle for
        // self-signed/intercepting proxies, an HTTP(S) proxy, and extra
        // static headers (gateway auth like `api-key: ...`). All optional;
        // defaults preserve the previous behavior exactly.
        public readonly ?string $caBundle = null,
        public readonly ?string $proxy = null,
        public readonly array $extraHeaders = [],
    ) {
        $this->chat = new OpenAiCompletions($this);
    }

    /**
     * Curl options shared by every request path (streaming + blocking).
     *
     * @return array<int, mixed>
     */
    public function baseCurlOptions(): array
    {
        $options = [
            CURLOPT_CONNECTTIMEOUT => 30,
        ];
        if ($this->caBundle !== null) {
            $options[CURLOPT_CAINFO] = $this->caBundle;
        }
        if ($this->proxy !== null) {
            $options[CURLOPT_PROXY] = $this->proxy;
        }
        return $options;
    }

    /**
     * Headers for a request, with extraHeaders appended last (M29).
     *
     * @param list<string> $headers
     * @return list<string>
     */
    public function withExtraHeaders(array $headers): array
    {
        foreach ($this->extraHeaders as $name => $value) {
            $headers[] = (is_int($name) ? (string) $value : "{$name}: {$value}");
        }
        return $headers;
    }

    public function chat(): OpenAiCompletions
    {
        return $this->chat;
    }

    /**
     * Build the chat-completions endpoint, preserving any query string that
     * is already part of baseUrl (e.g. http://host/v1?case=plain ->
     * /v1/chat/completions?case=plain).
     */
    private function endpointUrl(): string
    {
        $base = rtrim($this->baseUrl, '/');
        $query = '';
        $qPos = strpos($base, '?');
        if ($qPos !== false) {
            $query = substr($base, $qPos);
            $base = substr($base, 0, $qPos);
        }
        return $base . '/chat/completions' . $query;
    }

    /**
     * Encode a request body, failing loudly when it cannot be represented.
     *
     * `json_encode()` returns **false** for input it cannot encode (invalid
     * UTF-8, INF/NAN, runaway nesting). Passing that `false` straight to
     * `CURLOPT_POSTFIELDS` sends an EMPTY body with `Content-Length: 0`, so
     * the gateway answers a generic 400 and the real cause (a malformed byte
     * in the prompt) is nowhere to be seen.
     */
    private function encodeRequestBody(array $params): string
    {
        $encoded = json_encode($params, JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new LlmException(
                'request payload cannot be encoded as JSON: ' . json_last_error_msg(),
            );
        }
        return $encoded;
    }

    /**
     * Open a streaming chat completion and yield each SSE `data:` payload as
     * soon as it is parsed.
     *
     * rawRequest() buffers the complete response body before returning, which
     * makes progressive rendering impossible. This method instead drives the
     * transfer with curl_multi and feeds arriving bytes into SseParser, so the
     * caller observes events incrementally.
     *
     * When the endpoint rejects the `stream_options` field with HTTP 400 the
     * request is retried once without it (PORTING-DIFF: the Python original
     * never sends that field). The price of the fallback is that the provider
     * sends no usage frame, so the stream cannot be charged against the token
     * budget.
     *
     * @param array<string, mixed> $params
     * @return \Generator<string> raw SSE payloads (without the `data:` prefix)
     */
    public function streamRequest(array $params): \Generator
    {
        $yielded = false;
        try {
            foreach ($this->streamRequestOnce($params) as $payload) {
                $yielded = true;
                yield $payload;
            }
            return;
        } catch (LlmException $error) {
            // Never retry once the stream has produced payloads: the caller
            // already saw part of the answer and a retry would duplicate it.
            if ($yielded || !isset($params['stream_options']) || !self::rejectsStreamOptions($error)) {
                throw $error;
            }
            $this->streamOptionsUnsupported = true;
        }

        $fallback = $params;
        unset($fallback['stream_options']);
        yield from $this->streamRequestOnce($fallback);
    }

    /**
     * True when the endpoint answered HTTP 400 complaining about the
     * `stream_options` field (some OpenAI-compatible gateways reject
     * unknown request fields).
     */
    private static function rejectsStreamOptions(LlmException $error): bool
    {
        if ($error->statusCode !== 400) {
            return false;
        }
        $message = $error->getMessage();
        return stripos($message, 'stream_options') !== false
            || stripos($message, 'stream options') !== false
            || stripos($message, 'stream-options') !== false;
    }

    /**
     * Perform the streaming request once.
     *
     * @param array<string, mixed> $params
     * @return \Generator<string> raw SSE payloads (without the `data:` prefix)
     */
    private function streamRequestOnce(array $params): \Generator
    {
        $body = $params;

        $ch = curl_init($this->endpointUrl());
        if ($ch === false) {
            throw new LlmException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $this->encodeRequestBody($body),
            CURLOPT_HTTPHEADER => $this->withExtraHeaders([
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: text/event-stream',
            ]),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => (int) max(1, ceil($this->timeout)),
        ]);
        curl_setopt_array($ch, $this->baseCurlOptions());

        /** @var list<string> $payloads */
        $payloads = [];
        $parser = new SseParser(
            onData: static function (string $data) use (&$payloads): void {
                $payloads[] = $data;
            },
        );

        // Keep the leading part of the body so an HTTP error can quote it.
        $captured = '';
        $capturedLimit = 65536;

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($ch, string $chunk) use ($parser, &$captured, $capturedLimit): int {
            $parser->feed($chunk);
            if (strlen($captured) < $capturedLimit) {
                $captured .= $chunk;
            }
            return strlen($chunk);
        });

        $mh = curl_multi_init();
        curl_multi_add_handle($mh, $ch);

        $running = null;
        $httpError = null;
        $finalStatus = 0;
        $curlError = '';
        $curlErrno = 0;

        try {
            do {
                curl_multi_exec($mh, $running);

                // Transport errors are reported through the multi handle, not
                // through curl_error() on the individual handle — under
                // curl_multi the per-handle error string stays empty, so a
                // TLS / connection / DNS failure was silently swallowed and
                // surfaced as "the provider returned an empty response"
                // (H17). Read the completion message to recover it.
                while (($info = curl_multi_info_read($mh)) !== false) {
                    if (($info['msg'] ?? CURLMSG_DONE) !== CURLMSG_DONE) {
                        continue;
                    }
                    if (($info['handle'] ?? null) !== null && ($info['handle'] !== $ch)) {
                        continue;
                    }
                    $curlResult = (int) ($info['result'] ?? CURLE_OK);
                    if ($curlResult !== CURLE_OK) {
                        $curlError = curl_strerror($curlResult);
                        $curlErrno = $curlResult;
                        if ($curlError === '') {
                            $curlError = 'curl_multi error ' . $curlResult;
                        }
                    }
                }

                // An HTTP failure is detectable as soon as the status line has
                // been read, so report it without waiting for the body.
                if ($httpError === null) {
                    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                    if ($status >= 400) {
                        $httpError = LlmException::fromHttpStatus($status, $captured, curl_error($ch));
                    }
                }

                while ($payloads !== []) {
                    yield (string) array_shift($payloads);
                }

                if ($running > 0) {
                    // curl_multi_select() returns -1 when it has nothing to
                    // wait on; sleep briefly instead of spinning.
                    if (curl_multi_select($mh, 0.05) === -1) {
                        usleep(1000);
                    }
                }
            } while ($running > 0);

            // Flush the tail of the stream. A provider that closes the
            // connection right after its last `data:` payload — no trailing
            // newline — leaves that event in the parser buffer. For OpenAI
            // that event is typically the `stream_options.include_usage`
            // frame, i.e. the token accounting for the whole stream: without
            // this flush the run can never be charged against the budget.
            $parser->finish();
            while ($payloads !== []) {
                yield (string) array_shift($payloads);
            }

            $finalStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            // Do not clobber the error recovered from curl_multi_info_read():
            // under curl_multi the per-handle error string is empty, so
            // overwriting would restore the very bug this fixes (H17).
            if ($curlError === '') {
                $curlError = curl_error($ch);
            }
        } finally {
            curl_multi_remove_handle($mh, $ch);
            curl_multi_close($mh);
            // No explicit curl_close(): the handle is released by the garbage
            // collector, and curl_close() has been a deprecated no-op since 8.0.
        }

        if ($httpError !== null) {
            throw $httpError;
        }
        if ($finalStatus >= 400) {
            throw LlmException::fromHttpStatus($finalStatus, $captured, $curlError);
        }
        // A TRUNCATED response must never be reported as a successful one.
        // curl reports "transfer closed with N bytes remaining to read" as
        // CURLE_PARTIAL_FILE, and the partial body it hands back is a valid
        // prefix of the answer — so without this check a cut-off stream
        // looked exactly like a complete one and the agent happily returned
        // the truncated answer as a successful run. (The Python original
        // gets this for free: the official SDK raises APIConnectionError.)
        if ($curlErrno === CURLE_PARTIAL_FILE) {
            throw new LlmException(
                'the provider closed the connection before the response was complete (truncated response)',
            );
        }
        if ($curlError !== '' && $captured === '') {
            throw new LlmException("curl error: {$curlError}");
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|string raw decoded body, or raw SSE line on stream errors
     */
    public function rawRequest(array $params): array|string
    {
        $stream = (bool) ($params['stream'] ?? false);
        $body = $params;

        $url = $this->endpointUrl();
        $headers = $this->withExtraHeaders([
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: ' . ($stream ? 'text/event-stream' : 'application/json'),
        ]);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new LlmException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $this->encodeRequestBody($body),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) max(1, ceil($this->timeout)),
        ]);
        curl_setopt_array($ch, $this->baseCurlOptions());

        if ($stream) {
            // Streaming: capture via write callback into a pipe-friendly buffer.
            $buffer = fopen('php://temp', 'w+');
            curl_setopt($ch, CURLOPT_FILE, $buffer);
            curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($ch);
            $errno = curl_errno($ch);
                        rewind($buffer);
            $raw = stream_get_contents($buffer) ?: '';
            fclose($buffer);
            if ($status >= 400) {
                throw LlmException::fromHttpStatus($status, $raw, $curlError);
            }
            // A truncated body is not a successful response (see the note
            // in streamRequestOnce()).
            if ($errno === CURLE_PARTIAL_FILE) {
                throw new LlmException(
                    'the provider closed the connection before the response was complete (truncated response)',
                );
            }
            if ($curlError !== '' && $raw === '') {
                throw new LlmException("curl error: {$curlError}");
            }
            return $raw;
        }

        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        $errno = curl_errno($ch);

        // A truncated body is a failed request, not a short answer. curl
        // hands back the partial prefix, which is valid JSON in the worst
        // case — so without the errno check a cut-off response was
        // indistinguishable from a complete one.
        //
        // The check comes BEFORE the `$raw === false` branch on purpose: with
        // CURLOPT_RETURNTRANSFER, curl_exec() returns false for a truncated
        // transfer, so the generic branch would otherwise report the
        // truncation as an opaque "curl error: ... bytes missing" instead of
        // naming the actual cause.
        if ($errno === CURLE_PARTIAL_FILE) {
            throw new LlmException(
                'the provider closed the connection before the response was complete (truncated response)',
            );
        }

        if ($raw === false) {
            throw new LlmException("curl error: {$curlError}");
        }
        $raw = (string) $raw;

        if ($status >= 400) {
            throw LlmException::fromHttpStatus($status, $raw, $curlError);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new LlmException('invalid JSON from provider: ' . substr($raw, 0, 200));
        }
        return $decoded;
    }
}
