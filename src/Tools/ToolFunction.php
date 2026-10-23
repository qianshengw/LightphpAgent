<?php

declare(strict_types=1);

namespace LightAgent\Tools;

/**
 * PHP equivalent of a Python tool function with an attached tool_info
 * attribute. Immutable value object bundling callable + metadata.
 */
final class ToolFunction
{
    /**
     * @param callable $callable fn(...$params): mixed
     * @param array<string, mixed> $toolInfo
     */
    public function __construct(
        public readonly mixed $callable,
        public readonly array $toolInfo,
    ) {
    }

    /**
     * Invoke the underlying callable with named-style params mapped to
     * positional/associative arguments. PHP callables using named args:
     * we pass an associative array via named arguments emulation.
     */
    public function invoke(array $params): mixed
    {
        $fn = $this->callable;

        if ($fn instanceof \Closure || is_string($fn) || (is_array($fn) && is_callable($fn))) {
            $args = self::mapArgs($fn, $params);
            return $fn(...$args);
        }

        throw new \TypeError('ToolFunction callable is not invocable');
    }

    /**
     * Map an associative params array onto the callable's signature.
     *
     * Binding order per parameter:
     *  1. exact name match (schema `city_name` vs callable `city_name`)
     *  2. snake_case -> camelCase fallback (schema `city_name` vs `cityName`),
     *     because PHP tool callables conventionally use camelCase while the
     *     OpenAI tool schema (and the Python original) use snake_case
     *  3. defaults / null / variadic absorption
     *
     * Two opt-in bindings keep wrapper callables working:
     *  - a lone array-typed parameter whose name matches no model key receives
     *    the whole params map (MCP wrappers, mirroring Python's **kwargs)
     *  - required params that matched nothing bind the unmatched model params
     *    positionally (schema order == signature order in tool definitions)
     *
     * @return list<mixed>
     */
    private static function mapArgs(callable $fn, array $params): array
    {
        // ReflectionFunction rejects array callables with a TypeError (not a
        // ReflectionException), so dispatch to ReflectionMethod first (M-audit
        // followup: the old catch(\ReflectionException) never fired).
        try {
            if (is_array($fn) && isset($fn[0], $fn[1]) && is_object($fn[0])) {
                $ref = new \ReflectionMethod($fn[0], (string) $fn[1]);
            } else {
                $ref = new \ReflectionFunction($fn);
            }
        } catch (\ReflectionException) {
            return array_values($params);
        }

        $parameters = $ref->getParameters();

        // Wrapper convention: a lone array-typed parameter (e.g.
        // fn (array $params = [])) that no model key matches by name binds the
        // entire named map — never degrade to a positional list, which would
        // raise TypeError inside the tool instead of reaching the server.
        // The no-match check covers exact, camelCase AND snake_case forms
        // (A7): fn (array $input_data) + {input_data: [...]} must bind the
        // value, not swallow the whole map.
        if ($params !== [] && count($parameters) === 1) {
            $only = $parameters[0];
            $paramType = $only->getType();
            $isArrayType = $paramType instanceof \ReflectionNamedType && $paramType->getName() === 'array';
            $onlyName = $only->getName();
            if ($isArrayType
                && !$only->isVariadic()
                && !array_key_exists($onlyName, $params)
                && !array_key_exists(self::toCamelCase($onlyName), $params)
                && !array_key_exists(self::toSnakeCase($onlyName), $params)) {
                return [$params];
            }
        }

        // Bind by DECLARED position (never $args[]), so sparse signatures with
        // defaults stay aligned when later params match and earlier ones fall
        // back to positional binding.
        $args = [];
        $matchedKeys = [];
        $positionals = [];
        foreach ($parameters as $idx => $parameter) {
            $name = $parameter->getName();
            $camelName = self::toCamelCase($name);
            $snakeName = self::toSnakeCase($name);
            if (array_key_exists($name, $params)) {
                $args[$idx] = $params[$name];
                $matchedKeys[$name] = true;
            } elseif ($camelName !== $name && array_key_exists($camelName, $params)) {
                $args[$idx] = $params[$camelName];
                $matchedKeys[$camelName] = true;
            } elseif ($snakeName !== $name && array_key_exists($snakeName, $params)) {
                // Param is camelCase, model key is snake_case (max_results).
                $args[$idx] = $params[$snakeName];
                $matchedKeys[$snakeName] = true;
            } elseif ($parameter->isVariadic()) {
                // Variadic consumes the remaining named params not already matched.
                $declaredNames = array_map(static fn ($p) => $p->getName(), $parameters);
                foreach ($params as $k => $v) {
                    if (!in_array($k, $declaredNames, true) && !in_array(self::toSnakeCase($k), $declaredNames, true)) {
                        $args[$idx] = $v;
                        $idx++;
                    }
                }
                break;
            } elseif ($parameter->isDefaultValueAvailable()) {
                // Inject the declared default so later positional slots stay
                // aligned — skipping would leave a hole and array_values()
                // would shift subsequent arguments left.
                $args[$idx] = $parameter->getDefaultValue();
            } elseif ($parameter->allowsNull()) {
                $args[$idx] = null;
            } else {
                // Required param missing — remember its slot for positional
                // fallback so a schema-name/param-name mismatch still binds.
                $positionals[$idx] = $parameter;
                continue;
            }
        }

        // Required params that matched nothing by name: fall back to binding
        // the unmatched model params positionally (schema order == signature
        // order in every tool definition), instead of a guaranteed TypeError.
        if ($positionals !== []) {
            $consumedKeys = [];
            foreach ($matchedKeys as $matchedKey => $_) {
                $consumedKeys[$matchedKey] = true;
                $consumedKeys[self::toSnakeCase($matchedKey)] = true;
                $consumedKeys[self::toCamelCase($matchedKey)] = true;
            }
            $unmatched = [];
            foreach ($params as $k => $v) {
                if (!isset($consumedKeys[$k])) {
                    $unmatched[$k] = $v;
                }
            }
            if (count($unmatched) === count($positionals)) {
                $bound = 0;
                foreach (array_keys($positionals) as $idx) {
                    $args[$idx] = array_values($unmatched)[$bound];
                    $bound++;
                }
            }
        }

        ksort($args);
        return array_values($args);
    }

    private static function toCamelCase(string $name): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $name))));
    }

    private static function toSnakeCase(string $name): string
    {
        return strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name));
    }
}
