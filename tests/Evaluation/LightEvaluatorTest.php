<?php

declare(strict_types=1);

namespace LightAgent\Tests\Evaluation;

use LightAgent\Evaluation\EvaluationCase;
use LightAgent\Evaluation\LightEvaluator;
use LightAgent\Result\RunResult;
use PHPUnit\Framework\TestCase;

/**
 * Functional tests for the regression evaluator (evaluation.py semantics).
 */
final class LightEvaluatorTest extends TestCase
{
    /**
     * Fake run target mirroring Python examples.
     */
    private object $target;

    protected function setUp(): void
    {
        $this->target = new class {
            public function run(string $query, array $kwargs = []): RunResult
            {
                $fail = ($kwargs['metadata']['fail'] ?? false) === true;
                if ($fail) {
                    return new RunResult(
                        content: 'boom',
                        trace: [['type' => 'error', 'data' => ['stage' => 'model']]],
                        error: 'boom',
                    );
                }
                return new RunResult(
                    content: 'answer contains magic word',
                    toolCalls: [['name' => 'search']],
                    usage: ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
                    trace: [
                        ['type' => 'tool_call', 'data' => ['name' => 'search']],
                        ['type' => 'model_response', 'data' => []],
                        ['type' => 'run_end', 'data' => ['success' => true, 'duration_ms' => 12.0, 'retry_count' => 0]],
                    ],
                );
            }
        };
    }

    public function test_passing_case(): void
    {
        $evaluator = new LightEvaluator();
        $report = $evaluator->run($this->target, [
            new EvaluationCase(
                name: 'happy path',
                query: 'say the magic word',
                expectedOutputContains: ['magic word'],
                expectedTools: ['search'],
                expectedTraceEvents: ['model_response', 'run_end'],
            ),
        ]);

        self::assertSame(1, $report->total());
        self::assertSame(1, $report->passed());
        self::assertSame(0, $report->failed());
        self::assertSame(1.0, $report->passRate());
        self::assertTrue($report->results[0]->passed);
        self::assertSame([], $report->results[0]->failures);
    }

    public function test_output_mismatch_fails(): void
    {
        $report = (new LightEvaluator())->run($this->target, [
            new EvaluationCase(name: 'bad', query: 'x', expectedOutputContains: ['quantum']),
        ]);

        self::assertFalse($report->results[0]->passed);
        self::assertStringContainsString("output does not contain 'quantum'", $report->results[0]->failures[0]);
    }

    public function test_expected_tool_missing_fails(): void
    {
        $report = (new LightEvaluator())->run($this->target, [
            new EvaluationCase(name: 'tools', query: 'x', expectedTools: ['fetch_url']),
        ]);

        self::assertFalse($report->results[0]->passed);
        self::assertStringContainsString("expected tool 'fetch_url' was not called", $report->results[0]->failures[0]);
    }

    public function test_forbidden_tool_called_fails(): void
    {
        $report = (new LightEvaluator())->run($this->target, [
            new EvaluationCase(name: 'forbidden', query: 'x', forbiddenTools: ['search']),
        ]);

        self::assertFalse($report->results[0]->passed);
        self::assertStringContainsString("forbidden tool 'search' was called", $report->results[0]->failures[0]);
    }

    public function test_expected_trace_event_missing_fails(): void
    {
        $report = (new LightEvaluator())->run($this->target, [
            new EvaluationCase(name: 'events', query: 'x', expectedTraceEvents: ['human_feedback']),
        ]);

        self::assertStringContainsString("expected trace event 'human_feedback' was not recorded", $report->results[0]->failures[0]);
    }

    public function test_error_case_expect_success_false_passes(): void
    {
        $failing = new class {
            public function run(string $query, array $kwargs = []): RunResult
            {
                return new RunResult(content: 'err', error: 'boom', trace: []);
            }
        };

        $report = (new LightEvaluator())->run($failing, [
            new EvaluationCase(name: 'expected failure', query: 'x', expectSuccess: false),
        ]);

        self::assertTrue($report->results[0]->passed);
    }

