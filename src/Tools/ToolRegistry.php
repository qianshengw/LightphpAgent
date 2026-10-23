<?php

declare(strict_types=1);

namespace LightAgent\Tools;

use LightAgent\Support\Json;
use LightAgent\Errors\ErrorTaxonomy;

/**
 * Centralized tool registry (port of tools.py ToolRegistry).
 *
 * Python tools are callables with an attached ``tool_info`` attribute;
 * PHP tools are instances of ToolFunction (value object bundling the
 * callable and its metadata).
 */
class ToolRegistry
{
    /** @var array<string, ToolFunction> tool name -> tool */
    public array $functionMappings = [];

    /** @var array<string, array<string, mixed>> tool name -> tool_info */
    public array $functionInfo = [];

    /** @var list<array<string, mixed>> OpenAI-format tool schemas */
    public array $openaiFunctionSchemas = [];

    /** @var array<string, int> */
    private array $registrationCounts = [];

    private const TOOL_NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';
    private const SUPPORTED_PARAM_TYPES = ['string', 'integer', 'number', 'boolean', 'array', 'object'];

    public function registerTool(ToolFunction $func): bool
    {
        $toolInfo = $func->toolInfo;
        $toolName = (string) ($toolInfo['tool_name'] ?? '');
        // Enforce the documented name grammar at registration (M4): invalid
        // names previously flowed into the provider schema untouched.
        if (preg_match(self::TOOL_NAME_PATTERN, $toolName) !== 1) {
            throw new \InvalidArgumentException(
                "tool_name `{$toolName}` must match [A-Za-z_][A-Za-z0-9_]*",
            );
        }

        // M4: the tool_params shape is validated BEFORE any state is
        // written. A malformed `tool_params` used to raise a TypeError
        // half-way through registration, leaving the tool in
        // functionMappings without a schema.
        self::assertValidToolParams($toolInfo, $toolName);

        $existing = $this->functionMappings[$toolName] ?? null;
        if (!isset($this->registrationCounts[$toolName])) {
            $this->registrationCounts[$toolName] = 1;
        } elseif ($existing !== $func) {
            $this->registrationCounts[$toolName]++;
        }

        $this->functionInfo[$toolName] = $toolInfo;
        $this->functionMappings[$toolName] = $func;

        $this->openaiFunctionSchemas = array_values(array_filter(
            $this->openaiFunctionSchemas,
            static fn (array $schema): bool => (($schema['function']['name'] ?? null) !== $toolName),
        ));
        $this->openaiFunctionSchemas[] = self::buildOpenaiSchema($toolInfo, $toolName);
        return true;
    }

    /**
     * Validate the `tool_params` shape of a tool_info definition.
     *
     * The checks are the ones validateToolInfo() already performs, so a
     * tool that passes here also passes the registry diagnostics.
     *
     * @param array<string, mixed> $toolInfo
     * @throws \InvalidArgumentException when the shape is invalid
     */
    private static function assertValidToolParams(array $toolInfo, string $toolName): void
    {
        // A tool without tool_params is valid: it simply takes no
        // arguments. validateToolInfo() would report the missing key as an
        // error, so it is normalized away first.
        $normalized = $toolInfo;
        if (!array_key_exists('tool_params', $normalized)) {
            $normalized['tool_params'] = [];
        }
        $errors = [];
        foreach (self::validateToolInfo($normalized) as $diagnostic) {
            if ($diagnostic['level'] === 'error' && $diagnostic['field'] !== 'tool_name') {
                $errors[] = $diagnostic['message'];
            }
        }
        if ($errors !== []) {
            throw new \InvalidArgumentException(
                "tool `{$toolName}` has an invalid tool_params definition: " . implode('; ', $errors),
            );
        }
    }

