<?php

declare(strict_types=1);

namespace LightAgent\Dag;

/**
 * Run-level status (dag/models.py DAGRunStatus).
 */
enum DAGRunStatus: string
{
    case RUNNING = 'running';
    case PAUSED = 'paused';
    case BLOCKED = 'blocked';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
}
