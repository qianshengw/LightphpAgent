<?php

declare(strict_types=1);

namespace LightAgent\Tests\Core;

use LightAgent\Core\LightAgent;
use LightAgent\Llm\ChatCompletion;
use LightAgent\Llm\ChatMessage;
use LightAgent\Tools\ToolFunction;
use PHPUnit\Framework\TestCase;

/**
 * A backend that ALWAYS asks for one more tool call — the shape a model
 * takes when it gets stuck in a tool loop.
 *
 * `$parallel` is how many tool calls are requested per round: a model may
 * ask for several PARALLEL calls in one round, which is exactly why the
 * number of EXECUTIONS needs a bound of its own.
 */
final class ToolCapCompletions
{
    public int $calls = 0;

    public function __construct(private readonly int $parallel = 1)
    {
    }

    public function create(array $params): ChatCompletion
    {
        $this->calls++;
        $toolCalls = [];
        for ($i = 0; $i < $this->parallel; $i++) {
            $toolCalls[] = [
                'id' => 'call_' . $this->calls . '_' . $i,
                'type' => 'function',
                'function' => ['name' => 'ping', 'arguments' => '{}'],
            ];
        }
        return new ChatCompletion(
            choices: [[
                'message' => new ChatMessage(content: '', toolCalls: $toolCalls),
                'finish_reason' => 'tool_calls',
            ]],
            usage: ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        );
    }
}

/**
 * Agent with an injectable completions backend.
 */
final class ToolCapAgent extends LightAgent
{
    public function useBackend(mixed $backend): void
    {
        $this->client = new ToolCapClient($backend);
    }
}

final class ToolCapClient
{
    public function __construct(private readonly mixed $backend)
    {
    }

    public function chat(): ToolCapChat
    {
        return new ToolCapChat($this->backend);
    }
}

final class ToolCapChat
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
 * Regression tests for the tool EXECUTION cap (`max_tool_calls`).
 *
 * `max_tool_iterations` bounds the ROUNDS of the tool loop, but a model may
 * request several PARALLEL tool calls in one round, so the number of
 * executions is rounds x calls-per-round and is not bounded by the
 * iteration cap alone. `max_tool_calls` closes that gap.
 *
 * THE BUG: `max_tool_calls` used to be readable ONLY as a CONSTRUCTOR
 * option. Passing it to `run()` — the natural thing to do, since its
 * sibling `max_tool_iterations` is a run option — was SILENTLY IGNORED, and
 * the execution count stayed unbounded. Observed live against a real
 * provider: 10 parallel calls x 2 rounds = 20 executions with
 * `max_tool_calls => 3` passed to `run()`.
 */
final class ToolExecutionCapTest extends TestCase
{
    private function agent(array $options = []): ToolCapAgent
    {
        return new ToolCapAgent(array_merge([
            'model' => 'gpt-4o-mini',
            'api_key' => 'test-key',
            'base_url' => 'http://127.0.0.1:9/v1',
            'auto_discover_skills' => false,
        ], $options));
    }

    /**
     * A tool that counts its own invocations.
     */
    private function pingTool(?int &$counter): ToolFunction
    {
        return new ToolFunction(
            callable: static function () use (&$counter): string {
                $counter = ($counter ?? 0) + 1;
                return 'pong';
            },
            toolInfo: [
                'tool_name' => 'ping',
                'tool_description' => 'Ping.',
                'tool_params' => [],
            ],
        );
    }

    /**
     * THE REGRESSION: `max_tool_calls` passed to run() must bound the
     * executions.
     */
    public function test_max_tool_calls_as_a_run_option_bounds_the_executions(): void
    {
        $agent = $this->agent();
        $backend = new ToolCapCompletions();
        $agent->useBackend($backend);

        $counter = 0;
        $result = $agent->run('go', [
            'tools' => [$this->pingTool($counter)],
            'max_tool_calls' => 3,
            'max_retry' => 10,
        ]);

        self::assertSame(3, $counter, 'the run option must bound the tool executions');
        self::assertIsString($result);
        self::assertStringContainsString('max tool calls (3) reached', $result);
    }

