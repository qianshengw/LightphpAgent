<?php

declare(strict_types=1);

namespace LightAgent\Flow;

/**
 * Workflow status constants (flow.py module constants).
 */
final class FlowConstants
{
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const SUCCESS = 'success';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';
    public const WAITING_APPROVAL = 'waiting_approval';

    private function __construct()
    {
    }
}
