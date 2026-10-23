<?php

declare(strict_types=1);

namespace LightAgent\Session;

use LightAgent\Support\Json;
/**
 * Deterministic message trimming with optional summary generation
 * (session.py ContextCompactor).
 *
 * Two invariants hold for every result this class returns:
 *
 *   1. `count($result->messages) <= $maxMessages`. The SYSTEM messages are
 *      returned ON TOP of the kept conversation messages, so they count
 *      against the same budget; before, they were added for free and a
 *      compaction could return MORE messages than it was asked for (M-25).
 *   2. `compactToBudget()` returns a result that FITS the budget, or it
 *      fails explicitly — it never silently hands back an over-budget
 *      context.
 */
final class ContextCompactor
{
    public function __construct(
        public readonly ?\Closure $summarizer = null,
        public readonly int $maxInlineToolChars = 12000,
    ) {
    }

    /**
     * @param iterable<array<string, mixed>> $messages
     */
    public function compact(iterable $messages, int $maxMessages = 20): CompactionResult
    {
        $values = is_array($messages) ? array_values($messages) : iterator_to_array($messages);
        [$values, $spilled] = $this->spillToolResults($values);
        if ($maxMessages < 2) {
            throw new \InvalidArgumentException('max_messages must be at least 2');
        }
        if (count($values) <= $maxMessages) {
            return new CompactionResult(messages: $values, removedCount: 0, spilled: $spilled);
        }
        [$system, $kept, $removed] = $this->partition($values, $maxMessages, $this->summarizer !== null);
        $summary = null;
        if ($this->summarizer !== null && $removed !== []) {
            // ONE summariser call per compaction.
            $summary = ($this->summarizer)(Json::decode($removed));
        }
        return $this->build($system, $kept, $removed, $summary, $spilled);
    }

    /**
     * Compact the messages until they fit the budget.
     *
     * The summariser runs at most ONCE for the whole compaction: the
     * previous implementation called `compact()` in a loop and every
     * iteration summarised the dropped messages again (38 summariser
     * calls in a single compaction).
     *
     * @param list<array<string, mixed>> $messages
     * @throws \RuntimeException when even the smallest compaction does not
     *         fit the budget — the caller must know that the context does
     *         not fit instead of receiving an over-budget history.
     */
    public function compactToBudget(array $messages, ContextBudget $budget): CompactionResult
    {
        $values = array_values($messages);
        [$prepared, $spilled] = $this->spillToolResults($values);
        if ($budget->fits($prepared)) {
            return new CompactionResult(messages: $prepared, removedCount: 0, spilled: $spilled);
        }

        // Phase 1 — shrink until the result fits. The summary is NOT
        // produced here: it is added in phase 2, once, so the summariser
        // runs a single time.
        $maxMessages = max(2, count($prepared));
        $fit = null;
        while ($maxMessages >= 2) {
            [$system, $kept, $removed] = $this->partition($prepared, $maxMessages, $this->summarizer !== null);
            $candidate = $this->build($system, $kept, $removed, null, $spilled);
            if ($budget->fits($candidate->messages)) {
                $fit = [$system, $kept, $removed];
                break;
            }
            $maxMessages--;
        }
        if ($fit === null) {
            throw new \RuntimeException(sprintf(
                'the context does not fit the budget of %d tokens (reserved output: %d); '
                . 'even the smallest compaction is too large',
                $budget->maxTokens,
                $budget->reservedOutputTokens,
            ));
        }

        [$system, $kept, $removed] = $fit;
        $summary = null;
        if ($this->summarizer !== null && $removed !== []) {
            $summary = ($this->summarizer)(Json::decode($removed));
        }
        if ($summary === null || $summary === '') {
            $result = $this->build($system, $kept, $removed, null, $spilled);
            if (!$budget->fits($result->messages)) {
                throw new \RuntimeException('the compacted context does not fit the budget');
            }
            return $result;
        }

        // The summary is ONE more message: drop the oldest kept messages
        // until the result fits the budget again. The budget is a hard
        // limit, the summary is an optimisation.
        while ($kept !== [] && !$budget->fits($this->build($system, $kept, $removed, $summary, $spilled)->messages)) {
            array_shift($kept);
        }
        $result = $this->build($system, $kept, $removed, $summary, $spilled);
        if (!$budget->fits($result->messages)) {
            // The summary itself does not fit: drop it and keep the
            // messages that DO fit.
            $result = $this->build($system, $kept, $removed, null, $spilled);
        }
        if (!$budget->fits($result->messages)) {
            throw new \RuntimeException('the compacted context does not fit the budget');
        }
        return $result;
    }