    /**
     * Build the OpenAI-format schema of one tool.
     *
     * @param array<string, mixed> $toolInfo
     * @return array<string, mixed>
     */
    private static function buildOpenaiSchema(array $toolInfo, string $toolName): array
    {
        $toolParamsOpenai = [];
        $toolRequired = [];
        foreach ($toolInfo['tool_params'] ?? [] as $param) {
            $paramDef = [];
            foreach ($param as $k => $v) {
                if (!in_array($k, ['name', 'required'], true)) {
                    $paramDef[$k] = $v;
                }
            }
            $toolParamsOpenai[$param['name']] = $paramDef;
            if (!empty($param['required'])) {
                $toolRequired[] = $param['name'];
            }
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => $toolName,
                'description' => $toolInfo['tool_description'] ?? '',
                'parameters' => [
                    'type' => 'object',
                    'properties' => $toolParamsOpenai,
                    'required' => $toolRequired,
                ],
            ],
        ];
    }

    /**
     * @param list<ToolFunction> $tools
     */
    public function registerTools(array $tools): bool
    {
        $success = true;
        foreach ($tools as $func) {
            if (!$this->registerTool($func)) {
                $success = false;
            }
        }
        return $success;
    }

    /**
     * Return structured diagnostics for one tool_info definition.
     *
     * @param mixed $toolInfo
     * @return list<array<string, string>>
     */
    public static function validateToolInfo(mixed $toolInfo): array
    {
        $diagnostics = [];

        if (!is_array($toolInfo)) {
            return [
                [
                    'tool' => '<unknown>',
                    'level' => 'error',
                    'field' => 'tool_info',
                    'message' => 'tool_info must be a dictionary',
                ],
            ];
        }

        $rawToolName = $toolInfo['tool_name'] ?? null;
        $toolName = is_string($rawToolName) && $rawToolName !== '' ? $rawToolName : '<unknown>';

        $add = static function (string $level, string $field, string $message) use (&$diagnostics, $toolName): void {
            $diagnostics[] = [
                'tool' => $toolName,
                'level' => $level,
                'field' => $field,
                'message' => $message,
            ];
        };

        if (!is_string($rawToolName) || trim($rawToolName) === '') {
            $add('error', 'tool_name', 'tool_name must be a non-empty string');
        } elseif (preg_match(self::TOOL_NAME_PATTERN, $rawToolName) !== 1) {
            $add('error', 'tool_name', 'tool_name must match [A-Za-z_][A-Za-z0-9_]*');
        }

        $description = $toolInfo['tool_description'] ?? null;
        if (!is_string($description) || trim($description) === '') {
            $add('warning', 'tool_description', 'tool_description should not be empty');
        }

        $params = $toolInfo['tool_params'] ?? null;
        if (!is_array($params)) {
            $add('error', 'tool_params', 'tool_params must be a list');
            return $diagnostics;
        }

        $paramNames = [];
        foreach (array_values($params) as $index => $param) {
            $fieldPrefix = "tool_params[{$index}]";
            if (!is_array($param)) {
                $add('error', $fieldPrefix, 'tool parameter must be a dictionary');
                continue;
            }

            $paramName = $param['name'] ?? null;
            if (!is_string($paramName) || trim($paramName) === '') {
                $add('error', "{$fieldPrefix}.name", 'parameter name must be a non-empty string');
            } elseif (in_array($paramName, $paramNames, true)) {
                $add('error', "{$fieldPrefix}.name", "duplicate parameter name `{$paramName}`");
            } else {
                $paramNames[] = $paramName;
            }

            $paramType = $param['type'] ?? null;
            if (!is_string($paramType) || !in_array($paramType, self::SUPPORTED_PARAM_TYPES, true)) {
                $supported = implode(', ', self::SUPPORTED_PARAM_TYPES);
                $add('error', "{$fieldPrefix}.type", "parameter type must be one of: {$supported}");
            }

            $paramDescription = $param['description'] ?? null;
            if (!is_string($paramDescription) || trim($paramDescription) === '') {
                $add('warning', "{$fieldPrefix}.description", 'parameter description should not be empty');
            }

            if (array_key_exists('required', $param) && !is_bool($param['required'])) {
                $add('error', "{$fieldPrefix}.required", 'required must be a boolean');
            }
        }

        return $diagnostics;
    }

    /**
     * Validate all registered tools without changing registration behavior.
     *
     * @return list<array<string, string>>
     */
    public function validateTools(): array
    {
        $diagnostics = [];
        foreach ($this->functionInfo as $toolName => $toolInfo) {
            foreach (self::validateToolInfo($toolInfo) as $d) {
                $diagnostics[] = $d;
            }
            $count = $this->registrationCounts[$toolName] ?? 0;
            if ($count > 1) {
                $diagnostics[] = [
                    'tool' => $toolName,
                    'level' => 'error',
                    'field' => 'tool_name',
                    'message' => "tool name `{$toolName}` was registered {$count} times",
                ];
            }
        }
        return $diagnostics;
    }

    /**
     * Get all tool descriptions (OpenAI format).
     *
     * @return list<array<string, mixed>>
     */
    public function getTools(): array
    {
        return Json::decode($this->openaiFunctionSchemas);
    }

    /**
     * Convert tool descriptions to a formatted JSON string.
     */
    public function getToolsStr(): string
    {
        return Json::encodeOrError($this->openaiFunctionSchemas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Filter tools by a tool-reflection JSON result.
     *
     * @return list<array<string, mixed>>
     */
    public function filterTools(string $toolReflectionResult): array
    {
        $refinedContent = trim($toolReflectionResult);
        if (str_starts_with($refinedContent, '```json') && str_ends_with($refinedContent, '```')) {
            $refinedContent = trim(substr($refinedContent, 7, -3));
        }

        $parsed = json_decode($refinedContent, true);
        if (!is_array($parsed)) {
            throw new \ValueError('工具过滤失败: invalid JSON');
        }

        $validTools = [];
        foreach ($parsed['tools'] ?? [] as $tool) {
            if (is_array($tool) && isset($tool['name']) && is_string($tool['name'])) {
                $validTools[] = strtolower(trim($tool['name']));
            }
        }

        return array_values(array_filter(
            $this->openaiFunctionSchemas,
            static function (array $schema) use ($validTools): bool {
                $name = strtolower(trim((string) ($schema['function']['name'] ?? '')));
                return $name !== '' && in_array($name, $validTools, true);
            },
        ));
    }

    /**
     * Build a registry from a raw name -> ToolFunction map.
     *
     * This is the escape hatch for callers that already hold a map. The
     * arrays are assigned as-is (no name validation), so the result is a
     * SNAPSHOT: it cannot see registrations that happen afterwards (T1).
     * Prefer handing the live registry to the consumer.
     *
     * M5: the snapshot used to skip the name grammar AND to leave
     * openaiFunctionSchemas empty — `getTools()` then returned no schema
     * at all while the tools were dispatchable. The names are validated
     * here and the OpenAI schemas are built exactly like registerTool()
     * builds them.
     *
     * @param array<string, ToolFunction> $functionMappings
     * @param array<string, array<string, mixed>> $functionInfo
     */
    public static function fromSnapshot(array $functionMappings, array $functionInfo = []): self
    {
        $registry = new self();
        $registry->functionInfo = $functionInfo;
        foreach ($functionMappings as $toolName => $tool) {
            if (preg_match(self::TOOL_NAME_PATTERN, (string) $toolName) !== 1) {
                throw new \InvalidArgumentException(
                    "tool_name `{$toolName}` must match [A-Za-z_][A-Za-z0-9_]*",
                );
            }
            $registry->functionMappings[$toolName] = $tool;
            $toolInfo = $functionInfo[$toolName] ?? $tool->toolInfo;
            $registry->openaiFunctionSchemas[] = self::buildOpenaiSchema($toolInfo, (string) $toolName);
        }
        return $registry;
    }

    /**
     * Port of tools.py is_unsafe_python_tool: legacy Python-execution tools
     * are blocked in dispatch.
     *
     * The name check is a heuristic; the AUTHORITATIVE check is
     * isUnsafePythonCallable(), which compares the callable's identity (T2).
     * The Python original compares the function object itself
     * (`inspect.unwrap(tool_call) in (execute_python_code, ...)`), so a
     * renamed tool stays blocked there — while a name-only check lets a
     * renamed Python executor through.
     */
    public static function isUnsafePythonTool(string $toolName): bool
    {
        // Normalized comparison (M3): case and surrounding-space variants of
        // the blocked names must not slip through dispatch.
        $normalized = strtolower(trim($toolName));
        return in_array($normalized, ['execute_python_code', 'execute_python_file', 'execute_python_code_stream'], true);
    }

    /**
     * True when the callable IS one of the legacy Python execution entry
     * points, whatever name it was registered under (T2).
     *
     * The three factories in `BuiltinTools/PythonExecutorTool.php` each build
     * a closure at a fixed source location, so the closure's file+line is a
     * stable identity — renaming the tool (or re-wrapping the same closure)
     * does not change it.
     *
     * M10: the identity check now lives in PythonExecutorTool itself, so
     * a one-line wrapper closure that calls the executor from ANOTHER
     * file is detected as well (definition site, captured executor or
     * executor mentioned in the closure source) — not only a closure
     * defined inside the executor file.
     */
    public static function isUnsafePythonCallable(mixed $callable): bool
    {
        if (!is_callable($callable)) {
            return false;
        }
        if (\LightAgent\BuiltinTools\PythonExecutorTool::isUnsafeCallable($callable)) {
            return true;
        }
        // Fallback for the identity string of a callable the executor
        // cannot reflect on (e.g. an invokable object of another class).
        $identity = self::callableIdentity($callable);
        return str_contains($identity, 'LightAgent\\BuiltinTools\\PythonExecutorTool')
            || preg_match('#(?:^|[/\\\\])PythonExecutorTool\.php#', $identity) === 1;
    }

    /**
     * A comparable identity for a callable.
     *
     * PHP has no "same function object" comparison the way Python's
     * `inspect.unwrap(f) is g` does, so the identity is derived from the
     * definition site: the file and the start line of the closure, or the
     * class::method pair for callable arrays.
     */
    public static function callableIdentity(mixed $callable): string
    {
        if ($callable instanceof \Closure) {
            try {
                $ref = new \ReflectionFunction($callable);
                return 'closure:' . ($ref->getFileName() ?? '?') . ':' . $ref->getStartLine();
            } catch (\ReflectionException) {
                return 'closure:unknown';
            }
        }
        if (is_array($callable) && count($callable) === 2) {
            $class = is_object($callable[0]) ? get_class($callable[0]) : (string) $callable[0];
            return 'callable:' . $class . '::' . (string) $callable[1];
        }
        if (is_string($callable)) {
            return 'function:' . $callable;
        }
        if (is_object($callable) && method_exists($callable, '__invoke')) {
            return 'invokable:' . get_class($callable);
        }
        return 'unknown';
    }

    public static function unsafePythonToolReason(): string
    {
        return 'Legacy Python execution tools have no isolated execution route and are '
            . 'blocked in tool dispatch. Registering a SandboxProvider does not isolate '
            . 'them. Use safe_expression or a custom tool backed by a real sandbox.';
    }
}
