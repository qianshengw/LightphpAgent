<?php

declare(strict_types=1);

namespace LightAgent\Async;

/**
 * Cooperative cancellation signal raised inside fibers when a
 * CancellationToken is observed cancelled (maps to asyncio.CancelledError).
 */
final class AsyncCancelledException extends \RuntimeException
{
}
