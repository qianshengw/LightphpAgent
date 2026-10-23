<?php

declare(strict_types=1);

namespace LightAgent\Dag;

/**
 * Task outcome kinds (dag/models.py TaskOutcomeKind).
 */
enum TaskOutcomeKind: string
{
    case CANDIDATE = 'candidate';
    case DECOMPOSITION = 'decomposition';
    case BLOCKED = 'blocked';
}
