<?php

declare(strict_types=1);

namespace LightAgent\Runtime;

/**
 * Goal enums + Goal record (runtime.py GoalStatus / Goal).
 */
enum GoalStatus: string
{
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case COMPLETED = 'completed';
    case BLOCKED = 'blocked';
    case CANCELLED = 'cancelled';
}

final class Goal
{
    /**
     * @param list<string> $acceptanceCriteria
     * @param list<mixed> $evidence
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $objective,
        public string $goalId = '',
        public GoalStatus $status = GoalStatus::PENDING,
        public array $acceptanceCriteria = [],
        public ?string $parentGoalId = null,
        public array $evidence = [],
        public ?string $blocker = null,
        public string $createdAt = '',
        public string $updatedAt = '',
        public array $metadata = [],
    ) {
        if ($this->goalId === '') {
            $this->goalId = bin2hex(random_bytes(16));
        }
        $now = \LightAgent\Session\SessionConstants::utcNow();
        if ($this->createdAt === '') {
            $this->createdAt = $now;
        }
        if ($this->updatedAt === '') {
            $this->updatedAt = $now;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'objective' => $this->objective,
            'goal_id' => $this->goalId,
            'status' => $this->status->value,
            'acceptance_criteria' => $this->acceptanceCriteria,
            'parent_goal_id' => $this->parentGoalId,
            'evidence' => $this->evidence,
            'blocker' => $this->blocker,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromDict(array $value): self
    {
        return new self(
            objective: (string) $value['objective'],
            goalId: (string) ($value['goal_id'] ?? '') ?: bin2hex(random_bytes(16)),
            status: GoalStatus::from((string) ($value['status'] ?? 'pending')),
            acceptanceCriteria: is_array($value['acceptance_criteria'] ?? null) ? $value['acceptance_criteria'] : [],
            parentGoalId: isset($value['parent_goal_id']) ? (string) $value['parent_goal_id'] : null,
            evidence: is_array($value['evidence'] ?? null) ? $value['evidence'] : [],
            blocker: isset($value['blocker']) ? (string) $value['blocker'] : null,
            createdAt: (string) ($value['created_at'] ?? '') ?: \LightAgent\Session\SessionConstants::utcNow(),
            updatedAt: (string) ($value['updated_at'] ?? '') ?: \LightAgent\Session\SessionConstants::utcNow(),
            metadata: is_array($value['metadata'] ?? null) ? $value['metadata'] : [],
        );
    }
}
