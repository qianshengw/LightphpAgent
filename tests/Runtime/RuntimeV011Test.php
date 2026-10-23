<?php

declare(strict_types=1);

namespace LightAgent\Tests\Runtime;

use LightAgent\Async\AsyncRuntime;
use LightAgent\Runtime\AgentRuntime;
use LightAgent\Runtime\GoalStatus;
use LightAgent\Runtime\InboxMessageStatus;
use LightAgent\Runtime\InboxMessageType;
use LightAgent\Runtime\JobStatus;
use LightAgent\Security\SecurityContext;
use PHPUnit\Framework\TestCase;

/**
 * PHP port of tests/test_v011_runtime.py plus Inbox/Goal/Progress coverage.
 */
final class RuntimeV011Test extends TestCase
{
    public function test_expired_job_lease_prevents_result_publication(): void
    {
        [$runtime, $started, $completed] = AsyncRuntime::run(function () {
            $runtime = new AgentRuntime();
            $runtime->openSession();

            $started = $runtime->jobs->start(
                'leased',
                static function (): string {
                    \LightAgent\Async\AsyncRuntime::sleep(0.03);
                    return 'must not publish';
                },
                leaseSeconds: 0.005,
            );
            $completed = $runtime->jobs->wait($started->jobId);
            return [$runtime, $started, $completed];
        });

        self::assertSame(JobStatus::INTERRUPTED, $completed->status);
        self::assertNull($completed->result);
        self::assertSame(1, $completed->leaseEpoch);
        $found = false;
        foreach ($runtime->session->events as $event) {
            if ($event->type === 'job.interrupted') {
                $found = true;
            }
        }
        self::assertTrue($found);
    }

    public function test_job_lease_renewal_and_resume_are_epoch_fenced(): void
    {
        // Epoch fencing semantics (Python test_v011_runtime): resume() bumps
        // the lease epoch, renewLease() rejects stale epochs and foreign
        // owners. The race-window variant of this test lives in the Python
        // suite; here the fencing is exercised deterministically.
        $runtime = new AgentRuntime();
        $runtime->openSession();

        $started = $runtime->jobs->start(
            'leased',
            static fn (): string => 'first',
            leaseSeconds: 60,
        );
        $completed = $runtime->jobs->wait($started->jobId);
        self::assertSame(JobStatus::SUCCESS, $completed->status);

        // Force the record into INTERRUPTED (as markInterrupted would).
        $record = $runtime->jobs->get($started->jobId);
        $record->status = JobStatus::INTERRUPTED;

        $resumed = $runtime->jobs->resume($record->jobId, static fn (): string => 'recovered', leaseSeconds: 60);
        self::assertSame(2, $resumed->leaseEpoch);

        try {
            $runtime->jobs->renewLease($resumed->jobId, 60, expectedEpoch: $resumed->leaseEpoch - 1);
            self::fail('expected stale epoch error');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('stale', $e->getMessage());
        }

        $renewed = $runtime->jobs->renewLease($resumed->jobId, 60, expectedEpoch: $resumed->leaseEpoch);
        self::assertSame(2, $renewed->leaseEpoch);

        // Foreign owner rejected
        try {
            $runtime->jobs->renewLease($resumed->jobId, 60, owner: 'someone-else');
            self::fail('expected owner rejection');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('another owner', $e->getMessage());
        }

        $completedAfter = $runtime->jobs->wait($resumed->jobId);
        self::assertSame(JobStatus::SUCCESS, $completedAfter->status);
        self::assertSame('recovered', $completedAfter->result);
    }

    public function test_job_idempotency_key_deduplicates(): void
    {
        $runtime = new AgentRuntime();
        $runtime->openSession();

        $first = $runtime->jobs->start('dedupe', static fn (): string => 'one', idempotencyKey: 'key-a');
        $second = $runtime->jobs->start('dedupe', static fn (): string => 'two', idempotencyKey: 'key-a');

        self::assertSame($first->jobId, $second->jobId);
    }

    public function test_job_failure_records_error_and_enqueues_inbox(): void
    {
        $runtime = new AgentRuntime();
        $runtime->openSession();

        $started = $runtime->jobs->start('failing', static function (): never {
            throw new \RuntimeException('boom inside job');
        });
        $completed = $runtime->jobs->wait($started->jobId);

        self::assertSame(JobStatus::FAILED, $completed->status);
        self::assertStringContainsString('RuntimeException: boom inside job', (string) $completed->error);

        // Job completion enqueues a CONTEXT inbox message
        $pending = $runtime->inbox->pending();
        self::assertNotSame([], $pending);
        self::assertSame(InboxMessageType::CONTEXT, $pending[0]->type);
        self::assertSame($started->jobId, $pending[0]->correlationId);
    }

