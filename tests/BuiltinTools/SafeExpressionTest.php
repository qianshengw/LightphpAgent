<?php

declare(strict_types=1);

namespace LightAgent\Tests\BuiltinTools;

use LightAgent\BuiltinTools\SafeExpressionError;
use LightAgent\BuiltinTools\SafeExpressionTool;
use PHPUnit\Framework\TestCase;

final class SafeExpressionTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function expressionProvider(): array
    {
        return [
            'int arithmetic' => ['45 * 9827', 442215],
            'float division' => ['7 / 2', 3.5],
            'floor division' => ['7 // 2', 3],
            'modulo' => ['10 % 3', 1],
            'parens' => ['(2 + 3) * 4', 20],
            'unary minus' => ['-(3 + 4)', -7],
            'string concat' => ['"ab" + "cd"', 'abcd'],
            'list literal' => ['[1, 2, 3]', [1, 2, 3]],
            'comparison' => ['3 < 5', true],
            'chained comparison' => ['1 < 2', true],
            'and or' => ['1 and 0', 0],
            'not' => ['not 0', true],
            'bool literals' => ['True', true],
            'none literal' => ['None', null],
            'equality' => ['"a" == "a"', true],
            'not equal' => ['1 != 2', true],
        ];
    }

    /**
     * @param mixed $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('expressionProvider')]
    public function test_evaluates_safe_expressions(string $expression, mixed $expected): void
    {
        self::assertSame($expected, SafeExpressionTool::evaluate($expression));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function blockedProvider(): array
    {
        return [
            'name access' => ['__import__("os")'],
            'attribute access' => ['open("file")'],
            'call' => ['len([1, 2])'],
            'keyword id' => ['foo'],
            'empty' => ['   '],
            'unterminated string' => ['"abc'],
            'division by zero' => ['1 / 0'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blockedProvider')]
    public function test_blocks_unsafe_expressions(string $expression): void
    {
        $this->expectException(SafeExpressionError::class);
        SafeExpressionTool::evaluate($expression);
    }

    public function test_tool_wrapper_formats_success(): void
    {
        $result = SafeExpressionTool::safeExpression('2 * 21');
        self::assertSame('42', $result);
    }

    public function test_tool_wrapper_formats_error(): void
    {
        $result = SafeExpressionTool::safeExpression('__import__("os")');
        self::assertStringStartsWith('安全表达式错误：', $result);
    }

    public function test_string_length_limit(): void
    {
        $this->expectException(SafeExpressionError::class);
        SafeExpressionTool::evaluate('"' . str_repeat('a', 5000) . '"');
    }

    public function test_collection_size_limit(): void
    {
        $this->expectException(SafeExpressionError::class);
        SafeExpressionTool::evaluate('[' . implode(',', range(1, 100)) . ']');
    }

    public function test_node_count_limit(): void
    {
        $this->expectException(SafeExpressionError::class);
        SafeExpressionTool::evaluate('[' . implode(',', array_fill(0, 200, '1')) . ']');
    }
}
