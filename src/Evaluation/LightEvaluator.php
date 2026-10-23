<?php

declare(strict_types=1);

namespace LightAgent\Evaluation;

use LightAgent\Tracing\TraceSummary;
use LightAgent\Tracing\TraceUtils;

/**
 * One deterministic agent or LightFlow regression case
 * (evaluation.py EvaluationCase).
 *
 * @param list<string> $expectedOutputContains
 * @param list<string> $expectedTools
 * @param list<string> $forbiddenTools
 * @param list<string> $expectedTraceEvents
 * @param list<callable> $checks fn(mixed $result, array $trace): mixed — a check
 *        must return an EXPLICIT verdict: `true` passes, `false` fails, a
 *        non-empty string is the failure reason and `[false, reason]` fails
 *        with that reason. Anything else (e.g. `null`) is a failure (M-37).
 * @param array<string, mixed> $runKwargs
 * @param array<string, mixed> $metadata
 */
final class EvaluationCase
{
    public function __construct(
        public readonly string $name,
        public readonly string $query,
        public array $expectedOutputContains = [],
        public array $expectedTools = [],
        public array $forbiddenTools = [],
        public array $expectedTraceEvents = [],
        public readonly bool $expectSuccess = true,
        public readonly ?float $maxLatencyMs = null,
        public readonly bool $requireRecovery = false,
        public array $checks = [],
        public array $runKwargs = [],
        public array $metadata = [],
    ) {
    }
}

/**
 * Result and metrics for one evaluation case (evaluation.py EvaluationCaseResult).
 */
final class EvaluationCaseResult
{
    public function __construct(
        public readonly string $name,
        public readonly bool $passed,
        public readonly string $content,
        public readonly ?string $error,
        /** @var list<string> */
        public array $failures,
        public readonly float $durationMs,
        /** @var list<array<string, mixed>> */
        public array $trace,
        public readonly TraceSummary $summary,
        /** @var array<string, mixed> */
        public array $metadata = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'name' => $this->name,
            'passed' => $this->passed,
            'content' => $this->content,
            'error' => $this->error,
            'failures' => $this->failures,
            'duration_ms' => $this->durationMs,
            'summary' => $this->summary->toDict(),
            'metadata' => $this->metadata,
        ];
    }
}

/**
 * Aggregate report for a deterministic evaluation suite
 * (evaluation.py EvaluationReport).
 */
final class EvaluationReport
{
    /**
     * @param list<EvaluationCaseResult> $results
     */
    public function __construct(
        public array $results = [],
    ) {
    }

    public function total(): int
    {
        return count($this->results);
    }

    public function passed(): int
    {
        $count = 0;
        foreach ($this->results as $result) {
            if ($result->passed) {
                $count++;
            }
        }
        return $count;
    }

    public function failed(): int
    {
        return $this->total() - $this->passed();
    }

    public function passRate(): float
    {
        $total = $this->total();
        return $total > 0 ? $this->passed() / $total : 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        $durations = array_map(static fn (EvaluationCaseResult $r): float => $r->durationMs, $this->results);
        $costs = [];
        foreach ($this->results as $result) {
            if ($result->summary->estimatedCostUsd !== null) {
                $costs[] = $result->summary->estimatedCostUsd;
            }
        }
        return [
            'total' => $this->total(),
            'passed' => $this->passed(),
            'failed' => $this->failed(),
            'pass_rate' => $this->passRate(),
            'average_latency_ms' => $durations !== [] ? array_sum($durations) / count($durations) : 0.0,
            'estimated_cost_usd' => $costs !== [] ? array_sum($costs) : null,
            'results' => array_map(static fn (EvaluationCaseResult $r): array => $r->toDict(), $this->results),
        ];
    }
}

/**
 * Run fixed cases against any object exposing a compatible run() method
 * (port of evaluation.py LightEvaluator).
 */
final class LightEvaluator
{
    /**
     * @param array<string, float>|null $pricing
     */
    public function __construct(
        public readonly ?array $pricing = null,
    ) {
    }

    /**
     * @param list<EvaluationCase> $cases
     */
    public function run(object $target, array $cases): EvaluationReport
    {
        if (!method_exists($target, 'run')) {
            throw new \TypeError('evaluation target must provide run()');
        }
        return new EvaluationReport(array_map(
            fn (EvaluationCase $case): EvaluationCaseResult => $this->runCase($target, $case),
            $cases,
        ));
    }

