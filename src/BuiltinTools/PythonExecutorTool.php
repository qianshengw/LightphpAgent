<?php

declare(strict_types=1);

namespace LightAgent\BuiltinTools;

use LightAgent\Support\Json;
use LightAgent\Tools\ToolFunction;

/**
 * Safe Python execution tool (port of builtin_tools/python_executor.py).
 *
 * 1:1 port of the orchestration pipeline: parse code parameter → clean
 * code string → extract code block → static import safety check (pure PHP:
 * PythonSourceChecker) → subprocess execution.
 *
 * SEMANTICS PRESERVED: these tools are always blocked in tool dispatch
 * (UNSAFE_PYTHON_TOOL_NAMES); registering them is a compatibility affordance
 * for direct application use only.
 */
final class PythonExecutorTool
{
    public const MAX_CLEAN_DEPTH = 5;

    public const UNSAFE_NAMES = ['execute_python_code', 'execute_python_file', 'execute_python_code_stream'];

    public const UNSAFE_REASON =
        'Legacy Python execution tools have no isolated execution route and are '
        . 'blocked in tool dispatch. Registering a SandboxProvider does not isolate '
        . 'them. Use safe_expression or a custom tool backed by a real sandbox.';

    /** @var list<string> */
    public const DANGEROUS_MODULES = [
        '__builtins__', 'ctypes', 'glob', 'importlib', 'os', 'pickle', 'pty',
        'shelve', 'shutil', 'socket', 'subprocess', 'sys',
    ];

    /** @var list<string> */
    public const DANGEROUS_BUILTINS = ['__import__', 'compile', 'eval', 'exec', 'input', 'open', 'raw_input'];

    /** @var list<string> */
    public const DANGEROUS_ATTRIBUTES = ['compile', 'eval', 'exec', 'popen', 'system'];

    /** @var list<string> */
    public const PROCESS_ATTRIBUTES = ['Popen', 'call', 'check_call', 'check_output', 'run'];

    private function __construct()
    {
    }

    /**
     * Whether a tool name is a blocked legacy Python execution tool
     * (python_executor.py is_unsafe_python_tool).
     */
    public static function isUnsafe(string $toolName): bool
    {
        return in_array($toolName, self::UNSAFE_NAMES, true);
    }

    /**
     * True when the callable would run Python on the host.
     *
     * The dispatch layer blocks the legacy Python executors. A NAME check
     * alone is not enough: the same executor can be registered under
     * another name, or wrapped in a one-line closure that lives in a
     * different file. This is the authoritative identity check (M10):
     *
     *  1. the callable is the executor itself (a method of this class,
     *     an instance of it, or a closure defined in this file);
     *  2. the closure CAPTURES the executor (directly, inside an
     *     array, or behind another closure);
     *  3. the closure SOURCE mentions the executor — a wrapper written
     *     as `fn ($code) => PythonExecutorTool::executeCode($code)`
     *     mentions the class even though it captures nothing.
     *
     * RESIDUAL LIMITATION: a wrapper that reaches the executor through
     * an indirection the reflection cannot see (a variable imported
     * under a different alias, a class name built at runtime, or a
     * callable handed over as a string) is not detected. The check is a
     * strong heuristic, not a proof — but the executor remains blocked
     * for every direct and one-line-wrapper form.
     */
    public static function isUnsafeCallable(mixed $callable): bool
    {
        if (!is_callable($callable)) {
            return false;
        }

        if (is_array($callable) && count($callable) === 2) {
            $class = is_object($callable[0]) ? get_class($callable[0]) : (string) $callable[0];
            if ($class === self::class) {
                return true;
            }
            // A static method of this class referenced as a string.
            if (is_string($callable[0]) && strcasecmp($callable[0], self::class) === 0) {
                return true;
            }
        }

        if (is_object($callable) && $callable instanceof self) {
            return true;
        }

        if (is_string($callable)) {
            // A plain function name: only the executor's own functions
            // are unsafe, and they are not callable by name.
            return false;
        }

        if (!$callable instanceof \Closure) {
            return false;
        }

        try {
            $ref = new \ReflectionFunction($callable);
        } catch (\ReflectionException) {
            return false;
        }

        // (1) the closure was defined inside this file.
        $file = $ref->getFileName();
        if (is_string($file) && basename($file) === 'PythonExecutorTool.php') {
            return true;
        }

        // (1b) the closure is bound to an instance/static scope of this
        // class.
        $scope = $ref->getClosureScopeClass();
        if ($scope instanceof \ReflectionClass && $scope->getName() === self::class) {
            return true;
        }

        // (2) the closure captures the executor (or a closure that does).
        foreach ($ref->getStaticVariables() as $captured) {
            if (self::mentionsExecutor($captured)) {
                return true;
            }
        }

        // (3) the closure source mentions the executor class.
        if (self::sourceMentionsExecutor($ref)) {
            return true;
        }

        return false;
    }

