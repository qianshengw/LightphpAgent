<?php

declare(strict_types=1);

namespace LightAgent\Result;

/**
 * Optional structured return value for non-streaming agent runs (result.py RunResult).
 *
 * The `success` flag is the authoritative answer to "did this run succeed?".
 * Before it existed the only signal was `error`, which the core could only
 * populate when the failure text happened to start with the `[LA-` prefix —
 * so `max_tool_iterations`, `max_retry` exhaustion and a human-approval
 * block were all reported as a run with `error === null`, i.e. as SUCCESS.
 * Callers could not tell a failed run from a successful one without digging
 * into the trace.
 */
final class RunResult implements \Stringable
{
    /**
     * @param list<array<string, mixed>> $toolCalls
     * @param array<string, mixed>|null $usage
     * @param list<array<string, mixed>> $trace
     */
    public function __construct(
        public string $content = '',
        public ?string $reasoningContent = null,
        public array $toolCalls = [],
        public ?array $usage = null,
        public ?string $traceId = null,
        public array $trace = [],
        public ?string $error = null,
        public bool $success = true,
        public ?string $stage = null,
    ) {
    }

    public function __toString(): string
    {
        return $this->content;
    }
}
