<?php

declare(strict_types=1);

namespace LightAgent\Tests\Core;

use LightAgent\Core\LightAgent;
use LightAgent\Llm\ChatCompletionChunk;
use LightAgent\Result\StreamEvent;
use LightAgent\Tools\ToolFunction;
use PHPUnit\Framework\TestCase;

/**
 * Streaming backend emitting plain assistant text in several deltas.
 */
final class TextStreamCompletions
{
    public array $calls = [];

    /**
     * @param list<string> $pieces
     */
    public function __construct(private readonly array $pieces = ['Hello ', 'world ', 'again'])
    {
    }

    public function create(array $params): iterable
    {
        $this->calls[] = $params;
        foreach ($this->pieces as $piece) {
            yield new ChatCompletionChunk(
                choices: [[
                    'index' => 0,
                    'delta' => ['content' => $piece],
                    'finish_reason' => null,
                ]],
            );
        }
        yield new ChatCompletionChunk(
            choices: [[
                'index' => 0,
                'delta' => [],
                'finish_reason' => 'stop',
            ]],
            usage: ['prompt_tokens' => 4, 'completion_tokens' => 5, 'total_tokens' => 9],
        );
    }
}

/**
 * Streaming backend that asks for a tool call, then answers with text.
 */
final class ToolThenTextStreamCompletions
{
    public array $calls = [];

    public function create(array $params): iterable
    {
        $this->calls[] = $params;
        if (count($this->calls) === 1) {
            yield new ChatCompletionChunk(
                choices: [[
                    'index' => 0,
                    'delta' => ['tool_calls' => [[
                        'index' => 0,
                        'id' => 'call_shout',
                        'function' => ['name' => 'shout', 'arguments' => json_encode(['text' => 'hey'])],
                    ]]],
                    'finish_reason' => null,
                ]],
            );
            yield new ChatCompletionChunk(
                choices: [[
                    'index' => 0,
                    'delta' => [],
                    'finish_reason' => 'tool_calls',
                ]],
            );
            return;
        }
        yield new ChatCompletionChunk(
            choices: [[
                'index' => 0,
                'delta' => ['content' => 'Done.'],
                'finish_reason' => null,
            ]],
        );
        yield new ChatCompletionChunk(
            choices: [[
                'index' => 0,
                'delta' => [],
                'finish_reason' => 'stop',
            ]],
        );
    }
}

/**
 * Non-streaming backend returning a plain completion.
 *
 * Named distinctly from the StaticCompletions helper in CoreV065Test — both
 * live in the LightAgent\Tests\Core namespace and would otherwise collide.
 */
final class StaticTextCompletions
{
    public array $calls = [];

    public function __construct(private readonly string $content = 'Hello world again')
    {
    }

    public function create(array $params): \LightAgent\Llm\ChatCompletion
    {
        $this->calls[] = $params;
        return new \LightAgent\Llm\ChatCompletion(
            choices: [[
                'message' => new \LightAgent\Llm\ChatMessage(content: $this->content),
                'finish_reason' => 'stop',
            ]],
            usage: ['prompt_tokens' => 4, 'completion_tokens' => 5, 'total_tokens' => 9],
        );
    }
}

/**
 * Agent wired to an injectable completions backend.
 */
final class StreamTestableAgent extends LightAgent
{
    public function setCompletions(mixed $completions): void
    {
        $this->client = new StreamFakeClient($completions);
    }
}

final class StreamFakeClient
{
    public function __construct(private readonly mixed $backend)
    {
    }

    public function chat(): StreamFakeCompletions
    {
        return new StreamFakeCompletions($this->backend);
    }
}

final class StreamFakeCompletions
{
    public function __construct(private readonly mixed $backend)
    {
    }

    public function create(array $params): mixed
    {
        return $this->backend->create($params);
    }
}

/**
 * Contract tests for the streaming output surface.
 *
 * The framework documents two streaming shapes:
 *   - stream=true                  -> Generator of string|tool_call array|tool_result array
 *   - stream=true + result_format=event -> Generator of StreamEvent
 *
 * Both must carry the assistant's answer, and the event form must be honoured
 * on the success path (not only on error paths).
 */