    /**
     * True when a captured value is (or leads to) the Python executor.
     */
    private static function mentionsExecutor(mixed $value): bool
    {
        if ($value instanceof self) {
            return true;
        }
        if ($value instanceof \Closure) {
            return self::isUnsafeCallable($value);
        }
        if ($value instanceof ToolFunction) {
            return self::isUnsafeCallable($value->callable);
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::mentionsExecutor($item)) {
                    return true;
                }
            }
            return false;
        }
        if (is_object($value)) {
            return $value::class === self::class;
        }
        if (is_string($value)) {
            return str_contains($value, self::class);
        }
        return false;
    }

    /**
     * True when the source line(s) of a closure mention the executor.
     *
     * A one-line wrapper such as
     * `fn (string $code) => PythonExecutorTool::executeCode($code)`
     * lives in another file and captures nothing, so neither the
     * definition site nor the captured variables give it away — its
     * SOURCE does.
     */
    private static function sourceMentionsExecutor(\ReflectionFunction $ref): bool
    {
        $file = $ref->getFileName();
        if (!is_string($file) || !is_file($file)) {
            return false;
        }
        $start = $ref->getStartLine();
        $end = $ref->getEndLine();
        if ($start < 1) {
            return false;
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return false;
        }
        $slice = array_slice($lines, $start - 1, max(1, $end - $start + 1));
        $source = implode("\n", $slice);
        unset($lines);
        return str_contains($source, 'PythonExecutorTool');
    }

    // ------------------------------------------------------------------
    // Tool registrations (blocked in dispatch; usable directly by apps)
    // ------------------------------------------------------------------

    public static function createExecuteCode(): ToolFunction
    {
        return new ToolFunction(
            callable: static fn (string $code, int $timeout = 30, ?array $requirements = null, ?string $inputData = null): string => self::executeCode($code, $timeout, $requirements, $inputData),
            toolInfo: [
                'tool_name' => 'execute_python_code',
                'tool_title' => '执行Python代码',
                'tool_description' => '在隔离环境中安全执行Python代码，自动处理各种输入格式，支持依赖安装和输入数据传递',
                'tool_params' => [
                    ['name' => 'code', 'description' => '要执行的Python代码（可以是字符串、字典或列表，工具会自动解析）', 'type' => 'string', 'required' => true],
                    ['name' => 'timeout', 'description' => '执行超时时间（秒），默认30秒', 'type' => 'integer', 'required' => false],
                    ['name' => 'requirements', 'description' => "需要安装的依赖包列表，例如 ['requests', 'numpy==1.21.0']", 'type' => 'array', 'required' => false],
                    ['name' => 'input_data', 'description' => '传递给代码的标准输入数据', 'type' => 'string', 'required' => false],
                ],
            ],
        );
    }

    public static function createExecuteFile(): ToolFunction
    {
        return new ToolFunction(
            callable: static fn (string $filePath, int $timeout = 30, ?array $requirements = null, ?array $args = null): string => self::executeFile($filePath, $timeout, $requirements, $args),
            toolInfo: [
                'tool_name' => 'execute_python_file',
                'tool_title' => '执行Python文件',
                'tool_description' => '在隔离环境中执行指定的Python文件，支持依赖安装和命令行参数',
                'tool_params' => [
                    ['name' => 'file_path', 'description' => 'Python文件的路径', 'type' => 'string', 'required' => true],
                    ['name' => 'timeout', 'description' => '执行超时时间（秒），默认30秒', 'type' => 'integer', 'required' => false],
                    ['name' => 'requirements', 'description' => '需要安装的依赖包列表', 'type' => 'array', 'required' => false],
                    ['name' => 'args', 'description' => '传递给脚本的命令行参数列表', 'type' => 'array', 'required' => false],
                ],
            ],
        );
    }

    public static function createExecuteCodeStream(): ToolFunction
    {
        return new ToolFunction(
            callable: static fn (string $code, int $timeout = 30, ?array $requirements = null, int $chunkSize = 100): string => self::executeCodeStream($code, $timeout, $requirements, $chunkSize),
            toolInfo: [
                'tool_name' => 'execute_python_code_stream',
                'tool_title' => '流式执行Python代码',
                'tool_description' => '流式执行Python代码，逐步返回执行结果（用于长时间运行的任务）',
                'tool_params' => [
                    ['name' => 'code', 'description' => '要执行的Python代码', 'type' => 'string', 'required' => true],
                    ['name' => 'timeout', 'description' => '执行超时时间（秒），默认30秒', 'type' => 'integer', 'required' => false],
                    ['name' => 'requirements', 'description' => "需要安装的依赖包列表，示例：['openpyxl']", 'type' => 'array', 'required' => false],
                ],
            ],
        );
    }

    // ------------------------------------------------------------------
    // Public entry points
    // ------------------------------------------------------------------

    public static function executeCode(
        string|array $code,
        int $timeout = 30,
        ?array $requirements = null,
        ?string $inputData = null,
    ): string {
        $rawCode = self::parseCodeParameter($code);
        $cleaned = self::cleanCodeString($rawCode);
        $finalCode = self::extractCodeFromText($cleaned);

        if (trim($finalCode) === '') {
            return '错误：没有找到可执行的代码';
        }

        [$isSafe, $errorMsg] = self::safeImportCheck($finalCode);
        if (!$isSafe) {
            return "安全错误：{$errorMsg}";
        }

        $tmpdir = sys_get_temp_dir() . '/lightagent_php_' . bin2hex(random_bytes(8));
        if (!is_dir($tmpdir)) {
            mkdir($tmpdir, 0777, true);
        }
        try {
            $scriptPath = $tmpdir . DIRECTORY_SEPARATOR . 'script.py';
            file_put_contents($scriptPath, $finalCode);

            $pythonPath = self::resolvePythonPath($requirements, $tmpdir);
            if (is_string($pythonPath) && str_starts_with($pythonPath, '依赖')) {
                return $pythonPath; // dependency install failure message
            }

            $cmd = [$pythonPath, $scriptPath];
            $useStdinPipe = $inputData !== null;
            $descriptors = [
                0 => $useStdinPipe ? ['pipe', 'r'] : ['file', 'NUL', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open($cmd, $descriptors, $pipes, $tmpdir);
            if (!is_resource($proc)) {
                return '执行异常：proc_open failed';
            }
            if ($useStdinPipe) {
                fwrite($pipes[0], $inputData);
                fclose($pipes[0]);
            }
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $stdout = '';
            $stderr = '';
            $deadline = microtime(true) + $timeout;
            while (true) {
                $stdout .= (string) fread($pipes[1], 65536);
                $stderr .= (string) fread($pipes[2], 65536);
                $status = proc_get_status($proc);
                if (!$status['running'] || microtime(true) > $deadline) {
                    if ($status['running']) {
                        proc_terminate($proc);
                        $status['running'] = false;
                    }
                    // Drain remaining output
                    $stdout .= (string) stream_get_contents($pipes[1]);
                    $stderr .= (string) stream_get_contents($pipes[2]);
                    break;
                }
                usleep(10000);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);

            if (microtime(true) > $deadline && $exitCode !== 0 && $status['running'] === false && $exitCode === 0 && $stdout === '' && $stderr === '') {
                return "执行超时（超过{$timeout}秒）";
            }

            $outputParts = [];
            if ($stdout !== '') {
                $outputParts[] = $stdout;
            }
            if ($stderr !== '') {
                $outputParts[] = $stderr;
            }
            if ($exitCode !== 0) {
                $outputParts[] = "【退出代码】{$exitCode}";
            }
            return $outputParts === [] ? '【执行完成，无输出】' : implode("\n", $outputParts);
        } catch (\Throwable $e) {
            return '执行异常：' . $e->getMessage();
        } finally {
            self::rrmdir($tmpdir);
        }
    }

    public static function executeFile(
        string $filePath,
        int $timeout = 30,
        ?array $requirements = null,
        ?array $args = null,
    ): string {
        if (!is_file($filePath)) {
            return "错误：文件 '{$filePath}' 不存在";
        }
        if (!str_ends_with($filePath, '.py')) {
            return "错误：'{$filePath}' 不是Python文件";
        }
        $code = (string) file_get_contents($filePath);
        if ($args !== null && $args !== []) {
            $argv = json_encode(array_merge([$filePath], array_map('strval', $args)), JSON_UNESCAPED_SLASHES);
            $code = "import sys\nsys.argv = {$argv}\n" . $code;
        }
        return self::executeCode($code, $timeout, $requirements);
    }

    public static function executeCodeStream(
        string|array $code,
        int $timeout = 30,
        ?array $requirements = null,
        int $chunkSize = 100,
    ): string {
        // Streaming variant collapses to buffered execution in the PHP port
        // (dispatch-level streaming of subprocess output is handled by the
        // caller); result text is identical to execute_code.
        return self::executeCode($code, $timeout, $requirements);
    }

    // ------------------------------------------------------------------
    // Code parameter parsing (python_executor.py _parse_code_parameter)
    // ------------------------------------------------------------------

    /**
     * @param mixed $codeParam
     */
    public static function parseCodeParameter(mixed $codeParam, int $depth = 0): string
    {
        if (is_string($codeParam)) {
            return $codeParam;
        }
        if (is_array($codeParam)) {
            if ($depth >= self::MAX_CLEAN_DEPTH) {
                throw PythonCodeNestingError::withMessage('Code parameter nesting exceeds the cleanup limit');
            }
            foreach (['code', 'script', 'python_code', 'source', 'content', 'program'] as $key) {
                if (array_key_exists($key, $codeParam)) {
                    $value = $codeParam[$key];
                    if (is_string($value)) {
                        return $value;
                    }
                    if (is_array($value)) {
                        return self::parseCodeParameter($value, $depth + 1);
                    }
                }
            }
            if (count($codeParam) === 1) {
                $value = reset($codeParam);
                if (is_string($value)) {
                    return $value;
                }
            }
            $json = json_encode($codeParam, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                throw PythonCodeNestingError::withMessage('Code parameter is too deeply nested or cyclic');
            }
            return $json;
        }
        if (is_object($codeParam) && method_exists($codeParam, '__toString')) {
            return (string) $codeParam;
        }
        return Json::encodeOrError($codeParam, JSON_UNESCAPED_UNICODE);
    }

    // ------------------------------------------------------------------
    // Code string cleaning (python_executor.py _clean_code_string)
    // ------------------------------------------------------------------

    public static function cleanCodeString(mixed $codeStr, int $depth = 0): string
    {
        if ($depth >= self::MAX_CLEAN_DEPTH) {
            return is_string($codeStr) ? $codeStr : Json::encodeOrError($codeStr, JSON_UNESCAPED_UNICODE);
        }
        if (!is_string($codeStr)) {
            $codeStr = Json::encodeOrError($codeStr, JSON_UNESCAPED_UNICODE);
        }

        // Step 1: common JSON escape fixes
        $codeStr = str_replace(['\\n', '\\t', '\\r'], ["\n", "\t", "\r"], $codeStr);
        // Step 2: quote escapes
        $codeStr = str_replace(['\\"', "\\'"], ['"', "'"], $codeStr);
        // Step 3: double escapes
        $codeStr = str_replace('\\\\', '\\', $codeStr);
        // Step 4: f-string double braces from JSON escaping
        $codeStr = (string) preg_replace('/\{(\{[^}]*\})\}/', '$1', $codeStr);

        // Step 5: strip JSON string wrapper
        if (str_starts_with($codeStr, '"') && str_ends_with($codeStr, '"')) {
            $unwrapped = json_decode($codeStr, true);
            if (is_string($unwrapped)) {
                $codeStr = $unwrapped;
            } else {
                $codeStr = substr($codeStr, 1, -1);
            }
        }

        // Step 6: try JSON parse and extract code fields
        $parsed = json_decode($codeStr, true);
        if (is_array($parsed)) {
            try {
                $code = self::parseCodeParameter($parsed, $depth);
            } catch (PythonCodeNestingError) {
                return $codeStr;
            }
            if ($code !== $codeStr) {
                return self::cleanCodeString($code, $depth + 1);
            }
        } elseif (is_string($parsed)) {
            return self::cleanCodeString($parsed, $depth + 1);
        }

        return $codeStr;
    }

    // ------------------------------------------------------------------
    // Code block extraction (python_executor.py _extract_code_from_text)
    // ------------------------------------------------------------------

    public static function extractCodeFromText(string $text): string
    {
        if (preg_match_all('/```python\s*\n(.*?)\n```/s', $text, $m) && $m[1] !== []) {
            return implode("\n", $m[1]);
        }
        if (preg_match_all('/```\s*\n(.*?)\n```/s', $text, $m) && $m[1] !== []) {
            return implode("\n", $m[1]);
        }
        if (preg_match_all('/`([^`]+)`/', $text, $m) && $m[1] !== []) {
            return implode("\n", $m[1]);
        }
        return $text;
    }

    // ------------------------------------------------------------------
    // Static import safety check — pure-PHP tokenizer + blocklist rules
    // ported 1:1 from python_executor.py _safe_import_check. No host
    // Python is required for the check step.
    // ------------------------------------------------------------------

    /**
     * @return array{0: bool, 1: string}
     */
    public static function safeImportCheck(string $code): array
    {
        return PythonSourceChecker::check($code);
    }

    /**
     * @return string|null python binary path, or null when unavailable
     */
    public static function findPythonBinary(): ?string
    {
        static $cached = false;
        static $resolved = null;
        if ($cached) {
            return $resolved;
        }
        $cached = true;

        foreach (['python', 'python3', 'py'] as $name) {
            $test = PHP_OS_FAMILY === 'Windows'
                ? 'where ' . escapeshellarg($name) . ' 2>nul'
                : 'command -v ' . escapeshellarg($name) . ' 2>/dev/null';
            $out = shell_exec($test);
            if (!is_string($out) || trim($out) === '') {
                continue;
            }
            foreach (preg_split('/\r?\n/', trim($out)) ?: [] as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '' || !is_file($candidate)) {
                    continue;
                }
                // Verify the binary actually executes (WindowsApps stubs fail this)
                $probe = proc_open(
                    [$candidate, '-c', 'print(1)'],
                    [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['file', 'NUL', 'w']],
                    $pipes,
                    null,
                    null,
                    ['bypass_shell' => true],
                );
                if (!is_resource($probe)) {
                    continue;
                }
                $output = trim((string) stream_get_contents($pipes[1]));
                fclose($pipes[1]);
                proc_close($probe);
                if ($output === '1') {
                    $resolved = $candidate;
                    return $resolved;
                }
            }
        }
        return $resolved;
    }

    /**
     * Install requirements into a venv when requested (1:1 with Python flow).
     *
     * @return string resolved python binary path, or an error string starting with 依赖
     */
    private static function resolvePythonPath(?array $requirements, string $tmpdir): string
    {
        $base = self::findPythonBinary();
        if ($base === null) {
            return '执行异常：宿主机未找到 Python 解释器';
        }
        if ($requirements === null || $requirements === []) {
            return $base;
        }
        $venv = $tmpdir . DIRECTORY_SEPARATOR . 'venv';
        $create = self::runProcess([$base, '-m', 'venv', $venv], $tmpdir, 60);
        if ($create['exit'] !== 0) {
            return "创建虚拟环境失败：{$create['stderr']}";
        }
        $sep = PHP_OS_FAMILY === 'Windows' ? 'Scripts' : 'bin';
        $pythonPath = $venv . DIRECTORY_SEPARATOR . $sep . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'python.exe' : 'python');
        $pipPath = $venv . DIRECTORY_SEPARATOR . $sep . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'pip.exe' : 'pip');
        foreach ($requirements as $req) {
            $result = self::runProcess([$pythonPath, '-m', 'pip', 'install', (string) $req], $tmpdir, 120);
            if ($result['exit'] !== 0) {
                return "安装 {$req} 失败：{$result['stderr']}";
            }
        }
        return $pythonPath;
    }

    /**
     * @param list<string> $cmd
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private static function runProcess(array $cmd, string $cwd, int $timeout): array
    {
        $descriptors = [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes, $cwd);
        if (!is_resource($proc)) {
            return ['exit' => -1, 'stdout' => '', 'stderr' => 'proc_open failed'];
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeout;
        while (true) {
            $stdout .= (string) fread($pipes[1], 65536);
            $stderr .= (string) fread($pipes[2], 65536);
            $status = proc_get_status($proc);
            if (!$status['running'] || microtime(true) > $deadline) {
                if ($status['running']) {
                    proc_terminate($proc);
                }
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                break;
            }
            usleep(10000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}

/**
 * Nesting error (python_executor.py _CodeNestingError).
 */
final class PythonCodeNestingError extends \InvalidArgumentException
{
    public static function withMessage(string $message): self
    {
        return new self($message);
    }
}
