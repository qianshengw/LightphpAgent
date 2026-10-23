<?php

declare(strict_types=1);

namespace LightAgent\Tests\Logging;

use LightAgent\Logging\LoggerManager;
use PHPUnit\Framework\TestCase;

final class LoggerManagerTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/lightagent_test_' . bin2hex(random_bytes(4)) . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    public function test_log_is_noop_when_debug_disabled(): void
    {
        $logger = new LoggerManager('t', debug: false, logLevel: 'INFO', logFile: $this->logFile);
        $logger->log('INFO', 'action', 'data');

        self::assertFileDoesNotExist($this->logFile);
    }

    public function test_log_writes_to_file_when_debug_enabled(): void
    {
        $logger = new LoggerManager('t', debug: true, logLevel: 'INFO', logFile: $this->logFile);
        $logger->log('INFO', 'action', 'data');

        self::assertFileExists($this->logFile);
        $content = file_get_contents($this->logFile);
        self::assertStringContainsString('[INFO]', $content);
        self::assertStringContainsString('action: data', $content);
    }

    public function test_log_respects_level_threshold(): void
    {
        $logger = new LoggerManager('t', debug: true, logLevel: 'ERROR', logFile: $this->logFile);
        $logger->log('DEBUG', 'hidden', 'x');
        $logger->log('INFO', 'hidden', 'x');
        $logger->log('ERROR', 'visible', 'x');

        $content = file_get_contents($this->logFile);
        self::assertStringNotContainsString('hidden', $content);
        self::assertStringContainsString('[ERROR] visible: x', $content);
    }

    public function test_traceid_prefix(): void
    {
        $logger = new LoggerManager('t', debug: true, logLevel: 'DEBUG', logFile: $this->logFile);
        $logger->setTraceid('abc123');
        $logger->log('DEBUG', 'phase', 'msg');

        $content = file_get_contents($this->logFile);
        self::assertStringContainsString('[TraceID: abc123] phase: msg', $content);
    }

    public function test_creates_log_directory(): void
    {
        $dir = sys_get_temp_dir() . '/la_' . bin2hex(random_bytes(4)) . '/sub';
        $file = $dir . '/x.log';
        try {
            $logger = new LoggerManager('t', debug: true, logLevel: 'INFO', logFile: $file);
            $logger->log('INFO', 'a', 'b');
            self::assertFileExists($file);
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
            if (is_dir($dir)) {
                @rmdir($dir);
                @rmdir(dirname($dir));
            }
        }
    }

    public function test_non_string_data_is_json_encoded(): void
    {
        $logger = new LoggerManager('t', debug: true, logLevel: 'INFO', logFile: $this->logFile);
        $logger->log('INFO', 'payload', ['k' => 'v', 'n' => 1]);

        $content = file_get_contents($this->logFile);
        self::assertStringContainsString('payload: {"k":"v","n":1}', $content);
    }

    public function test_invalid_utf8_is_sanitized_not_dropped(): void
    {
        $logger = new LoggerManager('t', debug: true, logLevel: 'INFO', logFile: $this->logFile);
        $bad = "ok \xB1\x31 bad";
        $logger->log('INFO', 'sanitized', $bad);

        $content = file_get_contents($this->logFile);
        self::assertStringContainsString('sanitized: ok', $content);
    }

    /**
     * M-62: an unknown level must not raise an "undefined array key"
     * warning and must not silently disable the level filter.
     */
    public function test_unknown_level_falls_back_to_the_default(): void
    {
        $logger = new LoggerManager('t', debug: true, logLevel: 'NOPE', logFile: $this->logFile);
        $logger->log('DEBUG', 'hidden', 'x');
        $logger->log('INFO', 'visible', 'x');

        $content = file_get_contents($this->logFile);
        self::assertStringNotContainsString('hidden', $content);
        self::assertStringContainsString('[INFO] visible: x', $content);
    }

    /**
     * M-62: an unknown MESSAGE level is reported as the default level
     * instead of being dropped.
     */
    public function test_unknown_message_level_is_not_dropped(): void
    {
        $logger = new LoggerManager('t', debug: true, logLevel: 'INFO', logFile: $this->logFile);
        $logger->log('WAT', 'action', 'data');

        $content = file_get_contents($this->logFile);
        self::assertStringContainsString('[INFO] action: data', $content);
    }
}
