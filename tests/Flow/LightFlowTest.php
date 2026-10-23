<?php

declare(strict_types=1);

namespace LightAgent\Tests\Flow;

use LightAgent\Flow\JsonLightFlowStore;
use LightAgent\Flow\LightFlow;
use LightAgent\Flow\LightFlowResult;
use LightAgent\Hooks\HookDecision;
use LightAgent\Result\RunResult;
use PHPUnit\Framework\TestCase;

/**
 * PHP port of tests/test_lightflow.py.
 */
final class LightFlowTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/la_flow_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*.json') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    /**
     * Python FakeAgent equivalent.
     */
    private function fakeAgent(string $name, array $responses): FakeFlowAgent
    {
        return new FakeFlowAgent($name, $responses);
    }

    public function test_runs_single_step_and_returns_object_result(): void
    {
        $agent = $this->fakeAgent('writer', ['done']);
        $flow = (new LightFlow())->step('write', agent: $agent);

        $result = $flow->run('draft this', ['trace' => true]);

        self::assertInstanceOf(LightFlowResult::class, $result);
        self::assertTrue($result->isSuccess());
        self::assertSame('done', $result->content);
        self::assertSame('write', $result->steps[0]->name);
        self::assertSame('success', $result->steps[0]->status);
        self::assertSame('draft this', $agent->calls[0]['query']);
        self::assertSame(
            ['flow_start', 'step_start', 'step_end', 'flow_end'],
            array_map(static fn (array $e): string => $e['type'], $result->trace),
        );
        self::assertSame($result->traceId, $agent->calls[0]['kwargs']['parent_trace_id']);
        self::assertSame($result->runId, $agent->calls[0]['kwargs']['run_group_id']);
    }

    public function test_passes_dependency_outputs_to_later_steps(): void
    {
        $research = $this->fakeAgent('research', ['facts']);
        $writer = $this->fakeAgent('writer', ['report']);
        $flow = (new LightFlow())
            ->step('research', agent: $research)
            ->step('write', agent: $writer, dependsOn: ['research']);

        $result = $flow->run('analyze company');

        self::assertSame('report', $result->content);
        self::assertStringContainsString('Previous step outputs:', $writer->calls[0]['query']);
        self::assertStringContainsString('research: facts', $writer->calls[0]['query']);
    }

    public function test_supports_callable_step_query(): void
    {
        $research = $this->fakeAgent('research', ['facts']);
        $writer = $this->fakeAgent('writer', ['report']);
        $flow = (new LightFlow())
            ->step('research', agent: $research)
            ->step(
                'write',
                agent: $writer,
                dependsOn: ['research'],
                query: static fn (array $context): string => "Write using {$context['outputs']['research']}",
            );

        $flow->run('ignored');

        self::assertSame('Write using facts', $writer->calls[0]['query']);
    }

    public function test_retries_failed_step_and_records_attempts(): void
    {
        $flaky = $this->fakeAgent('flaky', [
            new RunResult(content: '[LA-500] failed', error: '[LA-500] failed'),
            'recovered',
        ]);
        $flow = (new LightFlow())->step('flaky', agent: $flaky, maxRetry: 2);

        $result = $flow->run('try it');

        self::assertTrue($result->isSuccess());
        self::assertSame('recovered', $result->content);
        self::assertSame(2, $result->steps[0]->attempts);
        self::assertCount(2, $flaky->calls);
    }

    public function test_stops_on_step_error_after_retries(): void
    {
        $failing = $this->fakeAgent('failing', [
            new RunResult(content: '[LA-500] failed', error: '[LA-500] failed'),
            new RunResult(content: '[LA-500] failed again', error: '[LA-500] failed again'),
        ]);
        $skipped = $this->fakeAgent('skipped', ['should not run']);
        $flow = (new LightFlow())
            ->step('failing', agent: $failing, maxRetry: 2)
            ->step('skipped', agent: $skipped, dependsOn: ['failing']);

        $result = $flow->run('try it', ['trace' => true]);

        self::assertFalse($result->isSuccess());
        self::assertSame('[LA-500] failed again', $result->error);
        self::assertSame(2, $result->steps[0]->attempts);
        self::assertSame('failed', $result->steps[0]->status);
        self::assertSame('skipped', $result->steps[1]->status);
        self::assertSame([], $skipped->calls);
        self::assertFalse($result->trace[count($result->trace) - 1]['data']['success']);
    }

    public function test_detects_unknown_dependency_and_cycles(): void
    {
        $flow = (new LightFlow())->step('write', agent: $this->fakeAgent('writer', ['done']), dependsOn: ['missing']);
        try {
            $flow->run('hello');
            self::fail('expected unknown dependency error');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('unknown step', $e->getMessage());
        }

        $cyclic = (new LightFlow())
            ->step('a', agent: $this->fakeAgent('a', ['a']), dependsOn: ['b'])
            ->step('b', agent: $this->fakeAgent('b', ['b']), dependsOn: ['a']);
        try {
            $cyclic->run('hello');
            self::fail('expected cycle error');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('cycle detected', $e->getMessage());
        }
    }

    public function test_validate_reports_isolated_steps_without_blocking_run(): void
    {
        $flow = (new LightFlow())
            ->step('a', agent: $this->fakeAgent('a', ['a']))
            ->step('b', agent: $this->fakeAgent('b', ['b']));

        $validation = $flow->validate();
        $result = $flow->run('hello');

        self::assertSame(['step `a` is isolated', 'step `b` is isolated'], $validation['warnings']);
        self::assertTrue($result->isSuccess());
    }

    public function test_result_format_dict_and_str(): void
    {
        $agent = $this->fakeAgent('writer', ['done', 'done']);
        $flow = (new LightFlow())->step('write', agent: $agent);

        $asDict = $flow->run('draft this', ['result_format' => 'dict']);
        $asStr = $flow->run('draft this', ['result_format' => 'str']);

        self::assertIsArray($asDict);
        self::assertSame('done', $asDict['content']);
        self::assertTrue($asDict['success']);
        self::assertSame('write', $asDict['steps'][0]['name']);
        self::assertSame('success', $asDict['steps'][0]['status']);
        self::assertSame('done', $asStr);
    }

    public function test_timeout_and_fallback_agent(): void
    {
        $slow = new SlowFlowAgent('slow');
        $fallback = $this->fakeAgent('fallback', ['fallback done']);
        $flow = (new LightFlow())->step(
            'work',
            agent: $slow,
            timeout: 0.01,
            fallbackAgent: $fallback,
            allowTimeoutOverlap: true,
        );

        $result = $flow->run('hello');

        self::assertTrue($result->isSuccess());
        self::assertSame('fallback done', $result->content);
        self::assertTrue($result->steps[0]->usedFallback);
        self::assertSame('success', $result->steps[0]->status);
    }

    /**
     * M-31: a timeout must use the fallback agent just like an ordinary
     * agent error does. `allowTimeoutOverlap` used to gate the fallback as
     * well, so the default (`false`) skipped it for a timeout.
     */
    public function test_timeout_uses_the_fallback_agent_without_overlap(): void
    {
        $slow = new SlowFlowAgent('slow');
        $fallback = $this->fakeAgent('fallback', ['fallback done']);
        $flow = (new LightFlow())->step(
            'work',
            agent: $slow,
            timeout: 0.01,
            fallbackAgent: $fallback,
        );

        $result = $flow->run('hello');

        self::assertTrue($result->isSuccess(), (string) $result->error);
        self::assertSame('fallback done', $result->content);
        self::assertTrue($result->steps[0]->usedFallback);
    }

    /**
     * M-31: the fallback can be switched off explicitly.
     */
    public function test_timeout_can_opt_out_of_the_fallback(): void
    {
        $slow = new SlowFlowAgent('slow');
        $fallback = $this->fakeAgent('fallback', ['fallback done']);
        $flow = (new LightFlow())->step(
            'work',
            agent: $slow,
            timeout: 0.01,
            fallbackAgent: $fallback,
            fallbackOnTimeout: false,
        );

        $result = $flow->run('hello');

        self::assertFalse($result->isSuccess());
        self::assertFalse($result->steps[0]->usedFallback);
        self::assertSame([], $fallback->calls);
    }

    /**
     * V5-24: a timed-out agent is signalled through its cancellation token
     * so it can stop instead of running to completion in the background.
     */
    public function test_timed_out_agent_is_signalled(): void
    {
        $slow = new CancellableFlowAgent();
        $flow = (new LightFlow())->step('work', agent: $slow, timeout: 0.02);

        \LightAgent\Async\AsyncRuntime::run(static function () use ($flow): void {
            $flow->run('hello');
            // give the background fiber the chance to observe the signal
            \LightAgent\Async\AsyncRuntime::sleep(0.08);
        });

        self::assertTrue($slow->sawCancellation, 'the timed-out agent must observe the cancellation signal');
    }

    /**
     * V5-24: a two-argument `cancelIf` closure must be supported.
     */
    public function test_two_argument_cancel_if_is_supported(): void
    {
        $second = $this->fakeAgent('second', ['two']);
        $flow = (new LightFlow())
            ->step('first', agent: $this->fakeAgent('first', ['one']))
            ->step(
                'second',
                agent: $second,
                dependsOn: ['first'],
                cancelIf: static fn (string $query, array $context): bool => true,
            );

        $result = $flow->run('go');

        self::assertSame('skipped', $result->status);
        self::assertSame([], $second->calls);
    }

    /**
     * H-6 / V4-15: a hook-blocked step must skip its dependents - the
     * downstream side effects of a denied step must not happen.
     */
    public function test_hook_block_skips_the_dependent_steps(): void
    {
        $hooks = new \LightAgent\Hooks\HookManager([
            static fn (object $ctx): ?HookDecision => $ctx->phase === 'before_flow_step'
                ? HookDecision::block('denied by policy')
                : null,
        ]);
        $one = $this->fakeAgent('one', ['one']);
        $two = $this->fakeAgent('two', ['two']);
        $flow = (new LightFlow(hooks: $hooks))
            ->step('one', agent: $one)
            ->step('two', agent: $two, dependsOn: ['one']);

        $result = $flow->run('q');

        self::assertNotSame('success', $result->status);
        self::assertSame('skipped', $result->steps[0]->status);
        self::assertSame('skipped', $result->steps[1]->status);
        self::assertSame([], $two->calls, 'a dependent of a blocked step must not run');
    }

    /**
     * M-23: an unsafe run id is rejected instead of being rewritten into
     * the file name of ANOTHER run.
     */
    public function test_store_rejects_unsafe_run_ids(): void
    {
        $store = new JsonLightFlowStore($this->tmpDir);
        $store->saveRun('abcdef', ['marker' => 'SECOND']);

        self::assertSame('SECOND', ($store->loadRun('abcdef') ?? [])['marker'] ?? null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unsafe');
        $store->loadRun('abc/def');
    }

    /**
     * V5-27: the checkpoint write is atomic - a failed write leaves the
     * previous checkpoint untouched and no temporary file behind.
     */
    public function test_store_write_is_atomic(): void
    {
        $store = new JsonLightFlowStore($this->tmpDir);
        $store->saveRun('run-1', ['state' => 'ok']);

        try {
            $store->saveRun('run-1', ['state' => INF]);
            self::fail('an unencodable record must be reported');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('cannot be encoded', $e->getMessage());
        }

        self::assertSame('ok', ($store->loadRun('run-1') ?? [])['state'] ?? null);
        self::assertSame([], glob($this->tmpDir . '/*.tmp-*') ?: []);
    }

    public function test_approval_node_can_pause_before_execution(): void
    {
        $agent = $this->fakeAgent('writer', ['done']);
        $flow = (new LightFlow())->step('approve', agent: $agent, requiresApproval: true);

        $result = $flow->run('needs review');

        self::assertSame('waiting_approval', $result->status);
        self::assertFalse($result->isSuccess());
        self::assertSame('waiting_approval', $result->steps[0]->status);
        self::assertSame([], $agent->calls);
    }

    public function test_checkpoint_records_pending_steps(): void
    {
        $store = new JsonLightFlowStore($this->tmpDir);
        $approve = $this->fakeAgent('approve', ['approved']);
        $write = $this->fakeAgent('write', ['done']);
        $flow = (new LightFlow(store: $store))
            ->step('approve', agent: $approve, requiresApproval: true)
            ->step('write', agent: $write, dependsOn: ['approve']);

        $flow->run('needs approval', ['run_id' => 'approval-run']);
        $record = $flow->getRun('approval-run');

        $statuses = [];
        foreach ($record['steps'] as $step) {
            $statuses[$step['name']] = $step['status'];
        }
        self::assertSame(['approve' => 'waiting_approval', 'write' => 'pending'], $statuses);
    }

    public function test_checkpoint_resume_and_rerun(): void
    {
        $store = new JsonLightFlowStore($this->tmpDir);
        $first = $this->fakeAgent('first', ['facts']);
        $flaky = $this->fakeAgent('flaky', [
            new RunResult(content: '[LA-500] failed', error: '[LA-500] failed'),
            'fixed',
            'rerun fixed',
        ]);
        $flow = (new LightFlow(store: $store))
            ->step('first', agent: $first)
            ->step('second', agent: $flaky, dependsOn: ['first']);

        $failed = $flow->run('do work', ['run_id' => 'run-1']);
        $resumed = $flow->resume('run-1');
        $rerun = $flow->rerunStep('run-1', 'second');
        $record = $flow->getRun('run-1');

        self::assertFalse($failed->isSuccess());
        self::assertTrue($resumed->isSuccess());
        self::assertSame('fixed', $resumed->content);
        self::assertSame('rerun fixed', $rerun->content);
        self::assertSame('run-1', $record['run_id']);
        self::assertSame('success', $record['steps'][count($record['steps']) - 1]['status']);
    }

    public function test_hooks_can_replace_step_query_and_trace_decision(): void
    {
        $rewriteStep = static function (object $ctx) {
            if ($ctx->phase === 'before_flow_step') {
                return HookDecision::replace([
                    'query' => 'rewritten by flow hook',
                    'context' => $ctx->payload['context'],
                ]);
            }
            return null;
        };

        $agent = $this->fakeAgent('writer', ['done']);
        $flow = (new LightFlow(hooks: new \LightAgent\Hooks\HookManager([$rewriteStep])))
            ->step('write', agent: $agent);

        $result = $flow->run('original', ['trace' => true, 'run_id' => 'flow-1']);

        self::assertTrue($result->isSuccess());
        self::assertSame('rewritten by flow hook', $agent->calls[0]['query']);
        $found = false;
        foreach ($result->trace as $event) {
            if ($event['type'] === 'hook_decision') {
                $found = true;
            }
        }
        self::assertTrue($found);
    }

    public function test_cancel_stops_pending_steps(): void
    {
        $first = $this->fakeAgent('first', ['one']);
        $second = $this->fakeAgent('second', ['two']);
        $flow = (new LightFlow())
            ->step('first', agent: $first)
            ->step('second', agent: $second, dependsOn: ['first'], cancelIf: static fn (array $ctx): bool => true);

        $result = $flow->run('go');

        self::assertSame('skipped', $result->status);
        self::assertStringContainsString('LA-CANCELLED', (string) $result->error);
        self::assertSame([], $second->calls);
    }

    public function test_double_active_run_rejected(): void
    {
        $agent = $this->fakeAgent('writer', ['done', 'done']);
        $flow = (new LightFlow())->step('write', agent: $agent);

        $flow->run('a', ['run_id' => 'same-run']);
        // completed run finished -> token removed; a fresh run with the same id replays idempotently
        $replay = $flow->run('a', ['run_id' => 'same-run']);
        self::assertTrue($replay->idempotentReplay);
    }
}

