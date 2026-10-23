<?php

declare(strict_types=1);

namespace LightAgent\Tests\Tools;

use LightAgent\BuiltinTools\PythonExecutorTool;
use LightAgent\Tools\ToolDispatcher;
use LightAgent\Tools\ToolFunction;
use LightAgent\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the tool registry and the dispatch layer.
 *
 * M-1/V5-32: a failing tool must not return a PHP stack trace (it leaks
 *   absolute source paths into the model context).
 * M-4: `registerTool()` must validate the `tool_params` shape BEFORE it
 *   mutates any state.
 * M-5: `fromSnapshot()` must build the OpenAI schemas and enforce the
 *   tool-name grammar.
 * M-10: a one-line wrapper closure around the Python executor must be
 *   blocked in dispatch, not only the executor's own closures.
 */
final class ToolRegistrySecurityTest extends TestCase
{
    public function test_failing_tool_does_not_leak_a_stack_trace(): void
    {
        $registry = new ToolRegistry();
        $registry->registerTool(new ToolFunction(
            callable: static function (): string {
                throw new \RuntimeException('boom');
            },
            toolInfo: [
                'tool_name' => 'boom',
                'tool_description' => 'Always fails.',
                'tool_params' => [],
            ],
        ));
        $dispatcher = new ToolDispatcher($registry);

        $result = (string) $dispatcher->dispatch('boom', []);

        self::assertStringStartsWith('[LA-TOOL]', $result);
        self::assertStringContainsString('boom', $result);
        self::assertStringNotContainsString('#0 ', $result, 'the PHP stack trace must not reach the model');
        self::assertStringNotContainsString('ToolFunction.php', $result);
    }

    public function test_invalid_tool_params_are_rejected_before_registration(): void
    {
        $registry = new ToolRegistry();

        try {
            $registry->registerTool(new ToolFunction(
                callable: static fn (): string => 'x',
                toolInfo: [
                    'tool_name' => 'bad_tool',
                    'tool_description' => 'Broken params.',
                    'tool_params' => ['notanarray'],
                ],
            ));
            self::fail('a malformed tool_params definition must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('tool_params', $e->getMessage());
        }

        self::assertSame([], $registry->functionMappings, 'the tool must not be half-registered');
        self::assertSame([], $registry->openaiFunctionSchemas);
    }

    public function test_tool_without_params_is_still_registrable(): void
    {
        $registry = new ToolRegistry();

        $registry->registerTool(new ToolFunction(
            callable: static fn (): string => 'x',
            toolInfo: ['tool_name' => 'no_params', 'tool_description' => 'No arguments.'],
        ));

        self::assertArrayHasKey('no_params', $registry->functionMappings);
        self::assertCount(1, $registry->getTools());
    }

    public function test_snapshot_builds_openai_schemas(): void
    {
        $tool = new ToolFunction(
            callable: static fn (string $text): string => $text,
            toolInfo: [
                'tool_name' => 'echo',
                'tool_description' => 'Echo the text.',
                'tool_params' => [
                    ['name' => 'text', 'type' => 'string', 'description' => 'the text', 'required' => true],
                ],
            ],
        );

        $registry = ToolRegistry::fromSnapshot(['echo' => $tool], ['echo' => $tool->toolInfo]);

        $schemas = $registry->getTools();
        self::assertCount(1, $schemas);
        self::assertSame('echo', $schemas[0]['function']['name']);
        self::assertSame('string', $schemas[0]['function']['parameters']['properties']['text']['type']);
    }

    public function test_snapshot_rejects_an_invalid_tool_name(): void
    {
        $tool = new ToolFunction(
            callable: static fn (): string => 'x',
            toolInfo: ['tool_name' => 'x', 'tool_description' => 'd', 'tool_params' => []],
        );

        $this->expectException(\InvalidArgumentException::class);
        ToolRegistry::fromSnapshot(['not a valid name!' => $tool]);
    }

    public function test_wrapper_closure_around_the_python_executor_is_blocked(): void
    {
        $wrapper = static fn (string $code): string => PythonExecutorTool::executeCode($code);

        self::assertTrue(ToolRegistry::isUnsafePythonCallable($wrapper));

        $registry = new ToolRegistry();
        $registry->registerTool(new ToolFunction(
            callable: $wrapper,
            toolInfo: ['tool_name' => 'helper_py', 'tool_description' => 'A wrapper.', 'tool_params' => []],
        ));
        $dispatcher = new ToolDispatcher($registry);

        $result = (string) $dispatcher->dispatch('helper_py', ['code' => 'print(1)']);

        self::assertStringContainsString('LA-SANDBOX', $result);
    }

    public function test_ordinary_callables_are_not_blocked(): void
    {
        self::assertFalse(ToolRegistry::isUnsafePythonCallable(static fn (int $a): int => $a * 2));
    }
}