    public function test_unexpected_error_fails(): void
    {
        $report = (new LightEvaluator())->run($this->target, [
            new EvaluationCase(name: 'unexpected', query: 'x', runKwargs: ['metadata' => ['fail' => true]]),
        ]);

        self::assertFalse($report->results[0]->passed);
        self::assertSame('boom', $report->results[0]->error);
    }

    public function test_max_latency_enforced(): void
    {
        $report = (new LightEvaluator())->run($this->target, [
            new EvaluationCase(name: 'latency', query: 'x', maxLatencyMs: -1.0),
        ]);

        self::assertFalse($report->results[0]->passed);
        self::assertStringContainsString('latency', $report->results[0]->failures[0]);
        self::assertStringContainsString('exceeded', $report->results[0]->failures[0]);
    }

    public function test_custom_checks(): void
    {
        $report = (new LightEvaluator())->run($this->target, [
            new EvaluationCase(
                name: 'checks',
                query: 'x',
                checks: [
                    static fn (mixed $result, array $trace) => true,                        // pass
                    static fn (mixed $result, array $trace) => false,                       // generic fail
                    static fn (mixed $result, array $trace) => 'custom reason',             // reason fail
                    static fn (mixed $result, array $trace): never => throw new \RuntimeException('check blew up'),
                ],
            ),
        ]);

        $failures = $report->results[0]->failures;
        // M-37: the failure message names the check that failed.
        self::assertContains('custom check #1 returned False', $failures);
        self::assertContains('custom check #2 failed: custom reason', $failures);
        self::assertContains('custom check #3 raised: check blew up', $failures);
        self::assertCount(3, $failures);
    }

    /**
     * M-37: a check must return an EXPLICIT verdict. `null` and `0` used to
     * PASS the case, which turned a broken check into a green evaluation.
     */
    public function test_custom_check_without_verdict_fails(): void
    {
        $report = (new LightEvaluator())->run($this->target, [
            new EvaluationCase(
                name: 'no verdict',
                query: 'x',
                checks: [
                    static fn (mixed $result, array $trace) => null,
                    static fn (mixed $result, array $trace) => 0,
                ],
            ),
        ]);

        $failures = $report->results[0]->failures;
        self::assertFalse($report->results[0]->passed);
        self::assertCount(2, $failures);
        self::assertStringContainsString('custom check #0', $failures[0]);
        self::assertStringContainsString('null', $failures[0]);
        self::assertStringContainsString('custom check #1', $failures[1]);
        self::assertStringContainsString('must return true to pass', $failures[1]);
    }

    public function test_require_recovery(): void
    {
        $report = (new LightEvaluator())->run($this->target, [
            new EvaluationCase(name: 'recovery', query: 'x', requireRecovery: true),
        ]);

        self::assertFalse($report->results[0]->passed);
        self::assertStringContainsString('expected a successful recovery', $report->results[0]->failures[0]);
    }

    public function test_report_aggregation(): void
    {
        $report = (new LightEvaluator())->run($this->target, [
            new EvaluationCase(name: 'pass case', query: 'a'),
            new EvaluationCase(name: 'fail case', query: 'b', expectedOutputContains: ['nope']),
        ]);

        self::assertSame(2, $report->total());
        self::assertSame(1, $report->passed());
        self::assertSame(1, $report->failed());
        self::assertSame(0.5, $report->passRate());
        $dict = $report->toDict();
        self::assertSame(1, $dict['passed']);
        self::assertCount(2, $dict['results']);
        self::assertArrayHasKey('average_latency_ms', $dict);
    }

    public function test_target_without_run_rejected(): void
    {
        $this->expectException(\TypeError::class);
        (new LightEvaluator())->run(new \stdClass(), [
            new EvaluationCase(name: 'x', query: 'x'),
        ]);
    }

    public function test_pricing_feeds_cost_estimate(): void
    {
        $evaluator = new LightEvaluator(pricing: ['input_per_million' => 1.0, 'output_per_million' => 2.0]);
        $report = $evaluator->run($this->target, [
            new EvaluationCase(name: 'priced', query: 'x'),
        ]);

        $summary = $report->results[0]->summary;
        self::assertSame(15, $summary->usage['total_tokens']);
        self::assertNotNull($summary->estimatedCostUsd);
        self::assertGreaterThan(0.0, $summary->estimatedCostUsd);
    }
}
