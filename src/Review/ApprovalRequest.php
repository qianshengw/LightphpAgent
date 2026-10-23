<?php

declare(strict_types=1);

namespace LightAgent\Review;

/**
 * Serializable request sent to a human review system (review.py ApprovalRequest).
 */
final class ApprovalRequest
{
    public const ACTION_PENDING = ApprovalConstants::PENDING;
    public const ACTION_APPROVE = ApprovalConstants::APPROVE;
    public const ACTION_REJECT = ApprovalConstants::REJECT;
    public const ACTION_EDIT = ApprovalConstants::EDIT;
    public const ACTION_RESPOND = ApprovalConstants::RESPOND;

    /**
     * @param array<string, mixed> $arguments
     * @param list<string> $allowedDecisions
     * @param array<string, mixed> $reviewerMetadata
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $action,
        public string $requestId = '',
        public readonly ?string $runId = null,
        public readonly ?string $traceId = null,
        public readonly ?string $actionName = null,
        public readonly ?string $toolName = null,
        public array $arguments = [],
        public readonly ?string $sourceAgent = null,
        public readonly ?string $targetAgent = null,
        public array $allowedDecisions = [self::ACTION_APPROVE, self::ACTION_REJECT, self::ACTION_EDIT],
        public array $reviewerMetadata = [],
        public array $metadata = [],
        public string $createdAt = '',
    ) {
        if ($this->requestId === '') {
            $this->requestId = bin2hex(random_bytes(16));
        }
        if ($this->createdAt === '') {
            $this->createdAt = ApprovalConstants::now();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'action' => $this->action,
            'request_id' => $this->requestId,
            'run_id' => $this->runId,
            'trace_id' => $this->traceId,
            'action_name' => $this->actionName,
            'tool_name' => $this->toolName,
            'arguments' => $this->arguments,
            'source_agent' => $this->sourceAgent,
            'target_agent' => $this->targetAgent,
            'allowed_decisions' => $this->allowedDecisions,
            'reviewer_metadata' => $this->reviewerMetadata,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt,
        ];
    }
}
