<?php

declare(strict_types=1);

namespace LightAgent\Llm;

/**
 * Immutable value object mirroring an OpenAI ChatCompletion response message.
 */
final class ChatMessage
{
    /**
     * @param list<array<string, mixed>>|null $toolCalls
     */
    public function __construct(
        public readonly ?string $role = null,
        public readonly ?string $content = null,
        public readonly ?string $reasoningContent = null,
        public readonly ?array $toolCalls = null,
    ) {
    }
}
