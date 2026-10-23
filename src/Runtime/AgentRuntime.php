<?php

declare(strict_types=1);

namespace LightAgent\Runtime;

use LightAgent\Capabilities\RuntimeContext;
use LightAgent\Session\InMemorySessionStore;
use LightAgent\Session\Session;
use LightAgent\Session\SessionCheckpoint;
use LightAgent\Session\SessionStore;

/**
 * Bundle Session, Registry, Inbox, Goal, Budget, Job, and Subagent state
 * (port of runtime.py AgentRuntime).
 */
final class AgentRuntime
{
    public ?Session $session = null;
    public RuntimeContext $context;

    public readonly string $runtimeId;
    public SessionStore $sessionStore;
    public BudgetManager $budget;
    public AgentInbox $inbox;
    public GoalManager $goals;
    public JobManager $jobs;
    public SubagentManager $subagents;
    public ProgressTracker $progress;

    public function __construct(
        string $runtimeId = '',
        ?SessionStore $sessionStore = null,
        ?BudgetManager $budget = null,
        ?SubagentManager $subagents = null,
    ) {
        $this->runtimeId = $runtimeId !== '' ? $runtimeId : bin2hex(random_bytes(16));
        $this->sessionStore = $sessionStore ?? new InMemorySessionStore();
        $this->budget = $budget ?? new BudgetManager();
        $this->context = new RuntimeContext(runtimeId: $this->runtimeId);
        $this->inbox = new AgentInbox(eventSink: $this->append(...));
        $this->goals = new GoalManager(eventSink: $this->append(...));
        // H-19: the budget usage is persisted as `budget.consumed` session
        // events, exactly like the inbox / goals / jobs / subagents /
        // progress state. Without it, `BudgetManager::restore()` found an
        // empty history and every session reopen reset the budget to zero.
        // An INJECTED budget is wired too, so a budget built outside the
        // runtime (LightAgent builds one) keeps its usage as well.
        if ($this->budget->eventSink === null) {
            $this->budget->setEventSink($this->appendBudgetEvent(...));
        }
        $this->jobs = new JobManager(eventSink: $this->append(...), inbox: $this->inbox);
        $this->subagents = $subagents ?? new SubagentManager(eventSink: $this->append(...));
        $this->progress = new ProgressTracker(eventSink: $this->append(...));
    }

    /**
     * Open a session, optionally bound to a SecurityContext
     * (runtime.py open_session with security_context).
     *
     * @param array<string, mixed>|null $metadata
     */
    public function openSession(
        ?string $sessionId = null,
        ?array $metadata = null,
        ?string $agentId = null,
        ?string $userId = null,
        ?\LightAgent\Security\SecurityContext $securityContext = null,
    ): Session {
        $session = $sessionId !== null ? $this->sessionStore->get($sessionId) : null;
        if ($session === null) {
            $session = new Session(sessionId: $sessionId ?? bin2hex(random_bytes(16)), metadata: $metadata ?? []);
            $session->append('session.started', ['runtime_id' => $this->runtimeId]);
            $this->sessionStore->create($session);
        }
        $this->session = $session;
        if ($securityContext !== null) {
            $runtimeContext = $securityContext->toRuntimeContext();
            if ($runtimeContext->sessionId !== null && $runtimeContext->sessionId !== $session->sessionId) {
                throw new \InvalidArgumentException('SecurityContext session_id does not match the opened Session');
            }
            $runtimeContext->runtimeId = $this->runtimeId;
            $runtimeContext->sessionId = $session->sessionId;
            $runtimeContext->agentId = $agentId ?? $runtimeContext->agentId;
            $runtimeContext->userId = $userId ?? $runtimeContext->userId;
            $this->context = $runtimeContext;
        } else {
            $this->context->sessionId = $session->sessionId;
            $this->context->agentId = $agentId;
            $this->context->userId = $userId;
        }
        $this->inbox->restore($session);
        $this->goals->restore($session);
        $this->budget->restore($session);
        $this->jobs->restore($session);
        $this->jobs->markInterrupted();
        return Session::fromDict($session->toDict());
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function checkpoint(?string $label = null, ?array $metadata = null): SessionCheckpoint
    {
        $session = $this->requireSession();
        $checkpoint = $session->checkpoint($label, $metadata);
        $this->sessionStore->save($session);
        return $checkpoint;
    }

    public function fork(?int $throughSequence = null, ?array $metadata = null): Session
    {
        $forked = $this->requireSession()->fork(throughSequence: $throughSequence, metadata: $metadata);
        $this->sessionStore->create($forked);
        return $forked;
    }

    /**
     * Full state snapshot (runtime.py snapshot).
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $session = $this->requireSession();
        $jobs = [];
        foreach ($this->jobs->list() as $job) {
            $jobs[] = $job->toDict();
        }
        $goals = [];
        foreach ($this->goals->list() as $goal) {
            $goals[] = $goal->toDict();
        }
        $inbox = [];
        foreach ($this->inbox->list() as $message) {
            $inbox[] = $message->toDict();
        }
        return [
            'runtime_id' => $this->runtimeId,
            'session' => $session->toDict(),
            'replay' => $session->replay()->toDict(),
            'inbox' => $inbox,
            'goals' => $goals,
            'budget' => [
                'limits' => $this->budget->limits->toDict(),
                'usage' => $this->budget->usage->toDict(),
                'remaining' => $this->budget->remaining(),
            ],
            'jobs' => $jobs,
            'progress' => $this->progress->state->toDict(),
        ];
    }

    public function pause(?string $reason = null): void
    {
        $this->append('session.paused', ['reason' => $reason]);
    }

    public function resume(?string $reason = null): void
    {
        $this->append('session.resumed', ['reason' => $reason]);
    }

    public function cancel(?string $reason = null): void
    {
        $this->append('session.cancelled', ['reason' => $reason]);
    }

    public function continueRun(?string $reason = null): void
    {
        $this->append('session.continued', ['reason' => $reason]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function append(string $eventType, array $data): void
    {
        $session = $this->requireSession();
        $session->append(
            $eventType,
            $data,
            turnId: $this->context->turnId,
            runId: $this->context->runId,
            agentId: $this->context->agentId,
        );
        $this->sessionStore->save($session);
    }

    /**
     * Persist a budget event into the open session.
     *
     * Unlike the other managers the budget must never break a run: it is
     * charged from inside the agent loop (before a model request, before a
     * tool call), and a budget that is consumed before a session was
     * opened has nowhere to persist to anyway. The event is therefore
     * dropped instead of raising - the same tolerance the agent applies to
     * its own session events.
     *
     * @param array<string, mixed> $data
     */
    private function appendBudgetEvent(string $eventType, array $data): void
    {
        if ($this->session === null) {
            return;
        }
        try {
            $this->append($eventType, $data);
        } catch (\Throwable) {
            // A failing session store must not abort the run that is being
            // charged against the budget.
        }
    }

    private function requireSession(): Session
    {
        if ($this->session === null) {
            throw new \RuntimeException('open_session() must be called first');
        }
        return $this->session;
    }
}
