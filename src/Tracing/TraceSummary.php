<?php

declare(strict_types=1);

namespace LightAgent\Tracing;

/**
 * Aggregated production metrics derived from structured trace events
 * (tracing.py TraceSummary).
 */
final class TraceSummary
{
    public ?float $durationMs = null;
    public float $modelLatencyMs = 0.0;
    public float $toolLatencyMs = 0.0;
    /** @var array<string, int> */
    public array $errorCategories = [];
    /** @var array<string, int> */
    public array $reviewCounts = [];
    /** @var array<string, int> */
    public array $usage = [];
    /**
     * True when `usage` was derived from the trace's own `model_request`
     * events.
     *
     * V5-34: tracing is disabled by default, so a trace can be EMPTY while
     * the agent still accumulated a token usage. Reporting that usage next
     * to `model_request_count = 0` presented it as if it came from model
     * requests that never happened. The flag makes the origin explicit.
     */
    public bool $usageFromTrace = false;
    public ?float $estimatedCostUsd = null;

    public function __construct(
        public int $eventCount = 0,
        public int $modelRequestCount = 0,
        public int $toolCallCount = 0,
        public int $toolSuccessCount = 0,
        public int $retryCount = 0,
        public int $errorCount = 0,
    ) {
    }

    public function toolSuccessRate(): ?float
    {
        if ($this->toolCallCount === 0) {
            return null;
        }
        return $this->toolSuccessCount / $this->toolCallCount;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'event_count' => $this->eventCount,
            'duration_ms' => $this->durationMs,
            'model_request_count' => $this->modelRequestCount,
            'model_latency_ms' => $this->modelLatencyMs,
            'tool_call_count' => $this->toolCallCount,
            'tool_success_count' => $this->toolSuccessCount,
            'tool_success_rate' => $this->toolSuccessRate(),
            'tool_latency_ms' => $this->toolLatencyMs,
            'retry_count' => $this->retryCount,
            'error_count' => $this->errorCount,
            'error_categories' => $this->errorCategories,
            'review_counts' => $this->reviewCounts,
            'usage' => $this->usage,
            'usage_from_trace' => $this->usageFromTrace,
            'estimated_cost_usd' => $this->estimatedCostUsd,
        ];
    }
}
