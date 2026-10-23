<?php

declare(strict_types=1);

namespace LightAgent\Dag;

/**
 * Verification assurance levels (dag/models.py AssuranceLevel).
 */
enum AssuranceLevel: string
{
    case FORMAL = 'formal';
    case EXECUTABLE_CHECKS = 'executable_checks';
    case HUMAN_REVIEW = 'human_review';
}
