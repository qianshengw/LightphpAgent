<?php

declare(strict_types=1);

namespace LightAgent\Llm;

/**
 * Utility for incrementally assembling OpenAI tool_calls from stream deltas
 * (port of core.py _run_stream_logic tool_calls accumulation logic).
 */
final class ToolCallAssembler
{
    /** @var array<int, array{name: string, arguments: string, index: int, title: string, id: string}> */
    private array $toolCalls = [];

    /**
     * Consume one chunk's delta.tool_calls payload (already decoded JSON).
     *
     * OpenAI may batch several parallel tool calls inside ONE delta
     * (delta.tool_calls[N]); every entry is consumed, not just [0].
     *
     * @param array<string, mixed> $chunkChoices0 the first choice object
     */
    public function consumeChunk(array $chunkChoices0): void
    {
        $delta = $chunkChoices0['delta'] ?? [];
        $deltas = $delta['tool_calls'] ?? null;
        if (!is_array($deltas) || $deltas === []) {
            return;
        }

        foreach ($deltas as $toolCallDelta) {
            if (!is_array($toolCallDelta)) {
                continue;
            }

            $index = null;
            if (isset($toolCallDelta['index']) && $toolCallDelta['index'] !== null) {
                $index = (int) $toolCallDelta['index'];
            } else {
                // The provider did not declare an index. Defaulting to 0
                // merged PARALLEL tool calls into a single garbled one: the
                // second call's name overwrote the first and their argument
                // fragments were concatenated into invalid JSON. Allocating
                // the next free slot keeps them apart. A single index-less
                // call still lands in slot 0, so the common case is
                // unchanged.
                $index = $this->nextFreeIndex();
            }

            // Key slots by the model-declared index so out-of-order arrival
            // (index 1 before index 0) still lands in the right place.
            if (!isset($this->toolCalls[$index])) {
                $this->toolCalls[$index] = ['name' => '', 'arguments' => '', 'index' => $index, 'title' => '', 'id' => ''];
            }

            if (!empty($toolCallDelta['id'])) {
                $this->toolCalls[$index]['id'] = (string) $toolCallDelta['id'];
            }

            $function = $toolCallDelta['function'] ?? [];
            if (is_array($function) && !empty($function['name'])) {
                $this->toolCalls[$index]['name'] = (string) $function['name'];
            }
            if (is_array($function) && !empty($function['arguments'])) {
                $this->toolCalls[$index]['arguments'] .= (string) $function['arguments'];
            }
        }
    }

    /**
     * Assembled tool calls ordered by the model-declared index.
     *
     * @return list<array{name: string, arguments: string, index: int, title: string, id: string}>
     */
    public function getToolCalls(): array
    {
        $calls = $this->toolCalls;
        ksort($calls);
        return array_values($calls);
    }

    /**
     * Position of a tool call inside getToolCalls() output (identity match).
     *
     * @param array<string, mixed> $toolCall one entry returned by getToolCalls()
     */
    public function indexForToolCall(array $toolCall): int
    {
        $ordered = $this->getToolCalls();
        foreach ($ordered as $i => $tc) {
            if ($tc === $toolCall) {
                return $i;
            }
        }
        return (int) ($toolCall['index'] ?? 0);
    }

    public function hasNamedToolCalls(): bool
    {
        foreach ($this->toolCalls as $tc) {
            if ($tc['name'] !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * The lowest slot index that is not in use yet.
     */
    private function nextFreeIndex(): int
    {
        $index = 0;
        while (isset($this->toolCalls[$index])) {
            $index++;
        }
        return $index;
    }
}
