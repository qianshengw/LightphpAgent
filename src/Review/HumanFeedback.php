<?php

declare(strict_types=1);

namespace LightAgent\Review;

/**
 * Human-on-the-loop annotation attached to a trace (review.py HumanFeedback).
 */
final class HumanFeedback
{
    public string $feedbackId = '';

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $traceId,
        public readonly ?float $rating = null,
        public readonly ?string $label = null,
        public readonly ?string $comment = null,
        public readonly ?string $reviewerId = null,
        public array $metadata = [],
        ?string $feedbackId = null,
        public readonly string $createdAt = '',
    ) {
        $this->feedbackId = $feedbackId ?? bin2hex(random_bytes(16));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'trace_id' => $this->traceId,
            'rating' => $this->rating,
            'label' => $this->label,
            'comment' => $this->comment,
            'reviewer_id' => $this->reviewerId,
            'metadata' => $this->metadata,
            'feedback_id' => $this->feedbackId,
            'created_at' => $this->createdAt !== '' ? $this->createdAt : ApprovalConstants::now(),
        ];
    }
}
