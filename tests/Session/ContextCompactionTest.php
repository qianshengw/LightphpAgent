<?php

declare(strict_types=1);

namespace LightAgent\Tests\Session;

use LightAgent\Session\ContextBudget;
use LightAgent\Session\ContextCompactor;
use PHPUnit\Framework\TestCase;

/**
 * Context compaction tests.
 *
 * These used to live in `SessionTest.php`, where PHPUnit never collected
 * them: the file-based test discovery only registers a class whose name
 * matches the file name, so `ContextCompactorTest` inside
 * `SessionTest.php` was dead code.
 */
final class ContextCompactionTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private function makeMessages(int $n): array
    {
        $out = [['role' => 'system', 'content' => 'sys']];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = ['role' => $i % 2 === 0 ? 'user' : 'assistant', 'content' => "msg{$i}"];
        }
        return $out;
    }

    public function test_compact_keeps_system_and_tail(): void
    {
        $compactor = new ContextCompactor();
        $messages = $this->makeMessages(10);

        $result = $compactor->compact($messages, 4);
        self::assertSame(7, $result->removedCount);
        self::assertSame('system', $result->messages[0]['role']);
        self::assertCount(4, $result->messages);
        self::assertSame('msg10', $result->messages[3]['content']);
    }

    public function test_compact_with_summarizer(): void
    {
        $compactor = new ContextCompactor(
            summarizer: static fn (array $removed): string => 'summary of ' . count($removed) . ' messages',
        );
        $result = $compactor->compact($this->makeMessages(10), 4);

        self::assertNotNull($result->summary);
        // The summary message occupies one of the four slots, so eight of
        // the ten conversation messages are summarised.
        self::assertSame('summary of 8 messages', $result->summary);
        self::assertSame('system', $result->messages[1]['role']);
        self::assertStringContainsString('Previous conversation summary', $result->messages[1]['content']);
        self::assertCount(4, $result->messages);
    }

    public function test_spill_large_tool_results(): void
    {
        $compactor = new ContextCompactor(maxInlineToolChars: 100);
        $bigContent = str_repeat('x', 200);
        $messages = [
            ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => $bigContent],
        ];

        // The destructure in the original (never executed) test read the
        // CompactionResult as $prepared and null as $spilled.
        $prepared = $compactor->compact($messages, 20);
        $spilled = $prepared->spilled;
        self::assertCount(1, $spilled);
        self::assertSame(64, strlen($spilled[0]['sha256']));
        self::assertStringContainsString('stored outside context', $prepared->messages[0]['content']);
    }

    public function test_budget_estimate_and_compact_to_budget(): void
    {
        $budget = new ContextBudget(maxTokens: 100, reservedOutputTokens: 10);
        $messages = $this->makeMessages(20);

        self::assertFalse($budget->fits($messages));

        $compactor = new ContextCompactor();
        $result = $compactor->compactToBudget($messages, $budget);
        self::assertTrue($budget->fits($result->messages));
        self::assertGreaterThan(0, $result->removedCount);
    }

    /**
     * M-25: the result never carries MORE messages than the budget allows
     * — the system messages are returned on top of the kept messages and
     * used to be added for free.
     */
    public function test_compact_never_exceeds_max_messages(): void
    {
        $compactor = new ContextCompactor();
        $messages = [];
        for ($i = 0; $i < 25; $i++) {
            $messages[] = ['role' => 'system', 'content' => "sys{$i}"];
        }
        for ($i = 0; $i < 10; $i++) {
            $messages[] = ['role' => 'user', 'content' => "u{$i}"];
        }

        foreach ([2, 3, 5, 10, 20, 40] as $maxMessages) {
            $result = $compactor->compact($messages, $maxMessages);
            self::assertLessThanOrEqual(
                $maxMessages,
                count($result->messages),
                "the result must not exceed maxMessages={$maxMessages}",
            );
            self::assertNotSame([], $result->messages);
        }
    }

    /**
     * M-25: `compactToBudget()` guarantees a result that fits the budget.
     */
    public function test_compact_to_budget_result_fits_the_budget(): void
    {
        $budget = new ContextBudget(maxTokens: 120, reservedOutputTokens: 20);
        $messages = [];
        for ($i = 0; $i < 60; $i++) {
            $messages[] = ['role' => $i % 2 === 0 ? 'user' : 'assistant', 'content' => str_repeat('x', 120)];
        }

        $result = (new ContextCompactor())->compactToBudget($messages, $budget);

        self::assertTrue($budget->fits($result->messages));
        self::assertGreaterThan(0, $result->removedCount);
    }

    /**
     * M-25: the summariser runs ONCE per compaction — the budget search
     * used to summarise the dropped messages again on every iteration.
     */
    public function test_compact_to_budget_summarises_once(): void
    {
        $calls = 0;
        $compactor = new ContextCompactor(summarizer: static function (array $removed) use (&$calls): string {
            $calls++;
            return 'a summary';
        });
        $budget = new ContextBudget(maxTokens: 150, reservedOutputTokens: 20);
        $messages = [];
        for ($i = 0; $i < 80; $i++) {
            $messages[] = ['role' => $i % 2 === 0 ? 'user' : 'assistant', 'content' => str_repeat('x', 120)];
        }

        $compactor->compactToBudget($messages, $budget);

        self::assertSame(1, $calls);
    }
}
