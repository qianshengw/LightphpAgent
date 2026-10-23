<?php

declare(strict_types=1);

namespace LightAgent\Tests\Session;

use LightAgent\Session\ContextBudget;
use LightAgent\Session\JsonlSessionStore;
use LightAgent\Session\Session;
use LightAgent\Session\SessionConstants;
use LightAgent\Session\SqliteSessionStore;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the session hardening findings:
 *
 *   M-22  the camelCase credential keys were never redacted
 *   M-33  the persistent stores accepted a STALE copy and lost updates
 *   V5-21 the JSONL store silently dropped the event when the rename failed
 *   M-26  the token estimate was wrong for invalid UTF-8 and for CJK
 *   V4-10 the redaction only happened in the constructor
 */
final class SessionHardeningTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/la_hardening_' . bin2hex(random_bytes(4));
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
    }

    /**
     * M-22: the credential spellings that actually occur in payloads.
     *
     * @return list<array{0: string}>
     */
    public static function sensitiveKeyProvider(): array
    {
        return [
            ['accessToken'], ['dbPassword'], ['clientSecret'], ['authToken'],
            ['openaiToken'], ['userSecret'], ['api_key'], ['apiKey'],
            ['Api-Key'], ['api key'], ['access_token'], ['password'],
            ['authorization'], ['x-api-key'], ['user_api_key'], ['privateKey'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sensitiveKeyProvider')]
    public function test_credential_keys_are_redacted(string $key): void
    {
        self::assertTrue(SessionConstants::isSensitiveKey($key), "{$key} must be redacted");
        $safe = SessionConstants::jsonSafe([$key => 'sk-value']);
        self::assertSame('[redacted]', $safe[$key]);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function ordinaryKeyProvider(): array
    {
        return [
            ['user'], ['session'], ['tokenizer'], ['userId'], ['agentId'],
            ['tenantId'], ['name'], ['content'], ['keyword'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ordinaryKeyProvider')]
    public function test_ordinary_keys_are_not_redacted(string $key): void
    {
        self::assertFalse(SessionConstants::isSensitiveKey($key), "{$key} must not be redacted");
        $safe = SessionConstants::jsonSafe([$key => 'value']);
        self::assertSame('value', $safe[$key]);
    }

    public function test_jsonl_store_rejects_a_stale_copy(): void
    {
        $store = new JsonlSessionStore($this->tmpDir);
        $session = $store->create();
        $session->append('e1');
        $session->append('e2');
        $store->save($session);

        $stale = $store->get($session->sessionId);
        self::assertNotNull($stale);
        array_pop($stale->events);

        try {
            $store->save($stale);
            self::fail('a stale copy must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('older event sequence', $e->getMessage());
        }

        $reloaded = $store->get($session->sessionId);
        self::assertNotNull($reloaded);
        self::assertCount(2, $reloaded->events, 'the stored log must keep the newer events');
    }

    public function test_sqlite_store_rejects_a_stale_copy(): void
    {
        $store = new SqliteSessionStore($this->tmpDir . '/sessions.db');
        $session = $store->create();
        $session->append('e1');
        $session->append('e2');
        $store->save($session);

        $stale = $store->get($session->sessionId);
        self::assertNotNull($stale);
        array_pop($stale->events);

        try {
            $store->save($stale);
            self::fail('a stale copy must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('older event sequence', $e->getMessage());
        }

        $reloaded = $store->get($session->sessionId);
        self::assertNotNull($reloaded);
        self::assertCount(2, $reloaded->events, 'the stored log must keep the newer events');
    }

    public function test_sqlite_store_appends_without_losing_events(): void
    {
        $store = new SqliteSessionStore($this->tmpDir . '/sessions.db');
        $session = $store->create();
        $session->append('e1');
        $store->save($session);

        $loaded = $store->get($session->sessionId);
        self::assertNotNull($loaded);
        $loaded->append('e2');
        $store->save($loaded);

        $reloaded = $store->get($session->sessionId);
        self::assertNotNull($reloaded);
        self::assertCount(2, $reloaded->events);
    }

    /**
     * V5-21: a failed `rename()` must surface as an error instead of
     * silently dropping the event. On Windows the rename over a file that
     * another handle keeps open fails; the lock is only reproducible on
     * Windows, so the test is skipped elsewhere.
     */
    public function test_jsonl_store_reports_a_failed_replace(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('the sharing violation is a Windows behaviour');
        }
        $store = new JsonlSessionStore($this->tmpDir);
        $session = $store->create();
        $session->append('e1');
        $store->save($session);

        $path = $this->tmpDir . DIRECTORY_SEPARATOR . $session->sessionId . '.jsonl';
        $handle = fopen($path, 'r');
        self::assertIsResource($handle);
        $session->append('e2');
        try {
            $store->save($session);
            self::fail('the failed rename must raise an error');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('cannot replace session file', $e->getMessage());
        } finally {
            fclose($handle);
        }

        $leftovers = glob($this->tmpDir . '/*.tmp-*') ?: [];
        self::assertSame([], $leftovers, 'the temporary file must be cleaned up');
        $reloaded = $store->get($session->sessionId);
        self::assertNotNull($reloaded);
        self::assertCount(1, $reloaded->events, 'the stored log must be unchanged');
    }

    /**
     * M-26: an invalid UTF-8 byte must not collapse the estimate to one
     * token.
     */
    public function test_budget_estimate_handles_invalid_utf8(): void
    {
        $budget = new ContextBudget(maxTokens: 100000, reservedOutputTokens: 0);
        $estimate = $budget->estimate([['role' => 'user', 'content' => "broken \xB1 byte"]]);

        self::assertGreaterThan(1, $estimate);
    }

    /**
     * M-26: the estimate stays calibrated for English text.
     */
    public function test_budget_estimate_keeps_the_english_calibration(): void
    {
        $budget = new ContextBudget(maxTokens: 100000, reservedOutputTokens: 0);
        $messages = [['role' => 'user', 'content' => str_repeat('a', 4000)]];

        self::assertSame(1008, $budget->estimate($messages));
    }

    /**
     * M-26: a CJK character costs about one token, not a quarter.
     */
    public function test_budget_estimate_is_cjk_aware(): void
    {
        $budget = new ContextBudget(maxTokens: 100000, reservedOutputTokens: 0);
        $messages = [['role' => 'user', 'content' => str_repeat('中', 9500)]];

        $estimate = $budget->estimate($messages);

        self::assertGreaterThan(9000, $estimate, 'the CJK estimate must not under-count');
        self::assertLessThan(11000, $estimate);
    }

    /**
     * V4-10: a payload that is mutated after the event was created must
     * still be redacted when it is written.
     */
    public function test_payload_is_redacted_at_write_time(): void
    {
        $store = new JsonlSessionStore($this->tmpDir);
        $session = $store->create();
        $event = $session->append('e1', ['name' => 'ok']);
        $event->data['apiKey'] = 'sk-mutated';
        $session->metadata['clientSecret'] = 'shhh';
        $store->save($session);

        $raw = (string) file_get_contents($this->tmpDir . DIRECTORY_SEPARATOR . $session->sessionId . '.jsonl');
        self::assertStringNotContainsString('sk-mutated', $raw);
        self::assertStringNotContainsString('shhh', $raw);
        self::assertStringContainsString('[redacted]', $raw);
    }

    /**
     * V4-10: the constructor behaviour is kept.
     */
    public function test_payload_is_redacted_at_construction_time(): void
    {
        $session = new Session();
        $event = $session->append('e1', ['api_key' => 'sk-123', 'name' => 'ok']);

        self::assertSame('[redacted]', $event->data['api_key']);
        self::assertSame('ok', $event->data['name']);
    }
}
