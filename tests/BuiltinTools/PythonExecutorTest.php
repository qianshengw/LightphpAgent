<?php

declare(strict_types=1);

namespace LightAgent\Tests\BuiltinTools;

use LightAgent\BuiltinTools\PythonCodeNestingError;
use LightAgent\BuiltinTools\PythonExecutorTool;
use LightAgent\BuiltinTools\SafeExpressionTool;
use LightAgent\Tools\ToolDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * PHP port of tests/test_python_executor_clean_code.py and
 * tests/test_python_executor_blocklist.py, plus dispatch-blocking semantics.
 *
 * @group requires-python
 */
final class PythonExecutorTest extends TestCase
{
    protected function setUp(): void
    {
        if (PythonExecutorTool::findPythonBinary() === null) {
            $this->markTestSkipped('host has no Python interpreter; AST checker unavailable');
        }
    }

    // ------------------------------------------------------------------
    // clean_code_string (test_python_executor_clean_code.py)
    // ------------------------------------------------------------------

    public function test_malformed_json_returns_input_unchanged(): void
    {
        $input = "def broken(:\n    pass";
        self::assertSame($input, PythonExecutorTool::cleanCodeString($input));
    }

    public function test_quoted_but_invalid_json_strips_quotes(): void
    {
        $input = '"print(1)" trailing';
        // Not valid JSON (trailing text) → wrapper quotes stripped positionally
        self::assertStringContainsString('print(1)', PythonExecutorTool::cleanCodeString($input));
    }

    public function test_plain_code_passes_through(): void
    {
        $code = "print('hello')\n";
        self::assertSame($code, PythonExecutorTool::cleanCodeString($code));
    }

    public function test_single_wrapped_code_field(): void
    {
        $wrapped = json_encode(['code' => "print('x')"]);
        self::assertSame("print('x')", PythonExecutorTool::cleanCodeString($wrapped));
    }

    public function test_object_nested_code_field(): void
    {
        // Python parity: {'code': {'code': ...}} unwraps in one parse pass
        $wrapped = json_encode(['code' => ['code' => 'print(2)']]);
        self::assertSame('print(2)', PythonExecutorTool::cleanCodeString($wrapped));
    }

    public function test_depth_limit_terminates_endless_chain(): void
    {
        // Build nested JSON that re-wraps "code" until the depth guard trips
        $payload = 'print("bottom")';
        for ($i = 0; $i < 20; $i++) {
            $payload = json_encode(['code' => $payload]);
        }
        // Must terminate (no infinite recursion) and return a string
        $result = PythonExecutorTool::cleanCodeString($payload);
        self::assertIsString($result);
    }

    public function test_at_limit_returns_input(): void
    {
        $input = 'not json at all';
        self::assertSame($input, PythonExecutorTool::cleanCodeString($input, PythonExecutorTool::MAX_CLEAN_DEPTH));
    }

    public function test_parse_code_parameter_nested_beyond_limit_throws(): void
    {
        // Build an array nested deeper than MAX_CLEAN_DEPTH
        $deep = 'print(1)';
        for ($i = 0; $i < 10; $i++) {
            $deep = ['code' => $deep];
        }
        $this->expectException(PythonCodeNestingError::class);
        PythonExecutorTool::parseCodeParameter($deep);
    }

    public function test_parse_code_parameter_string_passthrough(): void
    {
        self::assertSame('print(1)', PythonExecutorTool::parseCodeParameter('print(1)'));
    }

    public function test_parse_code_parameter_common_keys(): void
    {
        self::assertSame('A', PythonExecutorTool::parseCodeParameter(['script' => 'A']));
        self::assertSame('B', PythonExecutorTool::parseCodeParameter(['python_code' => 'B']));
        self::assertSame('C', PythonExecutorTool::parseCodeParameter(['source' => 'C']));
        self::assertSame('D', PythonExecutorTool::parseCodeParameter(['content' => 'D']));
        self::assertSame('E', PythonExecutorTool::parseCodeParameter(['program' => 'E']));
    }

    public function test_parse_code_parameter_single_value_dict(): void
    {
        self::assertSame('just one', PythonExecutorTool::parseCodeParameter(['anything' => 'just one']));
    }

    // ------------------------------------------------------------------
    // extract_code_from_text
    // ------------------------------------------------------------------

    public function test_extract_python_block(): void
    {
        $text = "intro\n```python\nprint(1)\nprint(2)\n```\noutro";
        self::assertSame("print(1)\nprint(2)", PythonExecutorTool::extractCodeFromText($text));
    }

    public function test_extract_generic_block(): void
    {
        $text = "```\nprint(9)\n```";
        self::assertSame('print(9)', PythonExecutorTool::extractCodeFromText($text));
    }

    public function test_extract_inline_code(): void
    {
        $text = 'run `print(7)` now';
        self::assertSame('print(7)', PythonExecutorTool::extractCodeFromText($text));
    }

    public function test_extract_plain_passthrough(): void
    {
        self::assertSame('print(3)', PythonExecutorTool::extractCodeFromText('print(3)'));
    }

