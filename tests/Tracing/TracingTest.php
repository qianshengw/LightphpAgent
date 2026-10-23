<?php

declare(strict_types=1);

namespace LightAgent\Tests\Tracing;

use LightAgent\Tracing\TraceRecorder;
use LightAgent\Tracing\TraceUtils;
use PHPUnit\Framework\TestCase;

/**
 * Tracing summary regressions (H-9 / V5-34).
 */
final class TracingTest extends TestCase
{
    /**
     * H-9: the latency and the usage of a model request are written into
     * the trace EVENT, so the summary derives them from the event data.
     */
    public function test_model_latency_and_usage_come_from_the_event(): void
    {
        $recorder = new TraceRecorder(enabled: true, traceId: 'trace-1');
        $event = $recorder->record('model_request', ['model' => 'gpt-test']);
        self::assertNotNull($event);
        $event->data['latency_ms'] = 12.5;
        $event->data['usage'] = ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15];

        $summary = $recorder->summarize();

        self::assertSame(1, $summary->modelRequestCount);
        self::assertSame(12.5, $summary->modelLatencyMs);
        self::assertSame(15, $summary->usage['total_tokens']);
        self::assertSame(10, $summary->usage['prompt_tokens']);
        self::assertTrue($summary->usageFromTrace);
    }

    /**
     * V5-34: a trace without model requests must not be presented together
     * with a token usage that cannot come from it.
     */
    public function test_trace_without_model_requests_reports_no_usage(): void
    {
        $summary = TraceUtils::summarize([], usage: ['prompt_tokens' => 10, 'total_tokens' => 15]);

        self::assertSame(0, $summary->modelRequestCount);
        self::assertSame(0, $summary->usage['total_tokens']);
        self::assertFalse($summary->usageFromTrace);
    }

    /**
     * V5-34: a usage that belongs to the traced events is reported even
     * when the trace holds no `model_request` event.
     */
    public function test_trace_scoped_usage_is_reported(): void
    {
        $summary = TraceUtils::summarize(
            [['type' => 'run_end', 'data' => ['success' => true]]],
            usage: ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            usageIsTraceScoped: true,
        );

        self::assertSame(15, $summary->usage['total_tokens']);
    }

    public function test_summary_dict_reports_the_usage_origin(): void
    {
        $dict = TraceUtils::summarize([])->toDict();

        self::assertArrayHasKey('usage_from_trace', $dict);
        self::assertFalse($dict['usage_from_trace']);
    }
}
