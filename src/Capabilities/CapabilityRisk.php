<?php

declare(strict_types=1);

namespace LightAgent\Capabilities;

/**
 * Risk tiers (capabilities.py CapabilityRisk).
 */
enum CapabilityRisk: string
{
    case READ_ONLY = 'L0';
    case ISOLATED_WRITE = 'L1';
    case SENSITIVE = 'L2';
    case DESTRUCTIVE = 'L3';

    public function order(): int
    {
        return match ($this) {
            self::READ_ONLY => 0,
            self::ISOLATED_WRITE => 1,
            self::SENSITIVE => 2,
            self::DESTRUCTIVE => 3,
        };
    }
}
