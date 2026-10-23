<?php

declare(strict_types=1);

namespace LightAgent\Runtime;

use LightAgent\Session\Session;

/**
 * Goal lifecycle manager (port of runtime.py GoalManager).
 */
final class GoalManager
{
    /** @var array<string, Goal> */
    private array $goals = [];

    public function __construct(
        public readonly ?\Closure $eventSink = null,
    ) {
    }

    /**
     * @param list<string>|null $acceptanceCriteria
     * @param array<string, mixed>|null $metadata
     */
    public function create(
        string $objective,
        ?array $acceptanceCriteria = null,
        ?string $parentGoalId = null,
        ?array $metadata = null,
    ): Goal {
        if (trim($objective) === '') {
            throw new \InvalidArgumentException('goal objective must not be empty');
        }
        if ($parentGoalId !== null && !isset($this->goals[$parentGoalId])) {
            throw new \RuntimeException($parentGoalId);
        }
        $goal = new Goal(
            objective: $objective,
            acceptanceCriteria: $acceptanceCriteria ?? [],
            parentGoalId: $parentGoalId,
            metadata: $metadata ?? [],
        );
        $this->goals[$goal->goalId] = $goal;
        $this->emit('goal.created', $goal);
        return $goal;
    }

    public function activate(string $goalId): Goal
    {
        return $this->transition($goalId, GoalStatus::ACTIVE);
    }

    /**
     * @param list<mixed>|null $evidence
     */
    public function complete(string $goalId, ?array $evidence = null): Goal
    {
        $goal = $this->require($goalId);
        // V5-42: the transition is validated FIRST. The evidence and the
        // blocker were written before the transition was checked, so a
        // REJECTED transition still left the evidence behind on the goal.
        $this->assertTransitionAllowed($goal, GoalStatus::COMPLETED);
        foreach ($evidence ?? [] as $item) {
            $goal->evidence[] = $item;
        }
        $goal->blocker = null;
        return $this->transition($goalId, GoalStatus::COMPLETED, eventType: 'goal.completed');
    }

    public function block(string $goalId, string $reason): Goal
    {
        $goal = $this->require($goalId);
        $goal->blocker = $reason;
        return $this->transition($goalId, GoalStatus::BLOCKED, eventType: 'goal.blocked');
    }

    public function cancel(string $goalId, ?string $reason = null): Goal
    {
        $goal = $this->require($goalId);
        $goal->blocker = $reason;
        return $this->transition($goalId, GoalStatus::CANCELLED, eventType: 'goal.cancelled');
    }

    public function get(string $goalId): Goal
    {
        return $this->require($goalId);
    }

    /**
     * @return list<Goal>
     */
    public function list(GoalStatus|string|null $status = null): array
    {
        $resolved = $status !== null
            ? ($status instanceof GoalStatus ? $status : GoalStatus::from((string) $status))
            : null;
        $out = [];
        foreach ($this->goals as $goal) {
            if ($resolved === null || $goal->status === $resolved) {
                $out[] = $goal;
            }
        }
        return $out;
    }

    public function restore(Session $session): void
    {
        $byId = [];
        foreach ($session->events as $event) {
            if (!str_starts_with($event->type, 'goal.')) {
                continue;
            }
            $value = $event->data['goal'] ?? null;
            if (is_array($value)) {
                $goal = Goal::fromDict($value);
                $byId[$goal->goalId] = $goal;
            }
        }
        $this->goals = $byId;
    }

    private function transition(string $goalId, GoalStatus $status, string $eventType = 'goal.updated'): Goal
    {
        $goal = $this->require($goalId);
        $this->assertTransitionAllowed($goal, $status);
        $goal->status = $status;
        $goal->updatedAt = \LightAgent\Session\SessionConstants::utcNow();
        $this->emit($eventType, $goal);
        return $goal;
    }

    /**
     * Reject an illegal state transition.
     *
     * Split out of `transition()` so a caller that mutates the goal
     * BEFORE the transition (e.g. `complete()` with its evidence) can
     * validate first and leave the goal untouched when the transition is
     * rejected (V5-42).
     */
    private function assertTransitionAllowed(Goal $goal, GoalStatus $status): void
    {
        if (
            in_array($goal->status, [GoalStatus::COMPLETED, GoalStatus::CANCELLED], true)
            && $goal->status !== $status
        ) {
            throw new \InvalidArgumentException(
                "terminal goal `{$goal->goalId}` cannot transition from {$goal->status->value}",
            );
        }
    }

    private function require(string $goalId): Goal
    {
        if (!isset($this->goals[$goalId])) {
            throw new \RuntimeException($goalId);
        }
        return $this->goals[$goalId];
    }

    private function emit(string $eventType, Goal $goal): void
    {
        if ($this->eventSink !== null) {
            ($this->eventSink)($eventType, ['goal' => $goal->toDict()]);
        }
    }
}