final class StreamingOutputTest extends TestCase
{
    private function makeAgent(): StreamTestableAgent
    {
        return new StreamTestableAgent([
            'model' => 'gpt-4o-mini',
            'api_key' => 'test-key',
            'base_url' => 'http://127.0.0.1:9/v1',
            'auto_discover_skills' => false,
        ]);
    }

    private function shoutTool(): ToolFunction
    {
        return new ToolFunction(
            callable: static fn (string $text): string => strtoupper($text),
            toolInfo: [
                'tool_name' => 'shout',
                'tool_description' => 'Uppercase the given text.',
                'tool_params' => [
                    ['name' => 'text', 'type' => 'string', 'description' => 'text', 'required' => true],
                ],
            ],
        );
    }

    public function test_stream_yields_assistant_content(): void
    {
        $agent = $this->makeAgent();
        $agent->setCompletions(new TextStreamCompletions(['Hello ', 'world ', 'again']));

        $chunks = [];
        foreach ($agent->run('hi', ['stream' => true]) as $chunk) {
            $chunks[] = $chunk;
        }

        $strings = array_values(array_filter($chunks, 'is_string'));
        self::assertNotEmpty($strings, 'stream produced no string chunks');
        self::assertSame('Hello world again', implode('', $strings));
    }

    public function test_stream_event_format_yields_content_events(): void
    {
        $agent = $this->makeAgent();
        $agent->setCompletions(new TextStreamCompletions(['Hello ', 'world ', 'again']));

        $events = [];
        foreach ($agent->run('hi', ['stream' => true, 'result_format' => 'event']) as $event) {
            self::assertInstanceOf(StreamEvent::class, $event);
            $events[] = $event;
        }

        self::assertNotEmpty($events);
        foreach ($events as $event) {
            self::assertSame('content', $event->type);
        }
        self::assertSame('Hello world again', implode('', array_map(
            static fn (StreamEvent $e): string => (string) $e->data,
            $events,
        )));
    }

    public function test_stream_event_format_wraps_tool_events(): void
    {
        $agent = $this->makeAgent();
        $agent->setCompletions(new ToolThenTextStreamCompletions());

        $types = [];
        $text = '';
        foreach ($agent->run('go', ['stream' => true, 'result_format' => 'event', 'tools' => [$this->shoutTool()]]) as $event) {
            self::assertInstanceOf(StreamEvent::class, $event);
            $types[] = $event->type;
            if ($event->type === 'content') {
                $text .= (string) $event->data;
            }
        }

        self::assertContains('tool_call', $types);
        self::assertContains('tool_result', $types);
        self::assertContains('content', $types);
        self::assertSame('Done.', $text);
    }

    public function test_stream_tool_events_remain_available_in_default_format(): void
    {
        $agent = $this->makeAgent();
        $agent->setCompletions(new ToolThenTextStreamCompletions());

        $chunks = [];
        foreach ($agent->run('go', ['stream' => true, 'tools' => [$this->shoutTool()]]) as $chunk) {
            $chunks[] = $chunk;
        }

        $toolCalls = array_values(array_filter(
            $chunks,
            static fn ($c): bool => is_array($c) && isset($c['name'], $c['arguments']),
        ));
        $toolResults = array_values(array_filter(
            $chunks,
            static fn ($c): bool => is_array($c) && isset($c['output']),
        ));

        self::assertCount(1, $toolCalls);
        self::assertSame('shout', $toolCalls[0]['name']);
        self::assertCount(1, $toolResults);
        self::assertSame('HEY', $toolResults[0]['output']);
    }

    public function test_non_stream_result_is_unaffected(): void
    {
        $agent = $this->makeAgent();
        $agent->setCompletions(new StaticTextCompletions('Hello world again'));

        $result = $agent->run('hi', ['result_format' => 'object']);

        self::assertInstanceOf(\LightAgent\Result\RunResult::class, $result);
        self::assertSame('Hello world again', $result->content);
        self::assertSame(9, $result->usage['total_tokens']);
    }
}
