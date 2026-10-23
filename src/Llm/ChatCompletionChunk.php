<?php

declare(strict_types=1);

namespace LightAgent\Llm;

/**
 * One streaming chunk (OpenAI ChatCompletionChunk subset).
 */
final class ChatCompletionChunk
{
    /**
     * @param list<array{index: int, delta: array<string, mixed>, finish_reason: ?string}> $choices
     * @param array<string, mixed>|null $usage
     */
    public function __construct(
        public readonly array $choices,
        public readonly ?array $usage = null,
        public readonly ?string $id = null,
    ) {
    }
}
