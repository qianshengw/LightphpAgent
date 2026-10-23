<?php

declare(strict_types=1);

/**
 * Composer-path bootstrap for the vendored coroutine libraries.
 *
 * The Amp / Amp\Sync / Revolt / Symfony-Yaml packages are vendored under
 * `coroutine/` with their upstream directory layout, but they are not
 * published as Composer packages, so Composer cannot discover them through
 * PSR-4. This file registers the same prefix map that the manual
 * `autoload.php` uses, so the Composer install path resolves exactly the
 * same classes as the zero-dependency path.
 *
 * Prefix order matters: `Amp\Sync\` must be matched before `Amp\`.
 *
 * Kept in sync with `autoload.php`.
 */

spl_autoload_register(static function (string $class): void {
    static $prefixes = null;

    // Longest/most-specific prefixes first.
    $prefixes ??= [
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
                require_once $file;
            }
            return;
        }
    }
});
