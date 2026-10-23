<?php

declare(strict_types=1);

namespace LightAgent\Tracing;

/**
 * Trace aggregation and export helpers (port of tracing.py module functions).
 */
final class TraceUtils
{
    private function __construct()
    {
    }

    /**
     * Normalize OpenAI-compatible usage objects and dictionaries.
     *
     * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int}|array<never>
     */
    public static function normalizeUsage(mixed $usage): array
    {
        if ($usage === null) {
            return [];
        }
        if (is_object($usage) && method_exists($usage, 'toArray')) {
            $usage = $usage->toArray();
        } elseif (is_object($usage)) {
            $converted = [];
            foreach (['prompt_tokens', 'completion_tokens', 'total_tokens', 'input_tokens', 'output_tokens'] as $name) {
                $value = self::readProp($usage, $name);
                if ($value !== null) {
                    $converted[$name] = $value;
                }
            }
            $usage = $converted;
        } elseif (!is_array($usage)) {
            return [];
        }

        /** @var array<string, mixed> $usage */
        $prompt = $usage['prompt_tokens'] ?? ($usage['input_tokens'] ?? 0) ?? 0;
        $completion = $usage['completion_tokens'] ?? ($usage['output_tokens'] ?? 0) ?? 0;
        $total = $usage['total_tokens'] ?? null;
        if ($total === null) {
            $total = (is_numeric($prompt) ? (int) $prompt : 0) + (is_numeric($completion) ? (int) $completion : 0);
        }
        return [
            'prompt_tokens' => (int) ($prompt ?: 0),
            'completion_tokens' => (int) ($completion ?: 0),
            'total_tokens' => (int) ($total ?: 0),
        ];
    }

    /**
     * Create stable aggregate metrics without requiring an external backend.
     *
     * The usage of the traced model requests is the authoritative one: the
     * `model_request` events carry the latency and the token usage of the
     * request they describe (H-9). A caller may pass an additional usage;
     * it is only reported when the trace can account for it:
     *
     *  - the trace contains `model_request` events -> the trace's own usage
     *    wins, the caller's usage is the fallback;
     *  - the trace contains NO `model_request` event -> the trace cannot
     *    account for any usage. The caller's usage is reported only when
     *    it declared itself trace-scoped (`$usageIsTraceScoped`), otherwise
     *    the summary stays self-consistent and reports no usage at all
     *    (V5-34: tracing is disabled by default, so an empty trace must not
     *    be presented together with a non-zero token usage).
     *
     * @param list<array<string, mixed>> $events
     * @param array<string, float>|null $pricing
     * @param bool $usageIsTraceScoped whether `$usage` belongs to the traced
     *        events (e.g. the usage of the evaluated run) and may therefore
     *        be reported even when the trace holds no `model_request` event
     */
    public static function summarize(
        array $events,
        mixed $usage = null,
        ?array $pricing = null,
        bool $usageIsTraceScoped = false,
    ): TraceSummary {
        $summary = new TraceSummary(eventCount: count($events));
        $eventUsage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];

        foreach ($events as $event) {
            $eventType = (string) ($event['type'] ?? '');
            $data = is_array($event['data'] ?? null) ? $event['data'] : [];

            if ($eventType === 'model_request') {
                $summary->modelRequestCount++;
                // The latency of a model request is written into the event
                // itself (H-9): the recorder hands out the event OBJECT, so
                // the value lands on the stored event and not on a copy.
                $summary->modelLatencyMs += (float) ($data['latency_ms'] ?? 0);
                $currentUsage = self::normalizeUsage($data['usage'] ?? null);
                foreach ($eventUsage as $key => $val) {
                    $eventUsage[$key] += $currentUsage[$key] ?? 0;
                }
            } elseif ($eventType === 'tool_call') {
                $summary->toolCallCount++;
            }             elseif ($eventType === 'tool_result') {
                // Failures carry no explicit 'error' key (the message lives in
                // 'output' with an [LA-… prefix), so classify by output (M28):
                // toolSuccessRate must reflect real tool failures.
                $output = (string) ($data['output'] ?? '');
                if (empty($data['error']) && !str_starts_with($output, '[LA-')) {
                    $summary->toolSuccessCount++;
                }
                $summary->toolLatencyMs += (float) ($data['latency_ms'] ?? 0);
            } elseif ($eventType === 'error') {
                $summary->errorCount++;
                $category = (string) ($data['category'] ?? ($data['stage'] ?? 'unknown'));
                $summary->errorCategories[$category] = ($summary->errorCategories[$category] ?? 0) + 1;
                if (str_contains($category, 'retry')) {
                    $summary->retryCount++;
                }
            } elseif (str_starts_with($eventType, 'approval_') || $eventType === 'human_feedback') {
                $summary->reviewCounts[$eventType] = ($summary->reviewCounts[$eventType] ?? 0) + 1;
            } elseif ($eventType === 'run_end') {
                if (($data['duration_ms'] ?? null) !== null) {
                    $summary->durationMs = (float) $data['duration_ms'];
                }
                $summary->retryCount = max($summary->retryCount, (int) ($data['retry_count'] ?? 0));
            }
        }

