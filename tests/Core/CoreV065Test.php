<?php

declare(strict_types=1);

namespace LightAgent\Tests\Core;

use LightAgent\Core\LightAgent;
use LightAgent\Errors\ErrorTaxonomy;
use LightAgent\Llm\ChatCompletion;
use LightAgent\Llm\ChatCompletionChunk;
use LightAgent\Llm\ChatMessage;
use LightAgent\Result\RunResult;
use LightAgent\Result\StreamEvent;
use LightAgent\Tools\ToolDispatcher;
use LightAgent\Tools\ToolFunction;
use PHPUnit\Framework\TestCase;

/**
 * Mock chat-completions backends mirroring tests/test_v065_core.py.
 */
final class StaticCompletions
{
    public array $calls = [];

    public function __construct(private readonly string $content = 'hello')
    {
    }

    public function create(array $params): ChatCompletion
    {
        $this->calls[] = $params;
        return new ChatCompletion(
            choices: [['message' => new ChatMessage(content: $this->content), 'finish_reason' => 'stop']],
        );
    }
}

final class ToolCallCompletions
{
    public array $calls = [];

    public function create(array $params): ChatCompletion
    {
        $this->calls[] = $params;
        if (count($this->calls) === 1) {
            return new ChatCompletion(
                choices: [[
                    'message' => new ChatMessage(
                        content: null,
                        toolCalls: [[
                            'id' => 'call_runtime_add',
                            'type' => 'function',
                            'function' => ['name' => 'runtime_add', 'arguments' => json_encode(['a' => 20, 'b' => 22])],
                        ]],
                    ),
                    'finish_reason' => 'tool_calls',
                ]],
            );
        }
        return new ChatCompletion(
            choices: [['message' => new ChatMessage(content: 'ok 42'), 'finish_reason' => 'stop']],
        );
    }
}

final class LoopingStreamToolCallCompletions
{
    public array $calls = [];

    public function __construct(private readonly int $maxCreateCalls)
    {
    }

    public function create(array $params): iterable
    {
        $this->calls[] = $params;
        if (count($this->calls) > $this->maxCreateCalls) {
            throw new \AssertionError('stream loop exceeded max_retry');
        }

        $callId = 'call_runtime_add_' . count($this->calls);
        $toolCallChunk = new ChatCompletionChunk(
            choices: [[
                'index' => 0,
                'delta' => ['tool_calls' => [[
                    'index' => 0,
                    'id' => $callId,
                    'function' => ['name' => 'runtime_add', 'arguments' => json_encode(['a' => 20, 'b' => 22])],
                ]]],
                'finish_reason' => null,
            ]],
        );
        $finishChunk = new ChatCompletionChunk(
            choices: [[
                'index' => 0,
                'delta' => [],
                'finish_reason' => 'tool_calls',
            ]],
        );
        yield $toolCallChunk;
        yield $finishChunk;
    }
}

final class ErrorCompletions
{
    public function __construct(private readonly int $statusCode)
    {
    }

    public function create(array $params): never
    {
        throw new \LightAgent\Llm\LlmException('provider failure', statusCode: $this->statusCode);
    }
}

/**
 * Testable LightAgent exposing client injection.
 */
final class TestableAgent extends LightAgent
{
    public function setClient(mixed $completions): void
    {
        $this->client = new \LightAgent\Tests\Core\FakeClient($completions);
    }

    public function exposeToolDispatcher(): ToolDispatcher
    {
        return $this->toolDispatcher;
    }
}

final class FakeClient
{
    public FakeCompletions $chat;

    public function __construct(mixed $completions)
    {
        $this->chat = new FakeCompletions($completions);
    }

    public function __call(string $name, array $args): mixed
    {
        if ($name === 'chat') {
            return $this->chat;
        }
        throw new \BadMethodCallException($name);
    }
}

final class FakeCompletions
{
    public function __construct(private readonly mixed $backend)
    {
    }

    public function create(array $params): mixed
    {
        return $this->backend->create($params);
    }
}

final class CoreV065Test extends TestCase
{
    private function makeAgent(array $kwargs = []): TestableAgent
    {
        return new TestableAgent(array_merge([
            'model' => 'gpt-4o-mini',
            'api_key' => 'test-key',
            'base_url' => 'http://127.0.0.1:9/v1',
            'auto_discover_skills' => false,
        ], $kwargs));
    }