    private function runCase(object $target, EvaluationCase $case): EvaluationCaseResult
    {
        $kwargs = $case->runKwargs;
        $kwargs += ['result_format' => 'object', 'trace' => true];
        $started = microtime(true);
        $raisedError = null;
        $result = null;
        try {
            $result = $target->run($case->query, $kwargs);
        } catch (\Throwable $exc) {
            $raisedError = $exc->getMessage();
        }
        $durationMs = round((microtime(true) - $started) * 1000, 3);

        // Result extraction is fallible too (H11): a target returning an
        // un-stringable object must fail THIS case, not the whole evaluation.
        try {
            $content = $result === null ? '' : (is_object($result) && isset($result->content) ? (string) $result->content : (string) $result);
        } catch (\Throwable $exc) {
            $content = '';
            $raisedError = $raisedError ?? "result extraction failed: {$exc->getMessage()}";
        }
        $error = $raisedError ?? (is_object($result) && isset($result->error) && $result->error !== null ? (string) $result->error : null);
        $trace = is_object($result) && isset($result->trace) && is_array($result->trace) ? $result->trace : [];
        $usage = is_object($result) && isset($result->usage) ? $result->usage : null;
        // The usage of the evaluated run belongs to the traced events of
        // that run, so it is reported even when the trace holds no
        // `model_request` event (V5-34).
        $summary = TraceUtils::summarize($trace, usage: $usage, pricing: $this->pricing, usageIsTraceScoped: true);
        if ($summary->durationMs === null) {
            $summary->durationMs = $durationMs;
        }

        /** @var list<string> $failures */
        $failures = [];
        $success = $error === null;
        if ($success !== $case->expectSuccess) {
            $failures[] = "expected success=" . ($case->expectSuccess ? 'true' : 'false')
                . ', got success=' . ($success ? 'true' : 'false');
        }

        foreach ($case->expectedOutputContains as $expected) {
            if (!str_contains($content, $expected)) {
                $failures[] = "output does not contain '{$expected}'";
            }
        }

        $eventTypes = [];
        $calledTools = [];
        foreach ($trace as $event) {
            $type = (string) ($event['type'] ?? '');
            $eventTypes[] = $type;
            if ($type === 'tool_call') {
                $calledTools[] = (string) (($event['data'] ?? [])['name'] ?? '');
            }
        }
        foreach ($case->expectedTools as $tool) {
            if (!in_array($tool, $calledTools, true)) {
                $failures[] = "expected tool '{$tool}' was not called";
            }
        }
        foreach ($case->forbiddenTools as $tool) {
            if (in_array($tool, $calledTools, true)) {
                $failures[] = "forbidden tool '{$tool}' was called";
            }
        }
        foreach ($case->expectedTraceEvents as $eventType) {
            if (!in_array($eventType, $eventTypes, true)) {
                $failures[] = "expected trace event '{$eventType}' was not recorded";
            }
        }

        if ($case->maxLatencyMs !== null && $durationMs > $case->maxLatencyMs) {
            $failures[] = sprintf('latency %.3fms exceeded %.3fms', $durationMs, $case->maxLatencyMs);
        }
        if ($case->requireRecovery && !($success && $summary->retryCount > 0)) {
            $failures[] = 'expected a successful recovery after at least one retry';
        }

        // A custom check must return an EXPLICIT verdict (M-37). Anything
        // that is not `true` is a failure: `null` and `0` used to slip
        // through and silently PASS the case, which made a broken check
        // look like a green evaluation. The failure message names the
        // check that failed.
        foreach ($case->checks as $index => $check) {
            try {
                $outcome = $check($result, $trace);
            } catch (\Throwable $exc) {
                $failures[] = "custom check #{$index} raised: {$exc->getMessage()}";
                continue;
            }
            if ($outcome === true) {
                continue;
            }
            if ($outcome === false) {
                $failures[] = "custom check #{$index} returned False";
                continue;
            }
            if (is_string($outcome) && $outcome !== '') {
                $failures[] = "custom check #{$index} failed: {$outcome}";
                continue;
            }
            if (is_array($outcome) && $outcome !== []) {
                if (($outcome[0] ?? null) === true) {
                    continue;
                }
                if (($outcome[0] ?? null) === false) {
                    $failures[] = "custom check #{$index} failed: " . (string) ($outcome[1] ?? 'custom check failed');
                    continue;
                }
            }
            $failures[] = "custom check #{$index} returned " . self::describeVerdict($outcome)
                . '; a check must return true to pass';
        }

        return new EvaluationCaseResult(
            name: $case->name,
            passed: $failures === [],
            content: $content,
            error: $error,
            failures: $failures,
            durationMs: $durationMs,
            trace: $trace,
            summary: $summary,
            metadata: $case->metadata,
        );
    }

    /**
     * Describe a non-verdict check outcome for the failure message.
     */
    private static function describeVerdict(mixed $outcome): string
    {
        if ($outcome === null) {
            return 'null';
        }
        if (is_bool($outcome)) {
            return $outcome ? 'true' : 'false';
        }
        if (is_string($outcome)) {
            return $outcome === '' ? 'an empty string' : "'{$outcome}'";
        }
        if (is_array($outcome)) {
            return $outcome === [] ? 'an empty array' : 'an array without a verdict';
        }
        if (is_int($outcome) || is_float($outcome)) {
            return (string) $outcome;
        }
        return get_debug_type($outcome);
    }
}