    public function test_inbox_claim_complete_lifecycle(): void
    {
        $runtime = new AgentRuntime();
        $runtime->openSession();

        $message = $runtime->inbox->enqueue(InboxMessageType::FOLLOWUP, 'do work');
        $again = $runtime->inbox->enqueue(InboxMessageType::FOLLOWUP, 'do work', messageId: $message->messageId);
        self::assertSame($message->messageId, $again->messageId); // idempotent

        self::assertCount(1, $runtime->inbox->pending());
        $claimed = $runtime->inbox->claimNext();
        self::assertNotNull($claimed);
        self::assertSame(InboxMessageStatus::CLAIMED, $claimed->status);
        self::assertCount(0, $runtime->inbox->pending());

        $done = $runtime->inbox->complete($claimed->messageId, result: 'ok');
        self::assertSame(InboxMessageStatus::COMPLETED, $done->status);

        // Completing an already-completed message is idempotent (Python semantics)
        $again = $runtime->inbox->complete($claimed->messageId, result: 'ignored');
        self::assertSame(InboxMessageStatus::COMPLETED, $again->status);
    }

    public function test_inbox_steering_respects_safe_boundary(): void
    {
        $runtime = new AgentRuntime();
        $runtime->openSession();
        $runtime->inbox->enqueue(InboxMessageType::STEERING, 'redirect');

        // M-36: the SAFE default must not hand out a steering message: the
        // queue has no notion of turn state, so a steering message would
        // interrupt the running turn.
        self::assertNull($runtime->inbox->claimNext());
        self::assertNull($runtime->inbox->claimNext(safeBoundary: true));
        // The unsafe behaviour is the explicit opt-in.
        $claimed = $runtime->inbox->claimNext(safeBoundary: false);
        self::assertNotNull($claimed);
        self::assertSame(InboxMessageType::STEERING, $claimed->type);
    }

    public function test_goal_lifecycle_and_terminal_guard(): void
    {
        $runtime = new AgentRuntime();
        $runtime->openSession();

        $goal = $runtime->goals->create('ship the port', acceptanceCriteria: ['all tests green']);
        self::assertSame(GoalStatus::PENDING, $goal->status);

        $runtime->goals->activate($goal->goalId);
        self::assertSame(GoalStatus::ACTIVE, $runtime->goals->get($goal->goalId)->status);

        $blocked = $runtime->goals->block($goal->goalId, 'waiting on DAG port');
        self::assertSame(GoalStatus::BLOCKED, $blocked->status);
        self::assertSame('waiting on DAG port', $blocked->blocker);

        $completed = $runtime->goals->complete($goal->goalId, evidence: ['evidence-1']);
        self::assertSame(GoalStatus::COMPLETED, $completed->status);
        self::assertNull($completed->blocker);
        self::assertSame(['evidence-1'], $completed->evidence);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('terminal goal');
        $runtime->goals->activate($goal->goalId);
    }

    public function test_goal_restore_from_session_events(): void
    {
        $store = new \LightAgent\Session\InMemorySessionStore();
        $runtime = new AgentRuntime(sessionStore: $store);
        $runtime->openSession('s-shared');
        $goal = $runtime->goals->create('restorable goal');
        $runtime->goals->activate($goal->goalId);

        // A second runtime sharing the same store reopens the persisted session
        $restored = new AgentRuntime(sessionStore: $store);
        $restored->openSession('s-shared');
        $restored->goals->restore($restored->session);

        $goals = $restored->goals->list(GoalStatus::ACTIVE);
        self::assertCount(1, $goals);
        self::assertSame('restorable goal', $goals[0]->objective);
    }

    public function test_subagent_depth_and_permission_narrowing(): void
    {
        $runtime = new AgentRuntime();
        $runtime->openSession();

        $parent = $runtime->subagents->register(
            new \LightAgent\Tests\Runtime\FakeSubAgent('parent'),
            allowedCapabilities: ['tool.read'],
            maxRisk: \LightAgent\Capabilities\CapabilityRisk::ISOLATED_WRITE,
        );
        $child = $runtime->subagents->register(
            new \LightAgent\Tests\Runtime\FakeSubAgent('child'),
            parentAgentId: $parent->agentId,
            allowedCapabilities: ['tool.read'],
        );

        self::assertSame(1, $parent->depth);
        self::assertSame(2, $child->depth);
        self::assertSame($parent->agentId, $child->parentAgentId);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('depth limit');
        $runtime->subagents->register(
            new \LightAgent\Tests\Runtime\FakeSubAgent('grandchild'),
            parentAgentId: $child->agentId,
        );
    }

