<?php

declare(strict_types=1);

namespace LightAgent\Llm;

/**
 * Non-streaming chat completion response (subset used by LightAgent).
 */
final class ChatCompletion
{
    /**
     * @param list<array{index?: int, message: ChatMessage, finish_reason: ?string}> $choices
     * @param array<string, mixed>|null $usage
     */
    public function __construct(
        public readonly array $choices,
        public readonly ?array $usage = null,
        public readonly ?string $id = null,
    ) {
    }
}
