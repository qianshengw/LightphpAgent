<?php

declare(strict_types=1);

namespace LightAgent\Tests\Review;

use LightAgent\Hooks\HookAction;
use LightAgent\Hooks\HookContext;
use LightAgent\Hooks\HookDecision;
use LightAgent\Review\ApprovalConstants;
use LightAgent\Review\ApprovalDecision;
use LightAgent\Review\HumanApprovalHook;
use PHPUnit\Framework\TestCase;

/**
 * Functional tests for the human approval hook (review.py HumanApprovalHook).
 */
final class HumanApprovalHookTest extends TestCase
{
    private function makeContext(string $phase = 'before_tool_call', array $payload = [], array $metadata = []): HookContext
    {
        return new HookContext(
            phase: $phase,
            payload: $payload ?: [
                'tool_name' => 'delete_file',
                'arguments' => ['path' => '/tmp/x'],
            ],
            traceId: 'trace-1',
            runId: 'run-1',
            userId: 'alice',
            agentName: 'writer',
            metadata: $metadata,
        );
    }

    public function test_no_reviewer_stores_pending_and_blocks(): void
    {
        $store = new \LightAgent\Review\InMemoryReviewStore();
        $hook = new HumanApprovalHook(store: $store);

        $decision = $hook($this->makeContext());

        self::assertSame(HookAction::BLOCK, $decision->action);
        self::assertNotNull($decision->metadata['approval_request_id'] ?? null);
        $events = $decision->metadata['review_events'];
        self::assertSame('approval_required', $events[0]['type']);
        self::assertSame('human approval required', $decision->reason);

        // request persisted in the store
        $pending = $store->listPending();
        self::assertCount(1, $pending);
        self::assertSame('delete_file', $pending[0]->toolName);
    }

    public function test_approving_reviewer_continues(): void
    {
        $hook = new HumanApprovalHook(
            reviewer: static fn (object $request) => true,
        );

        $decision = $hook($this->makeContext());

        self::assertSame(HookAction::CONTINUE, $decision->action);
        self::assertSame('approval_approve', $decision->metadata['review_events'][0]['type']);
    }

    public function test_rejecting_reviewer_blocks(): void
    {
        $hook = new HumanApprovalHook(
            reviewer: static fn (object $request) => 'not allowed on weekdays',
        );

        $decision = $hook($this->makeContext());

        self::assertSame(HookAction::BLOCK, $decision->action);
        self::assertSame('not allowed on weekdays', $decision->reason);
    }

    public function test_reviewer_exception_becomes_rejection(): void
    {
        $hook = new HumanApprovalHook(
            reviewer: static function (object $request): never {
                throw new \RuntimeException('review service down');
            },
        );

        $decision = $hook($this->makeContext());

        self::assertSame(HookAction::BLOCK, $decision->action);
        self::assertStringContainsString('approval reviewer failed', (string) $decision->reason);
        self::assertStringContainsString('review service down', (string) $decision->reason);
    }

    public function test_resolved_request_is_reused_on_rerun(): void
    {
        $store = new \LightAgent\Review\InMemoryReviewStore();
        $hook = new HumanApprovalHook(store: $store);

        // First pass: pending, capture the request id
        $first = $hook($this->makeContext());
        $requestId = (string) $first->metadata['approval_request_id'];

        // Human approves offline
        $store->resolve($requestId, ApprovalDecision::approve());

        // Rerun with approval_id — matching context reuses the decision
        $second = $hook($this->makeContext(metadata: ['approval_id' => $requestId]));

        self::assertSame(HookAction::CONTINUE, $second->action);
        self::assertTrue($second->metadata['review_events'][0]['reused']);
    }

    public function test_approval_context_mismatch_is_rejected(): void
    {
        $store = new \LightAgent\Review\InMemoryReviewStore();
        $hook = new HumanApprovalHook(store: $store);

        $first = $hook($this->makeContext());
        $requestId = (string) $first->metadata['approval_request_id'];
        $store->resolve($requestId, ApprovalDecision::approve());

        // Different tool → context mismatch
        $second = $hook($this->makeContext(
            payload: ['tool_name' => 'other_tool', 'arguments' => ['x' => 1]],
            metadata: ['approval_id' => $requestId],
        ));

        self::assertSame(HookAction::BLOCK, $second->action);
        self::assertSame('approval does not match the current action', $second->reason);
        self::assertSame('approval_rejected', $second->metadata['review_events'][0]['type']);
    }

    public function test_unknown_approval_id_fails_closed(): void
    {
        $hook = new HumanApprovalHook(store: new \LightAgent\Review\InMemoryReviewStore());

        $decision = $hook($this->makeContext(metadata: ['approval_id' => 'nonexistent']));

        self::assertSame(HookAction::BLOCK, $decision->action);
        self::assertSame('approval request not found or unresolved', $decision->reason);
    }

    public function test_tool_filter_ignores_other_tools(): void
    {
        $hook = new HumanApprovalHook(tools: ['delete_file']);

        $decision = $hook($this->makeContext(
            payload: ['tool_name' => 'safe_tool', 'arguments' => []],
        ));

        self::assertNull($decision);
    }

