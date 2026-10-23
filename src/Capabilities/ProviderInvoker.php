<?php

declare(strict_types=1);

namespace LightAgent\Capabilities;

/**
 * Invokes a capability provider without letting an argument KEY collide
 * with the provider method's own parameter names.
 *
 * The provider contract is `invoke(string $capability, mixed ...$arguments)`
 * — the PHP analogue of the Python original's `invoke(capability,
 * **arguments)`. The argument map therefore has to reach the provider as a
 * MAP: providers read `$arguments['text']`, and flattening the map into
 * positional values would lose every key.
 *
 * PHP 8.1+ turns the string keys of a spread array into NAMED arguments.
 * A map that contains the key `capability` therefore collided with the
 * method's own first parameter and PHP raised a raw
 * `Error: Named parameter $capability overwrites previous argument` — a
 * crash instead of a clean capability error (V5-15).
 *
 * The fix keeps the map intact and demotes only the colliding keys:
 * they are handed over as positional values, which PHP delivers through
 * the variadic under numeric keys. The provider still receives every
 * argument; only the NAME of a colliding key is lost, and the call never
 * crashes.
 */
final class ProviderInvoker
{
    /** @var array<string, array{declared: array<string, true>, variadic: bool}> */
    private static array $signatures = [];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function invoke(object $provider, string $capability, array $arguments): mixed
    {
        if (!method_exists($provider, 'invoke')) {
            throw new \LogicException("provider `{$provider->name}` does not implement invoke()");
        }
        $signature = self::signature($provider::class);
        if (!$signature['variadic']) {
            // A provider that takes the map as a plain parameter (the
            // "non-variadic" shape) receives it positionally — there is no
            // named-argument collision to worry about in that case.
            return $provider->invoke($capability, $arguments);
        }

        $named = [];
        $demoted = [];
        foreach ($arguments as $key => $value) {
            if (is_string($key) && isset($signature['declared'][$key])) {
                $demoted[] = $value;
                continue;
            }
            $named[$key] = $value;
        }
        if ($demoted === []) {
            return $provider->invoke($capability, ...$named);
        }
        // Positional values first, named arguments afterwards: PHP rejects
        // a positional argument that follows a named one, not the other way
        // round.
        return $provider->invoke($capability, ...$demoted, ...$named);
    }

    /**
     * @return array{declared: array<string, true>, variadic: bool}
     */
    private static function signature(string $class): array
    {
        if (isset(self::$signatures[$class])) {
            return self::$signatures[$class];
        }
        $method = new \ReflectionMethod($class, 'invoke');
        $declared = [];
        $variadic = false;
        foreach ($method->getParameters() as $parameter) {
            if ($parameter->isVariadic()) {
                $variadic = true;
                break;
            }
            $declared[$parameter->getName()] = true;
        }
        return self::$signatures[$class] = ['declared' => $declared, 'variadic' => $variadic];
    }
}