    /**
     * Split the messages into the system block, the kept tail and the
     * removed head.
     *
     * The SYSTEM messages are returned on top of the kept conversation, so
     * they are capped: `systemSlots` never exceeds `slots - 1`, which
     * guarantees that at least one conversation message survives and that
     * the result never exceeds `maxMessages` (M-25). The FIRST system
     * messages are kept — they carry the primary instructions.
     *
     * @param list<array<string, mixed>> $values
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    private function partition(array $values, int $maxMessages, bool $reserveSummary): array
    {
        $system = [];
        $nonSystem = [];
        foreach ($values as $message) {
            if (($message['role'] ?? null) === 'system') {
                $system[] = $message;
            } else {
                $nonSystem[] = $message;
            }
        }
        // The summary message occupies one slot of the result.
        $slots = max(1, $maxMessages - ($reserveSummary ? 1 : 0));
        $systemSlots = min(count($system), max(0, $slots - 1));
        $nonSystemSlots = max(1, $slots - $systemSlots);
        $keptSystem = array_slice($system, 0, $systemSlots);
        $kept = array_slice($nonSystem, -$nonSystemSlots);
        $removed = array_slice($nonSystem, 0, count($nonSystem) - $nonSystemSlots);
        // A tool message must not be the first kept message: it needs the
        // assistant message that declares its tool call.
        while ($kept !== [] && (($kept[0]['role'] ?? null) === 'tool')) {
            $removed[] = array_shift($kept);
        }
        return [$keptSystem, $kept, $removed];
    }

    /**
     * Assemble the result.
     *
     * @param list<array<string, mixed>> $system
     * @param list<array<string, mixed>> $kept
     * @param list<array<string, mixed>> $removed
     * @param list<array<string, mixed>> $spilled
     */
    private function build(
        array $system,
        array $kept,
        array $removed,
        ?string $summary,
        array $spilled,
    ): CompactionResult {
        $messages = $system;
        if ($summary !== null && $summary !== '') {
            $messages[] = [
                'role' => 'system',
                'content' => "Previous conversation summary:\n{$summary}",
                'metadata' => ['compacted' => true, 'removed_count' => count($removed)],
            ];
        }
        $messages = array_merge($messages, $kept);
        return new CompactionResult(
            messages: $messages,
            removedCount: count($removed),
            summary: $summary,
            spilled: $spilled,
        );
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function spillToolResults(array $messages): array
    {
        $prepared = [];
        $spilled = [];
        foreach ($messages as $message) {
            $value = Json::decode($message);
            $content = $value['content'] ?? null;
            if (
                ($value['role'] ?? null) === 'tool'
                && is_string($content)
                && mb_strlen($content) > $this->maxInlineToolChars
            ) {
                $digest = hash('sha256', $content);
                $reference = "tool-result:sha256:{$digest}";
                $spilled[] = [
                    'reference' => $reference,
                    'sha256' => $digest,
                    'size' => mb_strlen($content),
                    'content' => $content,
                ];
                $value['content'] = "[Tool result stored outside context: {$reference}, " . mb_strlen($content) . ' chars]';
                $value['metadata']['content_reference'] = $reference;
            }
            $prepared[] = $value;
        }
        return [$prepared, $spilled];
    }
}
