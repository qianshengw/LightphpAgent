<?php

declare(strict_types=1);

namespace LightAgent\Llm;

/**
 * SSE (Server-Sent Events) line parser for OpenAI-compatible streams.
 * Decoupled from the transport so it can be unit-tested with raw text.
 *
 * Two behaviours matter for correctness against real gateways:
 *
 * 1. **The tail must be flushed.** `feed()` only emits COMPLETE lines, so
 *    the last event of a stream is still sitting in the buffer when the
 *    connection closes unless `finish()` is called. For OpenAI that last
 *    event is typically the `stream_options.include_usage` frame — i.e.
 *    the token accounting for the whole stream. The transport MUST call
 *    `finish()` when the transfer ends.
 *
 * 2. **Consecutive `data:` lines form ONE event.** The SSE specification
 *    joins them with "\n" and dispatches them together. Emitting them
 *    separately produced two invalid JSON fragments that the consumer
 *    then dropped — silently losing content. Because the parser is fed
 *    arbitrary network chunks, the join is applied when the *next* line is
 *    already known to be a `data:` line (one line of look-ahead).
 */
final class SseParser
{
    private string $buffer = '';
    private bool $streamFinished = false;
    /** @var list<string> the `data:` lines of the event being assembled */
    private array $pendingData = [];

    public function __construct(
        private readonly \Closure $onData, // fn(string $data): void
        private readonly ?\Closure $onDone = null, // fn(): void
    ) {
    }

    /**
     * Feed raw bytes from the HTTP body.
     */
    public function feed(string $chunk): void
    {
        if ($this->streamFinished) {
            return;
        }
        $this->buffer .= $chunk;

        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 1);
            $this->handleLine(rtrim($line, "\r"));
        }
    }

    /**
     * Flush everything the provider sent before closing the connection.
     *
     * MUST be called by the transport when the transfer ends. Without it the
     * last event of the stream is silently dropped.
     */
    public function finish(): void
    {
        if ($this->streamFinished) {
            return;
        }
        // Flush any final line without a trailing newline.
        $rest = rtrim($this->buffer, "\r");
        $this->buffer = '';
        if ($rest !== '') {
            $this->handleLine($rest);
        }
        // A held multi-line event may still be waiting for its last line.
        $this->flushPendingData();
        $this->streamFinished = true;
        if ($this->onDone !== null) {
            ($this->onDone)();
        }
    }

    private function handleLine(string $line): void
    {
        if ($line === '' || str_starts_with($line, ':')) {
            return; // comment / keep-alive / event separator
        }

        if (!str_starts_with($line, 'data:')) {
            // event:/id:/retry: and other fields are ignored (OpenAI uses
            // data only).
            return;
        }

        $value = trim(substr($line, 5));
        if ($value === '[DONE]') {
            $this->flushPendingData();
            $this->streamFinished = true;
            if ($this->onDone !== null) {
                ($this->onDone)();
            }
            return;
        }

        // Look-ahead: when the line that follows is already known to be
        // another `data:` line, both belong to the same event and must be
        // joined with "\n" instead of dispatched separately.
        if ($this->nextLineIsData()) {
            $this->pendingData[] = $value;
            return;
        }
        if ($this->pendingData !== []) {
            $this->pendingData[] = $value;
            $this->flushPendingData();
            return;
        }
        if ($value !== '') {
            ($this->onData)($value);
        }
    }

    /**
     * Dispatch the assembled event, if any.
     */
    private function flushPendingData(): void
    {
        if ($this->pendingData === []) {
            return;
        }
        $payload = implode("\n", $this->pendingData);
        $this->pendingData = [];
        if ($payload !== '') {
            ($this->onData)($payload);
        }
    }

    /**
     * True when the next line in the buffer is (or starts as) a `data:` line.
     *
     * The remainder may be an incomplete line — it still tells us that the
     * event continues, because a `data:` line is a `data:` line whether or
     * not its newline has arrived yet.
     */
    private function nextLineIsData(): bool
    {
        if ($this->buffer === '') {
            return false;
        }
        $pos = strpos($this->buffer, "\n");
        $next = $pos === false ? $this->buffer : substr($this->buffer, 0, $pos);
        return str_starts_with(rtrim($next, "\r"), 'data:');
    }
}
