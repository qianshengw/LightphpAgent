<?php

declare(strict_types=1);

namespace LightAgent\Runtime;

use LightAgent\Session\SessionConstants;

/**
 * One Inbox message (runtime.py InboxMessage).
 */
final class InboxMessage
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public InboxMessageType $type,
        public mixed $content = null,
        public string $messageId = '',
        public InboxMessageStatus $status = InboxMessageStatus::PENDING,
        public string $createdAt = '',
        public ?string $claimedAt = null,
        public ?string $completedAt = null,
        public ?string $correlationId = null,
        public array $metadata = [],
    ) {
        if ($this->messageId === '') {
            $this->messageId = bin2hex(random_bytes(16));
        }
        if ($this->createdAt === '') {
            $this->createdAt = SessionConstants::utcNow();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'type' => $this->type->value,
            'content' => $this->content,
            'message_id' => $this->messageId,
            'status' => $this->status->value,
            'created_at' => $this->createdAt,
            'claimed_at' => $this->claimedAt,
            'completed_at' => $this->completedAt,
            'correlation_id' => $this->correlationId,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromDict(array $value): self
    {
        return new self(
            type: InboxMessageType::from((string) $value['type']),
            content: $value['content'] ?? null,
            messageId: (string) ($value['message_id'] ?? '') ?: bin2hex(random_bytes(16)),
            status: InboxMessageStatus::from((string) ($value['status'] ?? 'pending')),
            createdAt: (string) ($value['created_at'] ?? '') ?: SessionConstants::utcNow(),
            claimedAt: isset($value['claimed_at']) ? (string) $value['claimed_at'] : null,
            completedAt: isset($value['completed_at']) ? (string) $value['completed_at'] : null,
            correlationId: isset($value['correlation_id']) ? (string) $value['correlation_id'] : null,
            metadata: is_array($value['metadata'] ?? null) ? $value['metadata'] : [],
        );
    }
}
