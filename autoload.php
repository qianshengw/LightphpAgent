<?php

declare(strict_types=1);

/**
 * LightAgent PHP — manual PSR-4 autoloader (no Composer).
 *
 * Layout:
 *   LightAgent\Tests\        -> ./tests/
 *   LightAgent\              -> ./src/
 *   Amp\Sync\                -> ./coroutine/sync/
 *   Symfony\Component\Yaml\  -> ./coroutine/yaml-6.4.48/
 *   Revolt\                  -> ./coroutine/revolt/
 *   Amp\                     -> ./coroutine/amphp/
 *
 * Prefix order matters: Amp\Sync\ must be matched before Amp\.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

spl_autoload_register(static function (string $class): void {
    static $prefixes = null;

    // Longest/most-specific prefixes first.
    $prefixes ??= [
        'LightAgent\\Tests\\' => __DIR__ . '/tests/',
        'LightAgent\\' => __DIR__ . '/src/',
        'Amp\\Sync\\' => __DIR__ . '/coroutine/sync/',
        'Symfony\\Component\\Yaml\\' => __DIR__ . '/coroutine/yaml-6.4.48/',
        'Revolt\\' => __DIR__ . '/coroutine/revolt/',
        'Amp\\' => __DIR__ . '/coroutine/amphp/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (str_starts_with($class, $prefix)) {
            $relative = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                // require_once: a re-triggered autoloader (e.g. after
                // spl_autoload_call in tests) must not redeclare classes.
                require_once $file;
            }
            return;
        }
    }
});

// Namespaced global functions are not autoloadable — require explicitly.
require_once __DIR__ . '/coroutine/amphp/functions.php';
require_once __DIR__ . '/coroutine/amphp/Future/functions.php';
require_once __DIR__ . '/coroutine/amphp/Internal/functions.php';
require_once __DIR__ . '/coroutine/sync/functions.php';
// T4: `src/Skills/create_skill_tools.php` declares a namespaced FUNCTION,
// which PSR-4 cannot load. Without this require the `auto_discover_skills`
// path dies with "Call to undefined function LightAgent\Skills\create_skill_tools()".
require_once __DIR__ . '/src/Skills/create_skill_tools.php';