    /**
     * The constructor option stays the DEFAULT for runs that do not
     * override it.
     */
    public function test_max_tool_calls_as_a_constructor_option_is_the_default(): void
    {
        $agent = $this->agent(['max_tool_calls' => 2]);
        $backend = new ToolCapCompletions();
        $agent->useBackend($backend);

        $counter = 0;
        $result = $agent->run('go', [
            'tools' => [$this->pingTool($counter)],
            'max_retry' => 10,
        ]);

        self::assertSame(2, $counter);
        self::assertIsString($result);
        self::assertStringContainsString('max tool calls (2) reached', $result);
    }

    /**
     * The run option WINS over the constructor value — the same precedence
     * `max_tool_iterations` has.
     */
    public function test_the_run_option_overrides_the_constructor_option(): void
    {
        $agent = $this->agent(['max_tool_calls' => 2]);
        $backend = new ToolCapCompletions();
        $agent->useBackend($backend);

        $counter = 0;
        $result = $agent->run('go', [
            'tools' => [$this->pingTool($counter)],
            'max_tool_calls' => 5,
            'max_retry' => 10,
        ]);

        self::assertSame(5, $counter);
        self::assertIsString($result);
        self::assertStringContainsString('max tool calls (5) reached', $result);
    }

    /**
     * The cap counts EXECUTIONS, not rounds: parallel calls in one round
     * count one by one.
     */
    public function test_parallel_calls_in_one_round_count_one_by_one(): void
    {
        $agent = $this->agent();
        $backend = new ToolCapCompletions(parallel: 4);
        $agent->useBackend($backend);

        $counter = 0;
        $result = $agent->run('go', [
            'tools' => [$this->pingTool($counter)],
            'max_tool_calls' => 5,
            'max_retry' => 10,
        ]);

        self::assertSame(5, $counter, 'four parallel calls per round must count as four');
        self::assertIsString($result);
        self::assertStringContainsString('max tool calls (5) reached', $result);
    }

    /**
     * Without a cap the loop is bounded by `max_tool_iterations` only.
     */
    public function test_without_a_cap_the_iteration_limit_still_bounds_the_loop(): void
    {
        $agent = $this->agent();
        $backend = new ToolCapCompletions();
        $agent->useBackend($backend);

        $counter = 0;
        $result = $agent->run('go', [
            'tools' => [$this->pingTool($counter)],
            'max_tool_iterations' => 2,
            'max_retry' => 10,
        ]);

        self::assertSame(2, $counter);
        self::assertIsString($result);
        self::assertStringContainsString('Max tool iterations(2) reached.', $result);
    }

    /**
     * A non-positive cap is a configuration error, not a silently
     * unlimited run.
     */
    public function test_a_non_positive_cap_is_rejected(): void
    {
        $agent = $this->agent();
        $backend = new ToolCapCompletions();
        $agent->useBackend($backend);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('max_tool_calls must be at least 1');
        $agent->run('go', ['max_tool_calls' => 0]);
    }

    /**
     * The failure is reported as a failure: stage `max_tool_calls`,
     * success false.
     */
    public function test_the_cap_is_reported_as_a_failed_run(): void
    {
        $agent = $this->agent();
        $backend = new ToolCapCompletions();
        $agent->useBackend($backend);

        $counter = 0;
        $result = $agent->run('go', [
            'tools' => [$this->pingTool($counter)],
            'max_tool_calls' => 2,
            'max_retry' => 10,
            'result_format' => 'object',
        ]);

        self::assertInstanceOf(\LightAgent\Result\RunResult::class, $result);
        self::assertFalse($result->success);
        self::assertSame('max_tool_calls', $result->stage);
        self::assertNotNull($result->error);
        self::assertStringContainsString('LA-TOOL', $result->error);
    }
}
