<?php

declare(strict_types=1);

namespace LightAgent\Dag;

/**
 * Verification verdict (dag/models.py VerificationVerdict).
 */
enum VerificationVerdict: string
{
    case PASS = 'pass';
    case FAIL = 'fail';
    case INCONCLUSIVE = 'inconclusive';
    case ERROR = 'error';
}