    public function test_phase_filter(): void
    {
        $hook = new HumanApprovalHook(phases: ['on_handoff']);

        $decision = $hook($this->makeContext(phase: 'before_tool_call'));

        self::assertNull($decision);
    }

    public function test_edit_decision_replaces_arguments(): void
    {
        $hook = new HumanApprovalHook(
            reviewer: static fn (object $request) => ApprovalDecision::edit(
                ['path' => '/tmp/safe'],
            ),
        );

        $decision = $hook($this->makeContext());

        self::assertSame(HookAction::REPLACE, $decision->action);
        self::assertSame('/tmp/safe', $decision->payload['arguments']['path']);
    }

    /**
     * H-12: an EDIT decision that carries no arguments must not wipe the
     * reviewed ones — the tool would then run with NO arguments at all.
     */
    public function test_edit_decision_without_arguments_keeps_the_reviewed_arguments(): void
    {
        $hook = new HumanApprovalHook(
            reviewer: static fn (object $request): array => ['action' => 'edit'],
        );

        $decision = $hook($this->makeContext());

        self::assertSame(HookAction::REPLACE, $decision->action);
        self::assertSame(
            ['path' => '/tmp/x'],
            $decision->payload['arguments'] ?? null,
            'the reviewed arguments must survive an argument-less EDIT',
        );
    }

    /**
     * M-41: the run id is regenerated for every run, so an approval is
     * bound to the ACTION and not to the run.
     */
    public function test_an_approval_can_be_consumed_in_a_later_run(): void
    {
        $store = new \LightAgent\Review\InMemoryReviewStore();
        $hook = new HumanApprovalHook(store: $store);

        $first = $hook(new HookContext(
            phase: 'before_tool_call',
            payload: ['tool_name' => 'delete_file', 'arguments' => ['path' => '/tmp/x']],
            runId: 'run-1',
        ));
        $requestId = (string) $first->metadata['approval_request_id'];
        $store->resolve($requestId, ApprovalDecision::approve());

        $second = $hook(new HookContext(
            phase: 'before_tool_call',
            payload: ['tool_name' => 'delete_file', 'arguments' => ['path' => '/tmp/x']],
            runId: 'run-2',
            metadata: ['approval_id' => $requestId],
        ));
        self::assertSame(HookAction::CONTINUE, $second->action, 'the approval must survive the run boundary');

        $otherTool = $hook(new HookContext(
            phase: 'before_tool_call',
            payload: ['tool_name' => 'other_tool', 'arguments' => ['path' => '/tmp/x']],
            runId: 'run-2',
            metadata: ['approval_id' => $requestId],
        ));
        self::assertSame(HookAction::BLOCK, $otherTool->action, 'a different tool must not reuse the approval');

        $otherArguments = $hook(new HookContext(
            phase: 'before_tool_call',
            payload: ['tool_name' => 'delete_file', 'arguments' => ['path' => '/tmp/other']],
            runId: 'run-2',
            metadata: ['approval_id' => $requestId],
        ));
        self::assertSame(HookAction::BLOCK, $otherArguments->action, 'different arguments must not reuse the approval');
    }

    /**
     * V5-16: an approval block is machine-readable.
     */
    public function test_an_approval_block_carries_the_approval_error_code(): void
    {
        $hook = new HumanApprovalHook(
            reviewer: static fn (object $request) => 'not allowed',
        );

        $decision = $hook($this->makeContext());

        self::assertSame(HookAction::BLOCK, $decision->action);
        self::assertSame('LA-APPROVAL', $decision->metadata['error_code'] ?? null);
    }

    public function test_respond_decision_blocks_with_human_response(): void
    {
        $hook = new HumanApprovalHook(
            reviewer: static fn (object $request) => ApprovalDecision::respond('use the safe endpoint instead'),
        );

        $decision = $hook($this->makeContext());

        self::assertSame(HookAction::BLOCK, $decision->action);
        self::assertSame('use the safe endpoint instead', $decision->reason);
    }

    public function test_handoff_phase_carries_query_into_arguments(): void
    {
        $store = new \LightAgent\Review\InMemoryReviewStore();
        $hook = new HumanApprovalHook(store: $store, phases: ['on_handoff']);

        $hook($this->makeContext(phase: 'on_handoff', payload: [
            'query' => 'do the thing',
            'source_agent' => 'a',
            'target_agent' => 'b',
        ]));

        $pending = $store->listPending();
        self::assertCount(1, $pending);
        self::assertSame('do the thing', $pending[0]->arguments['query']);
        self::assertSame('on_handoff', $pending[0]->action);
    }

    public function test_invalid_timeout_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HumanApprovalHook(timeout: 0);
    }

    public function test_timeout_expires_into_rejection(): void
    {
        $hook = new HumanApprovalHook(
            reviewer: static function (object $request) {
                \LightAgent\Async\AsyncRuntime::sleep(0.2);
                return true;
            },
            timeout: 0.05,
        );

        $decision = $hook($this->makeContext());

        self::assertSame(HookAction::BLOCK, $decision->action);
        self::assertStringContainsString('timed out', (string) $decision->reason);
    }
}
