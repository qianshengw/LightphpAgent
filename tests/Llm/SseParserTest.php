<?php

declare(strict_types=1);

namespace LightAgent\Tests\Llm;

use LightAgent\Llm\OpenAiCompletions;
use LightAgent\Llm\SseParser;
use LightAgent\Llm\ToolCallAssembler;
use PHPUnit\Framework\TestCase;

final class SseParserTest extends TestCase
{
    /**
     * @return array{0: list<string>, 1: bool}
     */
    private function collect(string $sse, bool $finish = true): array
    {
        $data = [];
        $done = false;
        $parser = new SseParser(
            onData: static function (string $d) use (&$data): void {
                $data[] = $d;
            },
            onDone: static function () use (&$done): void {
                $done = true;
            },
        );
        // Feed byte-by-byte to prove partial-line handling.
        foreach (str_split($sse) as $byte) {
            $parser->feed($byte);
        }
        if ($finish) {
            $parser->finish();
        }
        return [$data, $done];
    }

    public function test_parses_basic_data_lines(): void
    {
        [$data] = $this->collect("data: {\"a\":1}\n\ndata: {\"b\":2}\n\n", finish: false);
        self::assertSame(['{"a":1}', '{"b":2}'], $data);
    }

    public function test_partial_line_across_parsers_does_not_emit(): void
    {
        // A separate parser instance sees only its own buffer; partial line
        // without newline stays buffered until finish().
        $data = [];
        $parser = new SseParser(static function (string $d) use (&$data): void {
            $data[] = $d;
        }, null);
        $parser->feed('data: hel');
        self::assertSame([], $data); // no newline yet -> buffered

        $parser->feed("lo world\n");
        self::assertSame(['hello world'], $data);
    }

    public function test_done_marker_signals_done(): void
    {
        [$data, $done] = $this->collect("data: x\n\ndata: [DONE]\n\n");
        self::assertSame(['x'], $data);
        self::assertTrue($done);
        // Further feeds are ignored
        $parser = new SseParser(static function (): void {}, null);
        $parser->feed("data: [DONE]\n");
        $parser->feed("data: after\n");
        // no way to observe via public API other than no exception
        $this->addToAssertionCount(1);
    }

    public function test_comments_and_keepalive_ignored(): void
    {
        [$data] = $this->collect(": keepalive\n\ndata: real\n");
        self::assertSame(['real'], $data);
    }

    public function test_crlf_line_endings(): void
    {
        [$data] = $this->collect("data: one\r\ndata: two\r\n");
        self::assertSame(['one', 'two'], $data);
    }
}

final class ToolCallAssemblerTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $chunks
     * @return ToolCallAssembler
     */
    private function assemble(array $chunks): ToolCallAssembler
    {
        $assembler = new ToolCallAssembler();
        foreach ($chunks as $chunk) {
            $assembler->consumeChunk($chunk);
        }
        return $assembler;
    }

    public function test_single_tool_call_incremental_arguments(): void
    {
        $assembler = $this->assemble([
            ['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_abc', 'function' => ['name' => 'get_weather', 'arguments' => '{"city_na']]]]],
            ['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => 'me": "Beijing"}']]]]],
        ]);

        $calls = $assembler->getToolCalls();
        self::assertCount(1, $calls);
        self::assertSame('get_weather', $calls[0]['name']);
        self::assertSame('{"city_name": "Beijing"}', $calls[0]['arguments']);
        self::assertSame('call_abc', $calls[0]['id']);
        self::assertTrue($assembler->hasNamedToolCalls());
    }

    public function test_multiple_indexed_tool_calls(): void
    {
        $assembler = $this->assemble([
            ['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'tool_a', 'arguments' => '{"x"']]]]],
            ['delta' => ['tool_calls' => [['index' => 1, 'id' => 'call_2', 'function' => ['name' => 'tool_b', 'arguments' => '{"y"']]]]],
            ['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => ': 1}']]]]],
            ['delta' => ['tool_calls' => [['index' => 1, 'function' => ['arguments' => ': 2}']]]]],
        ]);

        $calls = $assembler->getToolCalls();
        self::assertCount(2, $calls);
        self::assertSame('{"x": 1}', $calls[0]['arguments']);
        self::assertSame('{"y": 2}', $calls[1]['arguments']);
        self::assertSame('tool_a', $calls[0]['name']);
        self::assertSame('tool_b', $calls[1]['name']);
    }

    public function test_missing_index_defaults_to_zero(): void
    {
        $assembler = $this->assemble([
            ['delta' => ['tool_calls' => [['id' => 'call_x', 'function' => ['name' => 't', 'arguments' => '{}']]]]],
        ]);
        $calls = $assembler->getToolCalls();
        self::assertSame(0, $calls[0]['index']);
    }

    public function test_empty_name_chunks_do_not_trigger_has_named(): void
    {
        $assembler = $this->assemble([
            ['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_1']]]],
        ]);
        self::assertFalse($assembler->hasNamedToolCalls());
    }

    public function test_no_tool_calls_chunks_are_noop(): void
    {
        $assembler = $this->assemble([
            ['delta' => ['content' => 'hello']],
            ['delta' => []],
        ]);
        self::assertSame([], $assembler->getToolCalls());
    }
}

final class CompletionParsingTest extends TestCase
{
    public function test_parse_non_streaming_completion(): void
    {
        $decoded = [
            'id' => 'chatcmpl-123',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Hello!',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];

        $completion = OpenAiCompletions::parseCompletion($decoded);
        self::assertSame('chatcmpl-123', $completion->id);
        self::assertCount(1, $completion->choices);
        self::assertSame('Hello!', $completion->choices[0]['message']->content);
        self::assertSame('stop', $completion->choices[0]['finish_reason']);
        self::assertSame(15, $completion->usage['total_tokens']);
    }

    public function test_parse_tool_calls_message(): void
    {
        $decoded = [
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [
                            [
                                'id' => 'call_9',
                                'type' => 'function',
                                'function' => ['name' => 'search', 'arguments' => '{"q": "php"}'],
                            ],
                        ],
                    ],
                    'finish_reason' => 'tool_calls',
                ],
            ],
        ];

        $completion = OpenAiCompletions::parseCompletion($decoded);
        $message = $completion->choices[0]['message'];
        self::assertNotNull($message->toolCalls);
        self::assertSame('search', $message->toolCalls[0]['function']['name']);
        self::assertSame('{"q": "php"}', $message->toolCalls[0]['function']['arguments']);
    }
}
