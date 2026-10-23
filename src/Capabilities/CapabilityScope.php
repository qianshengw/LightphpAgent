<?php

declare(strict_types=1);

namespace LightAgent\Capabilities;

/**
 * Provider scope tiers (capabilities.py CapabilityScope).
 */
enum CapabilityScope: string
{
    case DEFAULT = 'default';
    case RUNTIME = 'runtime';
    case SESSION = 'session';
    case AGENT = 'agent';
}
