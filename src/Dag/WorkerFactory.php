<?php

declare(strict_types=1);

namespace LightAgent\Dag;

/**
 * Worker protocols, factories, and strict LightAgent result adaptation
 * (port of dag/worker.py).
 *
 * PORTING-DIFF: Python awaitable workers map to plain PHP callables;
 * async generator/to_thread paths collapse to inline execution.
 */
final class DAGWorkerProtocolError extends \RuntimeException
{
}