    // ------------------------------------------------------------------
    // Blocklist via the real Python AST checker (test_python_executor_blocklist.py)
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function blockedProvider(): array
    {
        return [
            'direct eval' => ['eval("1+1")'],
            'direct exec' => ['exec("x=1")'],
            'direct compile' => ['compile("x", "f", "eval")'],
            'direct __import__' => ['__import__("os")'],
            'import os' => ["import os\nos.getcwd()"],
            'import subprocess' => ["import subprocess\nsubprocess.run(['ls'])"],
            'from builtins import eval' => ['from builtins import eval'],
            'attribute eval' => ["import builtins\nbuiltins.eval('1')"],
            'nested builtins eval' => ["import sys\nsys.modules['builtins'].eval('1')"],
            'getattr eval' => ["m = __import__('os')\ngetattr(m, 'system')"],
            'subscript eval' => ["d = {'eval': 1}\nd['eval']()"],
            'alias to os' => ["import os as o\no.system('x')"],
            'string-const dynamic attr' => ["import os\nattr = 'system'\nf = getattr(os, attr)"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blockedProvider')]
    public function test_blocks_dangerous_code(string $code): void
    {
        [$safe, $error] = PythonExecutorTool::safeImportCheck($code);
        self::assertFalse($safe, "expected block for: {$code}");
        self::assertNotSame('', $error);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function safeProvider(): array
    {
        return [
            'harmless computation' => ["x = 1 + 2\nprint(x)"],
            'print' => ["print('hello')"],
            'len and range' => ["print(len(range(10)))"],
            'getattr safe attribute' => ["class A:\n    pass\na = A()\nprint(getattr(a, '__class__') is not None)"],
            'dict subscript' => ["d = {'eval': 1}\nprint(d['eval'])"],
            'math import' => ["import math\nprint(math.pi)"],
            'json import' => ["import json\nprint(json.dumps({}))"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('safeProvider')]
    public function test_allows_safe_code(string $code): void
    {
        [$safe, $error] = PythonExecutorTool::safeImportCheck($code);
        self::assertTrue($safe, "unexpected block for: {$code} — {$error}");
        self::assertSame('', $error);
    }

    public function test_syntax_error_reported(): void
    {
        [$safe, $error] = PythonExecutorTool::safeImportCheck('def broken(:');
        self::assertFalse($safe);
        self::assertStringContainsString('语法错误', $error);
    }

    // ------------------------------------------------------------------
    // Execution end-to-end (host has Python)
    // ------------------------------------------------------------------

    public function test_execute_simple_code(): void
    {
        $result = PythonExecutorTool::executeCode("print('e2e-ok')");
        self::assertStringContainsString('e2e-ok', $result);
    }

    public function test_execute_blocked_code_returns_safety_error(): void
    {
        $result = PythonExecutorTool::executeCode("import os\nos.getcwd()");
        self::assertStringStartsWith('安全错误：', $result);
        self::assertStringContainsString('禁止导入危险模块', $result);
    }

    public function test_execute_empty_code_returns_error(): void
    {
        $result = PythonExecutorTool::executeCode('   ');
        self::assertSame('错误：没有找到可执行的代码', $result);
    }

    public function test_execute_file(): void
    {
        $file = sys_get_temp_dir() . '/la_exec_' . bin2hex(random_bytes(4)) . '.py';
        file_put_contents($file, "print('from-file')");
        try {
            $result = PythonExecutorTool::executeFile($file);
            self::assertStringContainsString('from-file', $result);
        } finally {
            @unlink($file);
        }
    }

    public function test_execute_file_missing(): void
    {
        self::assertStringStartsWith('错误：文件', PythonExecutorTool::executeFile('/nonexistent/no.py'));
    }

    public function test_execute_with_input_data_pipe_wiring(): void
    {
        // Python parity: `input`, `sys`, and `open` are all in DANGEROUS_* lists,
        // so sandboxed code cannot read stdin at all — that is the design. The
        // input_data pipe must still be wired without disturbing execution.
        $result = PythonExecutorTool::executeCode("print('stdin-ignored')", inputData: "ping\n");
        self::assertStringContainsString('stdin-ignored', $result);
        self::assertStringNotContainsString('ping', $result);
    }
    // ------------------------------------------------------------------
    // Dispatch-blocking semantics (UNSAFE_PYTHON_TOOL_NAMES)
    // ------------------------------------------------------------------

    public function test_dispatcher_blocks_legacy_python_tools(): void
    {
        $codeTool = PythonExecutorTool::createExecuteCode();
        $dispatcher = new ToolDispatcher(
            ['execute_python_code' => $codeTool],
            ['execute_python_code' => $codeTool->toolInfo],
        );

        $result = $dispatcher->dispatch('execute_python_code', ['code' => "print('nope')"]);
        self::assertStringStartsWith('[LA-SANDBOX]', (string) $result);
        self::assertStringContainsString('no isolated execution route', (string) $result);
    }

    public function test_isUnsafe_identifies_all_legacy_names(): void
    {
        self::assertTrue(PythonExecutorTool::isUnsafe('execute_python_code'));
        self::assertTrue(PythonExecutorTool::isUnsafe('execute_python_file'));
        self::assertTrue(PythonExecutorTool::isUnsafe('execute_python_code_stream'));
        self::assertFalse(PythonExecutorTool::isUnsafe('safe_expression'));
    }
}
