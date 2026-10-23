<?php

declare(strict_types=1);

namespace LightAgent\Llm;

/**
 * chat.completions namespace mirror.
 */
final class OpenAiCompletions
{
    public function __construct(
        private readonly OpenAiClient $client,
    ) {
    }

    /**
     * Create a chat completion (or stream of chunks).
     *
     * @param array<string, mixed> $params
     * @return ChatCompletion|iterable<ChatCompletionChunk>
     */
    public function create(array $params): ChatCompletion|iterable
    {
        $stream = (bool) ($params['stream'] ?? false);

        if (!$stream) {
            $decoded = $this->client->rawRequest($params);
            if (!is_array($decoded)) {
                throw new LlmException('unexpected non-array response');
            }
            return self::parseCompletion($decoded);
        }

        // Streaming: perform the HTTP request inside a fiber; yield chunks lazily.
        return new ChunkStream($this->client, $params);
    }

    /**
     * @param array<string, mixed> $decoded
     */
    public static function parseCompletion(array $decoded): ChatCompletion
    {
        $choices = [];
        foreach ($decoded['choices'] ?? [] as $choice) {
            $messageArr = $choice['message'] ?? [];
            $toolCalls = null;
            if (!empty($messageArr['tool_calls']) && is_array($messageArr['tool_calls'])) {
                $toolCalls = $messageArr['tool_calls'];
            }
            $choices[] = [
                'index' => $choice['index'] ?? 0,
                'message' => new ChatMessage(
                    role: $messageArr['role'] ?? 'assistant',
                    content: isset($messageArr['content']) && $messageArr['content'] !== null ? (string) $messageArr['content'] : null,
                    reasoningContent: isset($messageArr['reasoning_content']) && $messageArr['reasoning_content'] !== null ? (string) $messageArr['reasoning_content'] : null,
                    toolCalls: $toolCalls,
                ),
                'finish_reason' => $choice['finish_reason'] ?? null,
            ];
        }
        return new ChatCompletion(
            choices: $choices,
            usage: is_array($decoded['usage'] ?? null) ? $decoded['usage'] : null,
            id: isset($decoded['id']) ? (string) $decoded['id'] : null,
        );
    }
}

/**
 * Lazy chunk iterator over a streaming SSE body.
 */
final class ChunkStream implements \IteratorAggregate
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        private readonly OpenAiClient $client,
        private readonly array $params,
    ) {
    }

    public function getIterator(): \Generator
    {
        // streamRequest() yields each SSE payload as it arrives, so chunks are
        // surfaced progressively instead of after the whole body is buffered.
        foreach ($this->client->streamRequest($this->params) as $data) {
            $decoded = json_decode($data, true);
            if (!is_array($decoded)) {
                // A frame that is not valid JSON is a protocol violation, not
                // an empty answer. It used to be skipped in silence, so a
                // truncated payload looked exactly like "the provider had
                // nothing more to say". Count it so the loss is visible.
                $this->client->malformedStreamFrames++;
                continue;
            }

            $choices = [];
            foreach ($decoded['choices'] ?? [] as $choice) {
                $choices[] = [
                    'index' => $choice['index'] ?? 0,
                    'delta' => is_array($choice['delta'] ?? null) ? $choice['delta'] : [],
                    'finish_reason' => $choice['finish_reason'] ?? null,
                ];
            }
            yield new ChatCompletionChunk(
                choices: $choices,
                usage: is_array($decoded['usage'] ?? null) ? $decoded['usage'] : null,
                id: isset($decoded['id']) ? (string) $decoded['id'] : null,
            );
        }
    }
}

