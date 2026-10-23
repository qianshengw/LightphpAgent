<?php

declare(strict_types=1);

namespace LightAgent\Tools;

use LightAgent\Errors\ErrorTaxonomy;
use LightAgent\Support\Json;

/**
 * Tool dispatcher (port of tools.py AsyncToolDispatcher).
 *
 * The Python original is async because tools may be coroutines; PHP tools
 * are plain callables, so dispatch is synchronous. Streaming generator
 * tools are represented by ToolFunction whose callable returns an
 * \Iterator — dispatched as-is for the caller to consume.
 */
final class ToolDispatcher
{
    /**
     * The dispatcher holds the REGISTRY, not a copy of its arrays (T1).
     *
     * PHP arrays are value types: `new ToolDispatcher($registry->functionMappings, ...)`
     * captured a snapshot of the registry at construction time, so any tool
     * registered afterwards was invisible to the dispatcher. The framework
     * worked around it by rebuilding the dispatcher at five call sites — and
     * still missed the built-in `safe_expression` (registered after the
     * dispatcher was built) and every tool registered straight on the public
     * registry.
     */
    private readonly ToolRegistry $registry;

    /**
     * @param ToolRegistry|array<string, ToolFunction> $registry the registry
     *        to dispatch from, or a legacy name -> ToolFunction map
     * @param array<string, array<string, mixed>> $functionInfo legacy
     *        tool_info map (only used with the array form)
     */
    public function __construct(ToolRegistry|array $registry = [], array $functionInfo = [])
    {
        $this->registry = $registry instanceof ToolRegistry
            ? $registry
            : ToolRegistry::fromSnapshot($registry, $functionInfo);
    }

    /**
     * Tool name -> ToolFunction (live view of the registry).
     *
     * @return array<string, ToolFunction>
     */
    public function functionMappings(): array
    {
        return $this->registry->functionMappings;
    }

    /**
     * Tool name -> tool_info (live view of the registry).
     *
     * @return array<string, array<string, mixed>>
     */
    public function functionInfo(): array
    {
        return $this->registry->functionInfo;
    }

    /**
     * Backwards compatibility for the former public readonly properties:
     * they are now LIVE views of the registry instead of stale snapshots.
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            'functionMappings' => $this->registry->functionMappings,
            'functionInfo' => $this->registry->functionInfo,
            default => throw new \OutOfRangeException("undefined property: {$name}"),
        };
    }

    public function __isset(string $name): bool
    {
        return $name === 'functionMappings' || $name === 'functionInfo';
    }

    /**
     * Call a tool by name with validated params.
     *
     * @param array<string, mixed> $toolParams
     */
    public function dispatch(string $toolName, array $toolParams): mixed
    {
        $mappings = $this->registry->functionMappings;
        if (!isset($mappings[$toolName])) {
            return ErrorTaxonomy::formatCode('LA-TOOL', "Tool `{$toolName}` not found.");
        }

        $toolCall = $mappings[$toolName];

        // Block the legacy Python execution entry points. The NAME check is a
        // heuristic; the IDENTITY check is the authoritative one (T2): the
        // Python original compares the function object itself, so a renamed
        // executor stays blocked there — with a name-only check a renamed
        // `execute_python_code` would execute Python on the host.
        if (
            ToolRegistry::isUnsafePythonTool($toolName)
            || ToolRegistry::isUnsafePythonCallable($toolCall->callable)
        ) {
            return ErrorTaxonomy::formatCode('LA-SANDBOX', ToolRegistry::unsafePythonToolReason());
        }

        $validationError = $this->validateToolParams($toolName, $toolParams);
        if ($validationError !== null) {
            return $validationError;
        }

        try {
            $result = $toolCall->invoke($toolParams);
            return $this->serializeResult($result);
        } catch (\Throwable $exc) {
            // M1: only the taxonomy-formatted message goes back to the
            // model. The PHP stack trace used to be appended here, which
            // leaked absolute source paths into the model context and
            // burned the token budget on every failing tool call. The
            // framework's own tracer/logger already records the call.
            return ErrorTaxonomy::formatException($exc, 'execute tool', 'LA-TOOL');
        }
    }

    /**
     * Serialize any result type to string (OpenAI requires string content).
     */
    private function serializeResult(mixed $result): string
    {
        if ($result === null) {
            return 'Tool executed successfully (no output)';
        }
        if (is_array($result)) {
            // An encoding failure must not become an empty tool result: the
            // model would silently see "no output" (T5b).
            return Json::encodeOrError($result, JSON_UNESCAPED_UNICODE);
        }
        if (is_object($result) && method_exists($result, '__toString')) {
            return (string) $result;
        }
        if (is_scalar($result)) {
            return (string) $result;
        }
        return print_r($result, true);
    }

    /**
     * Validate required tool parameters and basic JSON-schema-like types.
     *
     * @param array<string, mixed> $toolParams
     */
    private function validateToolParams(string $toolName, array $toolParams): ?string
    {
        $toolInfo = $this->registry->functionInfo[$toolName] ?? [];
        $params = $toolInfo['tool_params'] ?? [];
        if ($params === []) {
            return null;
        }

        foreach ($params as $param) {
            $name = $param['name'] ?? null;
            if ($name === null) {
                continue;
            }
            $required = (bool) ($param['required'] ?? false);
            if ($required && !array_key_exists($name, $toolParams)) {
                return ErrorTaxonomy::formatCode('LA-TOOL', "Tool `{$toolName}` missing required parameter `{$name}`.");
            }
            if (array_key_exists($name, $toolParams) && !self::matchesDeclaredType($toolParams[$name], $param['type'] ?? null)) {
                $expected = $param['type'] ?? 'any';
                $actual = get_debug_type($toolParams[$name]);
                return ErrorTaxonomy::formatCode('LA-TOOL', "Tool `{$toolName}` parameter `{$name}` expected `{$expected}`, got `{$actual}`.");
            }
        }
        return null;
    }

    private static function matchesDeclaredType(mixed $value, mixed $declaredType): bool
    {
        if ($declaredType === null) {
            return true;
        }
        $normalized = strtolower((string) $declaredType);

        if (in_array($normalized, ['string', 'str'], true)) {
            return is_string($value);
        }
        if (in_array($normalized, ['integer', 'int'], true)) {
            return is_int($value) && !is_bool($value);
        }
        if (in_array($normalized, ['number', 'float'], true)) {
            return (is_int($value) || is_float($value)) && !is_bool($value);
        }
        if (in_array($normalized, ['boolean', 'bool'], true)) {
            return is_bool($value);
        }
        if (in_array($normalized, ['array', 'list'], true)) {
            return is_array($value) && array_is_list($value);
        }
        if (in_array($normalized, ['object', 'dict'], true)) {
            // json_decode('{}', assoc) yields [] which IS a list — an empty
            // object must still validate as object (M5).
            return $value instanceof \stdClass
                || (is_array($value) && (!array_is_list($value) || $value === []));
        }
        return true;
    }
}
