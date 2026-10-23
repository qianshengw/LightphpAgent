<?php

declare(strict_types=1);

namespace LightAgent\Dag;

/**
 * Task outcome (dag/models.py TaskOutcome, frozen).
 *
 * @param array<string, mixed> $metadata
 */
final class TaskOutcome
{
    public function __construct(
        public readonly TaskOutcomeKind $kind,
        public readonly mixed $content = null,
        public readonly string $mediaType = 'text/plain',
        public readonly ?DecompositionProposal $decomposition = null,
        public readonly ?string $blocker = null,
        public array $metadata = [],
    ) {
        if ($this->kind === TaskOutcomeKind::CANDIDATE && $this->decomposition !== null) {
            throw new \InvalidArgumentException('candidate outcome cannot include a decomposition');
        }
        if ($this->kind === TaskOutcomeKind::DECOMPOSITION && $this->decomposition === null) {
            throw new \InvalidArgumentException('decomposition outcome requires a proposal');
        }
        if ($this->kind === TaskOutcomeKind::BLOCKED && ($this->blocker === null || $this->blocker === '')) {
            throw new \InvalidArgumentException('blocked outcome requires a reason');
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function candidate(mixed $content, string $mediaType = 'text/plain', array $metadata = []): self
    {
        return new self(TaskOutcomeKind::CANDIDATE, content: $content, mediaType: $mediaType, metadata: $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function decompose(DecompositionProposal $proposal, array $metadata = []): self
    {
        return new self(TaskOutcomeKind::DECOMPOSITION, decomposition: $proposal, metadata: $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function blocked(string $reason, array $metadata = []): self
    {
        return new self(TaskOutcomeKind::BLOCKED, blocker: $reason, metadata: $metadata);
    }
}