    private function runtimeAdd(): ToolFunction
    {
        return new ToolFunction(
            callable: static fn (float|int $a, float|int $b): array => ['result' => $a + $b],
            toolInfo: [
                'tool_name' => 'runtime_add',
                'tool_description' => 'Add two numbers.',
                'tool_params' => [
                    ['name' => 'a', 'type' => 'number', 'description' => 'left operand', 'required' => true],
                    ['name' => 'b', 'type' => 'number', 'description' => 'right operand', 'required' => true],
                ],
            ],
        );
    }

    public function test_default_run_result_remains_string(): void
    {
        $agent = $this->makeAgent();
        $completions = new StaticCompletions('hello');
        $agent->setClient($completions);

        $result = $agent->run('hello');

        self::assertSame('hello', $result);
        self::assertIsString($result);
    }

    public function test_object_run_result_is_opt_in(): void
    {
        $agent = $this->makeAgent();
        $agent->setClient(new StaticCompletions('hello'));

        $result = $agent->run('hello', ['result_format' => 'object']);

        self::assertInstanceOf(RunResult::class, $result);
        self::assertSame('hello', $result->content);
        self::assertNotSame('', $result->traceId);
        self::assertNull($result->error);
    }

    public function test_runtime_tools_are_recorded_in_run_result(): void
    {
        $agent = $this->makeAgent();
        $completions = new ToolCallCompletions();
        $agent->setClient($completions);

        $result = $agent->run('add', ['tools' => [$this->runtimeAdd()], 'result_format' => 'object']);

        self::assertInstanceOf(RunResult::class, $result);
        self::assertSame('ok 42', $result->content);
        self::assertSame('runtime_add', $result->toolCalls[0]['name']);
        // The tool result message content is the serialized JSON
        $lastContent = $completions->calls[1]['messages'][count($completions->calls[1]['messages']) - 1]['content'];
        self::assertSame('{"result":42}', $lastContent);
    }

    public function test_model_errors_can_return_structured_result(): void
    {
        $agent = $this->makeAgent();
        $agent->setClient(new ErrorCompletions(401));

        $result = $agent->run('hello', ['result_format' => 'object']);

        self::assertInstanceOf(RunResult::class, $result);
        self::assertStringStartsWith('[LA-401]', (string) $result->error);
        self::assertSame($result->error, $result->content);
    }

    public function test_stream_default_remains_generator_and_event_is_opt_in(): void
    {
        $agent = $this->makeAgent();
        $agent->setClient(new ErrorCompletions(429));

        $legacyStream = $agent->run('hello', ['stream' => true]);
        self::assertInstanceOf(\Generator::class, $legacyStream);
        $first = $legacyStream->current();
        self::assertIsString($first);
        self::assertStringStartsWith('[LA-429]', $first);

        $eventStream = $agent->run('hello', ['stream' => true, 'result_format' => 'event']);
        self::assertInstanceOf(\Generator::class, $eventStream);
        $event = $eventStream->current();
        self::assertInstanceOf(StreamEvent::class, $event);
        self::assertSame('error', $event->type);
        self::assertStringStartsWith('[LA-429]', (string) $event->data);
    }

    public function test_stream_tool_loop_stops_at_max_retry(): void
    {
        $agent = $this->makeAgent();
        $completions = new LoopingStreamToolCallCompletions(maxCreateCalls: 2);
        $agent->setClient($completions);

        $chunks = [];
        foreach ($agent->run('loop', ['tools' => [$this->runtimeAdd()], 'stream' => true, 'max_retry' => 2, 'trace' => true]) as $chunk) {
            $chunks[] = $chunk;
        }

        self::assertCount(2, $completions->calls);
        $found = false;
        foreach ($chunks as $chunk) {
            if (is_string($chunk) && str_contains($chunk, 'Max tool iterations(2) reached.')) {
                $found = true;
            }
        }
        self::assertTrue($found);

        $trace = $agent->exportTrace();
        $lastData = $trace[count($trace) - 1]['data'];
        self::assertSame(false, $lastData['success']);
        self::assertSame('max_tool_iterations_reached', $lastData['error']);
        self::assertSame('max_tool_iterations', $lastData['stage']);
        self::assertSame(2, $lastData['max_tool_iterations']);
    }

