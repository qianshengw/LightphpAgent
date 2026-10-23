<?php

declare(strict_types=1);

namespace LightAgent\Logging;

/**
 * Centralized logger (port of logger.py LoggerManager).
 *
 * Semantics preserved from the Python original:
 *  - log() is a no-op unless debug mode is enabled
 *  - only DEBUG / INFO / ERROR levels are used
 *  - optional traceid prefix, optional log file (directory auto-created)
 *  - console handler only attached in debug mode
 */
final class LoggerManager
{
    public private(set) string $traceid = '';

    private const LEVELS = [
        'DEBUG' => 0,
        'INFO' => 1,
        'ERROR' => 2,
    ];

    /**
     * The level an unknown level falls back to (M-62).
     *
     * An unknown CONFIGURED level used to be looked up in LEVELS anyway:
     * that raised an "undefined array key" warning and the comparison
     * against `null` silently disabled the level filter (every message
     * passed). An unknown level is now resolved to this default instead.
     */
    public const DEFAULT_LEVEL = 'INFO';

    public function __construct(
        private readonly string $name,
        public private(set) bool $debug = false,
        private string $logLevel = 'INFO',
        private readonly ?string $logFile = null,
    ) {
        $this->logLevel = self::normalizeLevel($logLevel);
        if ($this->logFile !== null) {
            $dir = dirname($this->logFile);
            if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
                // 0750 instead of 0777: a log directory holds operational
                // data and must not be world-writable (M-64).
                mkdir($dir, 0750, true);
            }
        }
    }

    /**
     * Resolve a level name to a known level.
     *
     * Unknown names fall back to `self::DEFAULT_LEVEL` — no warning and no
     * silently disabled filter.
     */
    private static function normalizeLevel(string $level): string
    {
        $normalized = strtoupper(trim($level));
        return isset(self::LEVELS[$normalized]) ? $normalized : self::DEFAULT_LEVEL;
    }

    public function setTraceid(string $traceid): void
    {
        $this->traceid = $traceid;
    }

    /**
     * Record a log line. No-op unless debug mode is on.
     */
    public function log(string $level, string $action, mixed $data): void
    {
        if (!$this->debug) {
            return;
        }

        $level = strtoupper($level);
        if (!isset(self::LEVELS[$level])) {
            // An unknown message level is reported as the default level
            // instead of being dropped silently (M-62).
            $level = self::DEFAULT_LEVEL;
        }
        if (self::LEVELS[$level] < self::LEVELS[$this->logLevel]) {
            return;
        }

        $traceInfo = $this->traceid !== '' ? "[TraceID: {$this->traceid}] " : '';
        // Equivalent of the Python utf-8 ignore/decode sanitation pass.
        $message = $traceInfo . $action . ': ' . $this->sanitize($data);

        $line = sprintf(
            "%s [%s] %s\n",
            date('Y-m-d H:i:s'),
            $level,
            $message,
        );

        if ($this->debug) {
            fwrite(STDERR, $line);
        }
        if ($this->logFile !== null) {
            file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
            // The log file may contain operational data: keep it readable
            // by its owner only (M-64). chmod() is a no-op on Windows
            // beyond the read-only flag, so a failure is not fatal.
            @chmod($this->logFile, 0640);
        }
    }

    public function debug(string $action, mixed $data = ''): void
    {
        $this->log('DEBUG', $action, $data);
    }

    public function info(string $action, mixed $data = ''): void
    {
        $this->log('INFO', $action, $data);
    }

    public function error(string $action, mixed $data = ''): void
    {
        $this->log('ERROR', $action, $data);
    }

    private function sanitize(mixed $data): string
    {
        $text = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($text === false) {
            $text = print_r($data, true);
        }
        // Drop invalid UTF-8 sequences the way .encode('utf-8','ignore').decode() does.
        $clean = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        return $clean === false ? $text : $clean;
    }
}