        $summary->modelLatencyMs = round($summary->modelLatencyMs, 3);
        $summary->toolLatencyMs = round($summary->toolLatencyMs, 3);
        $summary->toolSuccessCount = min($summary->toolSuccessCount, $summary->toolCallCount);
        $normalizedUsage = self::normalizeUsage($usage);
        if ($summary->modelRequestCount > 0) {
            // The trace describes the requests itself: its own usage wins.
            $summary->usageFromTrace = self::hasTokens($eventUsage);
            $summary->usage = self::hasTokens($eventUsage)
                ? $eventUsage
                : ($normalizedUsage !== [] ? $normalizedUsage : $eventUsage);
        } elseif ($usageIsTraceScoped) {
            // The caller states that the usage belongs to these events.
            $summary->usage = $normalizedUsage !== [] ? $normalizedUsage : $eventUsage;
            $summary->usageFromTrace = false;
        } else {
            // No model request in the trace -> no usage can be attributed to
            // it (V5-34).
            $summary->usage = $eventUsage;
            $summary->usageFromTrace = false;
        }

        if ($pricing !== null) {
            $inputRate = (float) ($pricing['input_per_million'] ?? 0);
            $outputRate = (float) ($pricing['output_per_million'] ?? 0);
            $summary->estimatedCostUsd = round(
                (($summary->usage['prompt_tokens'] ?? 0) * $inputRate
                    + ($summary->usage['completion_tokens'] ?? 0) * $outputRate) / 1_000_000,
                8,
            );
        }
        return $summary;
    }

    /**
     * True when a normalized usage carries at least one token.
     *
     * @param array<string, int> $usage
     */
    private static function hasTokens(array $usage): bool
    {
        return ($usage['total_tokens'] ?? 0) > 0
            || ($usage['prompt_tokens'] ?? 0) > 0
            || ($usage['completion_tokens'] ?? 0) > 0;
    }

    /**
     * Export trace data through a callable or an object with export().
     *
     * @param list<array<string, mixed>> $events
     * @param array<string, float>|null $pricing
     * @param array<string, mixed>|null $metadata
     * @param bool $usageIsTraceScoped see `summarize()`
     */
    public static function export(
        array $events,
        callable|object $exporter,
        mixed $usage = null,
        ?array $pricing = null,
        ?array $metadata = null,
        bool $usageIsTraceScoped = false,
    ): mixed {
        $summary = self::summarize($events, usage: $usage, pricing: $pricing, usageIsTraceScoped: $usageIsTraceScoped);
        if (is_object($exporter) && method_exists($exporter, 'export')) {
            return $exporter->export($events, $summary, $metadata);
        }
        return $exporter($events, $summary, $metadata);
    }

    private static function readProp(object $obj, string $name): mixed
    {
        if (property_exists($obj, $name)) {
            $ref = new \ReflectionProperty($obj, $name);
            return $ref->isInitialized($obj) ? $ref->getValue($obj) : null;
        }
        $getter = 'get' . ucfirst($name);
        if (method_exists($obj, $getter)) {
            return $obj->$getter();
        }
        return null;
    }
}
