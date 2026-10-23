<?php

declare(strict_types=1);

namespace LightAgent\Tests\BuiltinTools;

use LightAgent\BuiltinTools\OssUploadTool;
use LightAgent\BuiltinTools\PythonSourceChecker;
use LightAgent\BuiltinTools\SafeExpressionError;
use LightAgent\BuiltinTools\SafeExpressionTool;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the bounded built-in tools.
 *
 * V4-02: `safe_expression` must have hard limits — an unbounded
 *   expression exhausted the memory limit with an uncatchable fatal.
 * M-58: the repr() of a value must always be a string.
 * M-59: `and`/`or`, `//` and chained comparisons follow Python.
 * M-60: a nested subscript chain must not smuggle a builtin out of a
 *   builtin container.
 * M-6: the OSS endpoint/bucket/credentials are not model-controllable.
 */
final class BuiltinToolsSecurityTest extends TestCase
{
    public function test_huge_expression_is_rejected_before_allocating(): void
    {
        $expression = str_repeat('1+', 200_000) . '1';

        $this->expectException(SafeExpressionError::class);
        SafeExpressionTool::evaluate($expression);
    }

    public function test_expression_length_limit_is_reported_by_the_tool(): void
    {
        $result = SafeExpressionTool::safeExpression(str_repeat('1+', 200_000) . '1');

        self::assertStringStartsWith('安全表达式错误：', $result);
    }

    public function test_token_limit_is_enforced(): void
    {
        $expression = implode('+', array_fill(0, 5000, '1'));

        $this->expectException(SafeExpressionError::class);
        SafeExpressionTool::evaluate($expression);
    }

    public function test_repr_never_returns_false(): void
    {
        $result = SafeExpressionTool::safeExpression("\"\xB1\x31\"");

        self::assertIsString($result);
        self::assertNotSame('', $result);
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function pythonSemanticsProvider(): array
    {
        return [
            'and keeps the falsy left operand' => ['0 and ""', 0],
            'and keeps None' => ['None and 0', null],
            'and returns the last operand' => ['1 and 0', 0],
            'or keeps the truthy left operand' => ['1 or ""', 1],
            'or returns the right operand' => ['"" or 0', 0],
            'or returns None' => ['0 or None', null],
            'chained comparison is true' => ['1 < 2 < 3', true],
            'chained comparison is false' => ['3 < 2 < 1', false],
            'chained comparison with equality' => ['1 < 2 <= 2', true],
            'floor division' => ['7 // 2', 3],
            'floor division rounds down' => ['-7 // 2', -4],
            'floor division with a negative divisor' => ['7 // -2', -4],
        ];
    }

    /**
     * @param mixed $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pythonSemanticsProvider')]
    public function test_python_semantics(string $expression, mixed $expected): void
    {
        self::assertSame($expected, SafeExpressionTool::evaluate($expression));
    }

    public function test_nested_builtin_container_subscript_is_blocked(): void
    {
        $checker = new PythonSourceChecker();

        [$allowed, $reason] = $checker->check('g = globals(); g["__builtins__"]["eval"]("1+1")');

        self::assertFalse($allowed, 'the alias must not smuggle the builtin out: ' . $reason);
    }

    public function test_ordinary_dictionary_reads_stay_allowed(): void
    {
        $checker = new PythonSourceChecker();

        [$allowed, $reason] = $checker->check('d = {"eval": 1}; print(d["eval"])');

        self::assertTrue($allowed, $reason);
    }

    public function test_oss_tool_only_binds_the_declared_file_path(): void
    {
        $tool = OssUploadTool::create();
        $reflection = new \ReflectionFunction($tool->callable);
        $names = array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            $reflection->getParameters(),
        );

        self::assertSame(['file_path'], $names);
        self::assertCount(1, $tool->toolInfo['tool_params']);
    }

    public function test_oss_upload_rejects_an_oversized_file(): void
    {
        $file = sys_get_temp_dir() . '/la_oss_limit_' . bin2hex(random_bytes(4)) . '.bin';
        $handle = fopen($file, 'wb');
        self::assertIsResource($handle);
        fseek($handle, OssUploadTool::MAX_UPLOAD_BYTES + 10);
        fwrite($handle, 'x');
        fclose($handle);

        try {
            $result = OssUploadTool::uploadFileToOss($file);
            self::assertStringContainsString('上传大小限制', $result);
        } finally {
            @unlink($file);
        }
    }
}
