<?php

declare(strict_types=1);

namespace LightAgent\Dag;

/**
 * DAG storage/validation error (store.py DAGError).
 */
final class DAGError extends \RuntimeException
{
    public readonly string $errorCode;

    public function __construct(string $code, string $message)
    {
        $this->errorCode = str_starts_with($code, 'LA-DAG-') ? $code : "LA-DAG-{$code}";
        parent::__construct("[{$this->errorCode}] {$message}");
    }
}
