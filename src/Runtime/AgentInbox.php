<?php

declare(strict_types=1);

namespace LightAgent\Runtime;

use LightAgent\Session\Session;

/**
 * Ordered, idempotent Inbox whose mutations are Session events
 * (port of runtime.py AgentInbox).
 */
final class AgentInbox
{
    /** @var list<InboxMessage> */
    private array $messages = [];

    public function __construct(
        public readonly ?\Closure $eventSink = null,
    ) {
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function enqueue(
        InboxMessageType|string $messageType,
        mixed $content,
        ?string $messageId = null,
        ?string $correlationId = null,
        ?array $metadata = null,
    ): InboxMessage {
        $resolvedId = $messageId ?? bin2hex(random_bytes(16));
        $existing = $this->get($resolvedId);
        if ($existing !== null) {
            return $existing;
        }
        $message = new InboxMessage(
            type: $messageType instanceof InboxMessageType ? $messageType : InboxMessageType::from((string) $messageType),
            content: $content,
            messageId: $resolvedId,
            correlationId: $correlationId,
            metadata: $metadata ?? [],
        );
        $this->messages[] = $message;
        $this->emit('inbox.enqueued', ['message' => $message->toDict()]);
        return $message;
    }

    /**
     * @return list<InboxMessage>
     */
    public function pending(InboxMessageType|string|null $messageType = null): array
    {
        $resolvedType = $messageType !== null
            ? ($messageType instanceof InboxMessageType ? $messageType : InboxMessageType::from((string) $messageType))
            : null;
        $out = [];
        foreach ($this->messages as $message) {
            if (
                $message->status === InboxMessageStatus::PENDING
                && ($resolvedType === null || $message->type === $resolvedType)
            ) {
                $out[] = $message;
            }
        }
        return $out;
    }

    /**
     * Claim the next pending message.
     *
     * `$safeBoundary` decides whether a STEERING message may be handed
     * out. A steering message interrupts the agent MID-TURN, so the safe
     * default (`true`) does NOT deliver one: the queue has no notion of
     * turn state, so a steering message must only be picked up at a turn
     * boundary. Passing `false` is the explicit opt-in for the unsafe
     * behaviour (M-36).
     */
    public function claimNext(bool $safeBoundary = true): ?InboxMessage
    {
        foreach ($this->messages as $message) {
            if ($message->status !== InboxMessageStatus::PENDING) {
                continue;
            }
            if ($message->type === InboxMessageType::STEERING && $safeBoundary) {
                // Not a safe boundary: a steering message would interrupt
                // the running turn.
                continue;
            }
            $message->status = InboxMessageStatus::CLAIMED;
            $message->claimedAt = \LightAgent\Session\SessionConstants::utcNow();
            $this->emit('inbox.claimed', ['message' => $message->toDict()]);
            return $message;
        }
        return null;
    }

    public function complete(string $messageId, mixed $result = null): InboxMessage
    {
        $message = $this->require($messageId);
        if ($message->status === InboxMessageStatus::COMPLETED) {
            return $message;
        }
        if ($message->status !== InboxMessageStatus::CLAIMED) {
            throw new \InvalidArgumentException('only claimed Inbox messages can be completed');
        }
        $message->status = InboxMessageStatus::COMPLETED;
        $message->completedAt = \LightAgent\Session\SessionConstants::utcNow();
        $this->emit('inbox.completed', ['message' => $message->toDict(), 'result' => $result]);
        return $message;
    }

    public function reject(string $messageId, string $reason): InboxMessage
    {
        $message = $this->require($messageId);
        $message->status = InboxMessageStatus::REJECTED;
        $message->completedAt = \LightAgent\Session\SessionConstants::utcNow();
        $this->emit('inbox.rejected', ['message' => $message->toDict(), 'reason' => $reason]);
        return $message;
    }

    public function get(string $messageId): ?InboxMessage
    {
        foreach ($this->messages as $message) {
            if ($message->messageId === $messageId) {
                return $message;
            }
        }
        return null;
    }

    /**
     * @return list<InboxMessage>
     */
    public function list(): array
    {
        return $this->messages;
    }

    public function restore(Session $session): void
    {
        $this->messages = [];
        $byId = [];
        foreach ($session->events as $event) {
            if (!str_starts_with($event->type, 'inbox.')) {
                continue;
            }
            $value = $event->data['message'] ?? null;
            if (!is_array($value)) {
                continue;
            }
            $message = InboxMessage::fromDict($value);
            $byId[$message->messageId] = $message;
        }
        usort($byId, static fn (InboxMessage $a, InboxMessage $b): int => strcmp($a->createdAt, $b->createdAt));
        $this->messages = array_values($byId);
    }

    private function require(string $messageId): InboxMessage
    {
        $message = $this->get($messageId);
        if ($message === null) {
            throw new \RuntimeException($messageId);
        }
        return $message;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function emit(string $eventType, array $data): void
    {
        if ($this->eventSink !== null) {
            ($this->eventSink)($eventType, $data);
        }
    }
}
