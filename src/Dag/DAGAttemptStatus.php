<?php

declare(strict_types=1);

namespace LightAgent\Dag;

/**
 * Attempt-level status (dag/models.py DAGAttemptStatus).
 */
enum DAGAttemptStatus: string
{
    case CLAIMED = 'claimed';
    case RUNNING = 'running';
    case SUBMITTED = 'submitted';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case EXPIRED = 'expired';
    case CANCELLED = 'cancelled';
}
