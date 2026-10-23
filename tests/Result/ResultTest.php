<?php

declare(strict_types=1);

namespace LightAgent\Tests\Result;

use LightAgent\Result\RunResult;
use LightAgent\Result\StreamEvent;
use PHPUnit\Framework\TestCase;

final class ResultTest extends TestCase
{
    public function test_run_result_defaults(): void
    {
        $r = new RunResult(content: 'hello');
        self::assertSame('hello', $r->content);
        self::assertNull($r->reasoningContent);
        self::assertSame([], $r->toolCalls);
        self::assertNull($r->usage);
        self::assertNull($r->traceId);
        self::assertSame([], $r->trace);
        self::assertNull($r->error);
    }

    public function test_run_result_to_string_returns_content(): void
    {
        $r = new RunResult(content: 'the answer');
        self::assertSame('the answer', (string) $r);
    }

    public function test_stream_event_string_data(): void
    {
        $e = new StreamEvent(type: 'token', data: 'chunk');
        self::assertSame('chunk', (string) $e);
        self::assertSame('token', $e->type);
        self::assertNull($e->traceId);
    }

    public function test_stream_event_null_data_renders_empty(): void
    {
        $e = new StreamEvent(type: 'done');
        self::assertSame('', (string) $e);
    }

    public function test_stream_event_array_data_renders_json(): void
    {
        $e = new StreamEvent(type: 'tool', data: ['a' => 1]);
        self::assertSame('{"a":1}', (string) $e);
    }
}
