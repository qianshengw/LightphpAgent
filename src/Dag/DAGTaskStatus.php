<?php

declare(strict_types=1);

namespace LightAgent\Dag;

/**
 * Task-level status (dag/models.py DAGTaskStatus).
 */
enum DAGTaskStatus: string
{
    case READY = 'ready';
    case RUNNING = 'running';
    case VERIFYING = 'verifying';
    case WAITING_DEPENDENCIES = 'waiting_dependencies';
    case RETRY_WAIT = 'retry_wait';
    case WAITING_APPROVAL = 'waiting_approval';
    case BLOCKED = 'blocked';
    case VERIFIED = 'verified';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case SUPERSEDED = 'superseded';
}
