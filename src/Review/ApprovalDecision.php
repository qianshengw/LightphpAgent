<?php

declare(strict_types=1);

namespace LightAgent\Review;

/**
 * Review decision for an ApprovalRequest (review.py ApprovalDecision).
 */
final class ApprovalDecision
{
    public string $requestId = '';
    public string $decidedAt = '';

    /**
     * @param array<string, mixed>|null $arguments
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $action,
        ?string $requestId = null,
        public readonly ?string $reason = null,
        public readonly ?array $arguments = null,
        public readonly ?string $response = null,
        public readonly ?string $reviewerId = null,
        public array $metadata = [],
        ?string $decidedAt = null,
    ) {
        if (!in_array($this->action, ApprovalConstants::ACTIONS, true)) {
            throw new \InvalidArgumentException("unsupported approval action: {$this->action}");
        }
        if ($requestId !== null) {
            $this->requestId = $requestId;
        }
        $this->decidedAt = $decidedAt ?? ApprovalConstants::now();
    }

    public function isApproved(): bool
    {
        return in_array($this->action, [
            ApprovalConstants::APPROVE,
            ApprovalConstants::EDIT,
            ApprovalConstants::RESPOND,
        ], true);
    }

    /**
     * @param array<string, mixed> $kwargs
     */
    public static function approve(array $kwargs = []): self
    {
        return self::construct(action: ApprovalConstants::APPROVE, kwargs: $kwargs);
    }

    /**
     * @param array<string, mixed> $kwargs
     */
    public static function reject(?string $reason = null, array $kwargs = []): self
    {
        if ($reason !== null) {
            $kwargs['reason'] = $reason;
        }
        return self::construct(action: ApprovalConstants::REJECT, kwargs: $kwargs);
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $kwargs
     */
    public static function edit(array $arguments, array $kwargs = []): self
    {
        $kwargs['arguments'] = $arguments;
        return self::construct(action: ApprovalConstants::EDIT, kwargs: $kwargs);
    }

    /**
     * @param array<string, mixed> $kwargs
     */
    public static function respond(string $response, array $kwargs = []): self
    {
        $kwargs['response'] = $response;
        return self::construct(action: ApprovalConstants::RESPOND, kwargs: $kwargs);
    }

    /**
     * @param array<string, mixed> $kwargs
     */
    public static function pending(?string $reason = null, array $kwargs = []): self
    {
        if ($reason !== null) {
            $kwargs['reason'] = $reason;
        }
        return self::construct(action: ApprovalConstants::PENDING, kwargs: $kwargs);
    }

    /**
     * Array-driven constructor bridging Python **kwargs factory style.
     *
     * @param array<string, mixed> $kwargs
     */
    private static function construct(string $action, array $kwargs): self
    {
        return new self(
            action: $action,
            requestId: isset($kwargs['request_id']) ? (string) $kwargs['request_id'] : (isset($kwargs['requestId']) ? (string) $kwargs['requestId'] : null),
            reason: isset($kwargs['reason']) ? (string) $kwargs['reason'] : null,
            arguments: is_array($kwargs['arguments'] ?? null) ? $kwargs['arguments'] : null,
            response: isset($kwargs['response']) ? (string) $kwargs['response'] : null,
            reviewerId: isset($kwargs['reviewer_id']) ? (string) $kwargs['reviewer_id'] : (isset($kwargs['reviewerId']) ? (string) $kwargs['reviewerId'] : null),
            metadata: is_array($kwargs['metadata'] ?? null) ? $kwargs['metadata'] : [],
            decidedAt: isset($kwargs['decided_at']) ? (string) $kwargs['decided_at'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'action' => $this->action,
            'request_id' => $this->requestId,
            'reason' => $this->reason,
            'arguments' => $this->arguments,
            'response' => $this->response,
            'reviewer_id' => $this->reviewerId,
            'metadata' => $this->metadata,
            'decided_at' => $this->decidedAt,
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromDict(array $value): self
    {
        return new self(
            action: (string) $value['action'],
            requestId: isset($value['request_id']) ? (string) $value['request_id'] : null,
            reason: isset($value['reason']) ? (string) $value['reason'] : null,
            arguments: is_array($value['arguments'] ?? null) ? $value['arguments'] : null,
            response: isset($value['response']) ? (string) $value['response'] : null,
            reviewerId: isset($value['reviewer_id']) ? (string) $value['reviewer_id'] : null,
            metadata: is_array($value['metadata'] ?? null) ? $value['metadata'] : [],
            decidedAt: isset($value['decided_at']) ? (string) $value['decided_at'] : null,
        );
    }
}
