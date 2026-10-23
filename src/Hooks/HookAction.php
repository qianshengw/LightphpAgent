<?php

declare(strict_types=1);

namespace LightAgent\Hooks;

/**
 * Hook action constants (hooks.py module constants).
 */
final class HookAction
{
    public const CONTINUE = 'continue';
    public const REPLACE = 'replace';
    public const BLOCK = 'block';
    public const RETRY = 'retry';
    public const FALLBACK = 'fallback';
    public const METADATA = 'metadata';

    private function __construct()
    {
    }
}