/**
 * Python FakeAgent port.
 */
final class FakeFlowAgent
{
    public string $name;
    /** @var list<mixed> */
    private array $responses;
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function __construct(string $name, array $responses)
    {
        $this->name = $name;
        $this->responses = array_values($responses);
    }

    public function run(string $query, array $kwargs = []): RunResult
    {
        $this->calls[] = ['query' => $query, 'kwargs' => $kwargs];
        $response = array_shift($this->responses);
        if ($response instanceof RunResult) {
            return $response;
        }
        return new RunResult(
            content: (string) $response,
            trace: [['type' => 'agent_event', 'data' => ['agent' => $this->name]]],
        );
    }
}

/**
 * Python SlowAgent port (sleeps before responding).
 */
final class SlowFlowAgent
{
    public string $name;
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function run(string $query, array $kwargs = []): RunResult
    {
        $this->calls[] = ['query' => $query, 'kwargs' => $kwargs];
        \LightAgent\Async\AsyncRuntime::sleep(0.05);
        return new RunResult(content: 'late');
    }
}

/**
 * V5-24: an agent that polls its cancellation token while it runs, so a
 * test can observe whether a timeout signalled it.
 */
final class CancellableFlowAgent
{
    public bool $sawCancellation = false;

    public function run(string $query, array $kwargs = []): RunResult
    {
        $token = $kwargs['cancellation_token'] ?? null;
        for ($i = 0; $i < 200; $i++) {
            \LightAgent\Async\AsyncRuntime::sleep(0.01);
            if ($token !== null && $token->isCancelled()) {
                $this->sawCancellation = true;
                break;
            }
        }
        return new RunResult(content: 'late');
    }
}
