<?php

declare(strict_types=1);

namespace LightAgent\Tests\Core;

use LightAgent\Core\LightAgent;
use LightAgent\Llm\ChatCompletionChunk;
use LightAgent\Tools\ToolFunction;
use PHPUnit\Framework\TestCase;

/**
 * Streaming backend: asks for a tool call, then answers with text.
 *
 * Named distinctly from the helpers in StreamingOutputTest — both live in
 * the LightAgent\Tests\Core namespace and would otherwise collide.
 */
final class LeakyToolStreamCompletions
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
                        'id' => 'call_boom',
                        'function' => ['name' => 'boom', 'arguments' => json_encode([])],
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
                'delta' => ['content' => 'Recovered.'],
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
 * Agent wired to an injectable completions backend.
 */
final class LeakTestAgent extends LightAgent
{
    public function setCompletions(mixed $completions): void
    {
        $this->client = new LeakFakeClient($completions);
    }
}

final class LeakFakeClient
{
    public function __construct(private readonly mixed $backend)
    {
    }

    public function chat(): LeakFakeCompletions
    {
        return new LeakFakeCompletions($this->backend);
    }
}

final class LeakFakeCompletions
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
 * Regression test for the streaming tool-error message (H-2).
 *
 * The tool result is fed BACK TO THE MODEL, so it must not carry the PHP
 * stack trace: the trace leaks absolute server paths into the prompt and
 * burns tokens. The full trace belongs in the server-side log, not in the
 * model-facing string.
 */
final class StreamToolErrorLeakTest extends TestCase
{
    private function makeAgent(): LeakTestAgent
    {
        return new LeakTestAgent([
            'model' => 'gpt-4o-mini',
            'api_key' => 'test-key',
            'base_url' => 'http://127.0.0.1:9/v1',
            'auto_discover_skills' => false,
        ]);
    }

    /**
     * A tool that always fails with an exception carrying a stack trace.
     */
    private function boomTool(): ToolFunction
    {
        return new ToolFunction(
            callable: static function (): string {
                throw new \RuntimeException('the tool exploded on purpose');
            },
            toolInfo: [
                'tool_name' => 'boom',
                'tool_description' => 'Always fails.',
                'tool_params' => [],
            ],
        );
    }

    public function test_the_tool_error_message_sent_to_the_model_carries_no_stack_trace(): void
    {
        $agent = $this->makeAgent();
        $backend = new LeakyToolStreamCompletions();
        $agent->setCompletions($backend);

        foreach ($agent->run('go', ['stream' => true, 'tools' => [$this->boomTool()]]) as $_chunk) {
            // consume the stream
        }

        self::assertGreaterThanOrEqual(
            2,
            count($backend->calls),
            'the agent must have sent the tool result back to the model',
        );

        $messages = $backend->calls[1]['messages'] ?? [];
        self::assertIsArray($messages);

        $toolMessages = array_values(array_filter(
            $messages,
            static fn ($m): bool => is_array($m) && ($m['role'] ?? null) === 'tool',
        ));
        self::assertNotEmpty($toolMessages, 'no tool message was sent back to the model');

        $content = (string) ($toolMessages[0]['content'] ?? '');
        self::assertNotSame('', $content);

        // The PHP stack trace marker must not appear.
        self::assertStringNotContainsString(
            '#0 ',
            $content,
            'the tool error message must not carry a PHP stack trace',
        );
        // A PHP stack frame looks like `#0 /abs/path/file.php(12): fn()`.
        self::assertDoesNotMatchRegularExpression(
            '/#\d+\s+[^\s]+\.php\(\d+\)/',
            $content,
            'the tool error message must not carry a PHP stack frame',
        );
        // The absolute path of the project must not leak into the prompt.
        self::assertStringNotContainsString(
            '\\src\\',
            $content,
            'the tool error message must not leak server paths',
        );
        // The failure itself IS still reported to the model.
        self::assertStringContainsString('LA-TOOL', $content);
    }
}