    public function test_subagent_run_records_lifecycle(): void
    {
        $runtime = new AgentRuntime();
        $runtime->openSession();

        $agent = new FakeSubAgent('worker');
        $record = $runtime->subagents->register($agent);

        $result = $runtime->subagents->run($record->agentId, 'hello');
        self::assertSame('ran: hello', $result);
        self::assertSame('success', $record->status);
    }

    public function test_progress_tracker_detects_stalls(): void
    {
        $events = [];
        $tracker = new \LightAgent\Runtime\ProgressTracker(
            maxUnchangedSteps: 2,
            maxRepeatedToolCalls: 2,
            eventSink: static function (string $type, array $data) use (&$events): void {
                $events[] = [$type, $data];
            },
        );

        $tracker->record('same output');
        $tracker->record('same output');
        $tracker->record('same output');
        self::assertTrue($tracker->isStalled());
        $stalls = array_values(array_filter($events, static fn (array $e): bool => $e[0] === 'progress.stalled'));
        self::assertCount(1, $stalls);
        self::assertSame('unchanged_output', $stalls[0][1]['reason']);
    }

    public function test_open_session_binds_security_context(): void
    {
        $runtime = new AgentRuntime();
        $context = new SecurityContext(
            tenantId: 'tenant-a',
            projectId: 'project-a',
            permissions: new \LightAgent\Capabilities\PermissionSet(allowed: ['tool.read']),
        )->narrow(agentId: 'agent-a');

        $session = $runtime->openSession('session-a', securityContext: $context);

        self::assertSame('session-a', $session->sessionId);
        self::assertSame('session-a', $runtime->context->sessionId);
        self::assertSame('tenant-a', $runtime->context->tenantId);
        self::assertSame('project-a', $runtime->context->projectId);

        $conflicting = SecurityContext::fromDict(
            array_merge((new SecurityContext(tenantId: 't'))->toDict(), ['session_id' => 'other']),
        );
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not match');
        $runtime->openSession('session-b', securityContext: $conflicting);
    }

    public function test_snapshot_contains_all_managers(): void
    {
        $runtime = new AgentRuntime();
        $runtime->openSession();
        $runtime->goals->create('snap goal');
        $runtime->inbox->enqueue(InboxMessageType::CONTEXT, 'x');

        $snap = $runtime->snapshot();

        self::assertSame($runtime->runtimeId, $snap['runtime_id']);
        self::assertArrayHasKey('session', $snap);
        self::assertArrayHasKey('replay', $snap);
        self::assertArrayHasKey('budget', $snap);
        self::assertCount(1, $snap['goals']);
        self::assertCount(1, $snap['inbox']);
        self::assertSame([], $snap['jobs']);
    }

    /**
     * H-19: the budget usage is persisted as `budget.consumed` session
     * events, so reopening the session does not reset the budget to zero.
     */
    public function test_budget_usage_survives_a_session_reopen(): void
    {
        $store = new \LightAgent\Session\InMemorySessionStore();
        $limits = new \LightAgent\Runtime\BudgetLimits(modelCalls: 5, tokens: 1000);
        $runtime = new AgentRuntime(
            sessionStore: $store,
            budget: new \LightAgent\Runtime\BudgetManager(limits: $limits),
        );
        $runtime->openSession('budget-session');
        $runtime->budget->consume(modelCalls: 3, tokens: 100);

        $reopened = new AgentRuntime(
            sessionStore: $store,
            budget: new \LightAgent\Runtime\BudgetManager(limits: $limits),
        );
        $reopened->openSession('budget-session');

        self::assertSame(3, $reopened->budget->usage->modelCalls);
        self::assertSame(100, $reopened->budget->usage->tokens);
        self::assertSame(2, $reopened->budget->remaining()['model_calls']);
        self::assertSame(900, $reopened->budget->remaining()['tokens']);

        $consumed = 0;
        foreach ($reopened->session->events as $event) {
            if ($event->type === 'budget.consumed') {
                $consumed++;
            }
        }
        self::assertGreaterThan(0, $consumed, 'the budget usage must reach the session log');
    }

