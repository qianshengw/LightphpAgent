<?php

declare(strict_types=1);

namespace LightAgent\Protocol;

/**
 * Promotion action constants (protocol.py module constants).
 */
final class MemoryPromotion
{
    public const APPROVE = 'approve';
    public const REJECT = 'reject';
    public const REWRITE = 'rewrite';
    public const KEEP = 'keep';

    private function __construct()
    {
    }
}
