<?php

declare(strict_types=1);

namespace LightAgent\Tracing;

/**
 * Append complete trace envelopes to a local JSONL audit file
 * (tracing.py JsonlTraceExporter).
 */
final class JsonlTraceExporter
{
    public function __construct(
        public readonly string $path,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $events
     * @param array<string, mixed>|null $metadata
     */
    public function export(array $events, TraceSummary $summary, ?array $metadata = null): string
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            // 0750 instead of 0777: the audit trail must not be
            // world-writable (M-64).
            mkdir($dir, 0750, true);
        }
        $envelope = [
            'exported_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'metadata' => $metadata ?? [],
            'summary' => $summary->toDict(),
            'events' => $events,
        ];
        $json = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = json_encode(['error' => 'json_encode failed']);
        }
        file_put_contents($this->path, $json . "\n", FILE_APPEND | LOCK_EX);
        // Keep the audit file readable by its owner only (M-64). chmod() is
        // a no-op on Windows beyond the read-only flag.
        @chmod($this->path, 0640);
        return $this->path;
    }
}