    public function test_stream_tool_loop_respects_max_tool_iterations(): void
    {
        $agent = $this->makeAgent();
        $completions = new LoopingStreamToolCallCompletions(maxCreateCalls: 1);
        $agent->setClient($completions);

        $chunks = [];
        foreach ($agent->run('loop', [
            'tools' => [$this->runtimeAdd()],
            'stream' => true,
            'max_retry' => 5,
            'max_tool_iterations' => 1,
            'trace' => true,
        ]) as $chunk) {
            $chunks[] = $chunk;
        }

        self::assertCount(1, $completions->calls);
        $found = false;
        foreach ($chunks as $chunk) {
            if (is_string($chunk) && str_contains($chunk, 'Max tool iterations(1) reached.')) {
                $found = true;
            }
        }
        self::assertTrue($found);
        $trace = $agent->exportTrace();
        self::assertSame(1, $trace[count($trace) - 1]['data']['max_tool_iterations']);
    }

    public function test_stream_tool_loop_uses_finish_run_hooks(): void
    {
        $events = [];
        $collectLifecycle = static function (object $ctx) use (&$events) {
            if (in_array($ctx->phase, ['on_error', 'after_run'], true)) {
                $events[] = [$ctx->phase, $ctx->payload];
            }
            return null;
        };

        $agent = $this->makeAgent(['hooks' => [$collectLifecycle]]);
        $agent->setClient(new LoopingStreamToolCallCompletions(maxCreateCalls: 2));

        foreach ($agent->run('loop', ['tools' => [$this->runtimeAdd()], 'stream' => true, 'max_retry' => 2, 'trace' => true]) as $_) {
        }

        self::assertSame(['on_error', 'after_run'], array_column($events, 0));
        self::assertSame('max_tool_iterations', $events[0][1]['stage']);
        self::assertSame('max_tool_iterations_reached', $events[0][1]['error']);
        $runEndEvents = array_values(array_filter(
            $agent->exportTrace(),
            static fn (array $e): bool => $e['type'] === 'run_end',
        ));
        self::assertCount(1, $runEndEvents);
        self::assertSame('max_tool_iterations', $runEndEvents[0]['data']['stage']);
    }

    public function test_on_error_capture_in_stream_tool_loop(): void
    {
        $errors = [];
        $captureError = static function (object $ctx) use (&$errors) {
            if ($ctx->phase === 'on_error') {
                $errors[] = $ctx->payload;
            }
            return null;
        };

        $agent = $this->makeAgent(['hooks' => [$captureError]]);
        $agent->setClient(new LoopingStreamToolCallCompletions(maxCreateCalls: 1));

        foreach ($agent->run('loop', [
            'tools' => [$this->runtimeAdd()],
            'stream' => true,
            'max_retry' => 3,
            'max_tool_iterations' => 1,
            'trace' => true,
        ]) as $_) {
        }

        $found = false;
        foreach ($errors as $err) {
            if (($err['error'] ?? null) === 'max_tool_iterations_reached') {
                $found = true;
                self::assertSame(false, $err['success']);
                self::assertSame('max_tool_iterations', $err['stage']);
                self::assertSame('Max tool iterations(1) reached.', $err['message']);
                self::assertSame(1, $err['max_tool_iterations']);
            }
        }
        self::assertTrue($found, 'on_error hook did not capture max_tool_iterations payload');
    }

    public function test_tool_parameter_validation_missing_required_and_wrong_type(): void
    {
        $tool = $this->runtimeAdd();
        $dispatcher = new ToolDispatcher(
            ['runtime_add' => $tool],
            ['runtime_add' => $tool->toolInfo],
        );

        $missing = $dispatcher->dispatch('runtime_add', ['a' => 1]);
        $wrongType = $dispatcher->dispatch('runtime_add', ['a' => '1', 'b' => 2]);

        self::assertStringStartsWith('[LA-TOOL]', (string) $missing);
        self::assertStringContainsString('missing required parameter `b`', (string) $missing);
        self::assertStringStartsWith('[LA-TOOL]', (string) $wrongType);
        self::assertStringContainsString('parameter `a` expected `number`', (string) $wrongType);
    }

    public function test_lightagent_error_is_catchable_and_classification_still_works(): void
    {
        $error = new \LightAgent\Errors\LightAgentError('LA-413', details: 'too large');
        self::assertSame('LA-413', $error->code2);
        self::assertStringContainsString('[LA-413]', $error->getMessage());

        $exc = new class ('quota exceeded') extends \RuntimeException {
            public int $statusCode = 429;
        };
        self::assertSame('LA-429', ErrorTaxonomy::classify($exc)->code);
    }
}