    /**
     * M-27: a negative delta is rejected - it used to inflate the
     * remaining budget.
     */
    public function test_budget_rejects_a_negative_delta(): void
    {
        $budget = new \LightAgent\Runtime\BudgetManager(
            limits: new \LightAgent\Runtime\BudgetLimits(modelCalls: 5),
        );

        try {
            $budget->consume(modelCalls: -10);
            self::fail('a negative delta must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('negative', $e->getMessage());
        }

        self::assertSame(0, $budget->usage->modelCalls);
        self::assertSame(5, $budget->remaining()['model_calls']);
    }

    /**
     * V5-41: an over-budget consume still records the usage that was
     * actually spent.
     */
    public function test_over_budget_consume_records_the_usage(): void
    {
        $budget = new \LightAgent\Runtime\BudgetManager(
            limits: new \LightAgent\Runtime\BudgetLimits(tokens: 100),
        );

        try {
            $budget->consume(tokens: 150);
            self::fail('an over-budget consume must throw');
        } catch (\LightAgent\Runtime\BudgetExceeded $e) {
            self::assertStringContainsString('tokens', $e->getMessage());
        }

        self::assertSame(150, $budget->usage->tokens);
    }

    /**
     * V4-14 / V5-25: a registration whose parent is not registered must
     * fail instead of widening the child to an unrestricted permission set.
     */
    public function test_orphan_subagent_registration_is_refused(): void
    {
        $manager = new \LightAgent\Runtime\SubagentManager();

        try {
            $manager->register(
                new FakeSubAgent('orphan'),
                parentAgentId: 'not-registered',
            );
            self::fail('an orphan subagent must be refused');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('unknown parent', $e->getMessage());
        }

        $root = $manager->register(new FakeSubAgent('root'));
        self::assertTrue($root->permissions->isUnrestricted(), 'a root registration is explicitly unrestricted');

        $child = $manager->register(
            new FakeSubAgent('child'),
            parentAgentId: $root->agentId,
            allowedCapabilities: ['tool.read'],
            maxRisk: \LightAgent\Capabilities\CapabilityRisk::ISOLATED_WRITE,
        );
        self::assertTrue($child->permissions->allows('tool.read'));
        self::assertFalse($child->permissions->allows('tool.write'));
        self::assertFalse(
            $child->permissions->allows('tool.read', \LightAgent\Capabilities\CapabilityRisk::DESTRUCTIVE),
        );
    }

    /**
     * M-35: the lease epoch fences the execution path - a stale execution
     * must not publish its result over a resumed job.
     */
    public function test_stale_job_execution_cannot_publish_its_result(): void
    {
        $runtime = new AgentRuntime();
        $runtime->openSession();

        $record = $runtime->jobs->start(
            'leased',
            static function (): string {
                AsyncRuntime::sleep(0.05);
                return 'stale result';
            },
            leaseSeconds: 60,
        );
        AsyncRuntime::sleep(0.01);
        $record->status = JobStatus::INTERRUPTED;
        $runtime->jobs->resume($record->jobId, static fn (): string => 'fresh result', leaseSeconds: 60);
        $runtime->jobs->wait($record->jobId);

        self::assertSame('fresh result', $record->result);
        self::assertSame(JobStatus::SUCCESS, $record->status);
    }

    /**
     * V5-43: one cancellation emits exactly one `job.cancelled` event.
     */
    public function test_cancel_emits_a_single_job_cancelled_event(): void
    {
        $events = [];
        $manager = new \LightAgent\Runtime\JobManager(
            eventSink: static function (string $type, array $data) use (&$events): void {
                $events[] = $type;
            },
        );
        $record = $manager->start('cancelled', static function (): string {
            AsyncRuntime::sleep(0.05);
            return 'late';
        });

        self::assertTrue($manager->cancel($record->jobId, 'stop'));
        $manager->wait($record->jobId);

        $cancelled = array_values(array_filter(
            $events,
            static fn (string $type): bool => $type === 'job.cancelled',
        ));
        self::assertCount(1, $cancelled, implode(',', $events));
    }

    /**
     * V5-42: a rejected transition must not leave the evidence behind.
     */
    public function test_rejected_goal_transition_keeps_no_evidence(): void
    {
        $runtime = new AgentRuntime();
        $runtime->openSession();

        $goal = $runtime->goals->create('goal');
        $runtime->goals->cancel($goal->goalId, 'not any more');

        try {
            $runtime->goals->complete($goal->goalId, evidence: ['evidence-1']);
            self::fail('a terminal goal must not transition');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('terminal goal', $e->getMessage());
        }

        self::assertSame([], $goal->evidence);
    }
}

/**
 * Minimal agent stub for SubagentManager tests.
 */
final class FakeSubAgent
{
    public function __construct(public readonly string $name)
    {
    }

    public function run(string $query, array $kwargs = []): string
    {
        return 'ran: ' . $query;
    }
}
