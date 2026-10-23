<?php

declare(strict_types=1);

namespace LightAgent\Flow;

use LightAgent\Support\Json;

/**
 * Small JSON-file run store for LightFlow checkpoints
 * (flow.py JsonLightFlowStore).
 */
final class JsonLightFlowStore
{
    public function __construct(
        public readonly string $directory,
    ) {
        if (!is_dir($this->directory)) {
            // 0750 instead of 0777: the checkpoint directory holds run state
            // and must not be world-writable (M-64).
            mkdir($this->directory, 0750, true);
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    public function saveRun(string $runId, array $record): void
    {
        // An encoding failure must not replace the stored run with a 0-byte
        // file: the whole flow state would be lost silently (T5b/T15).
        $encoded = Json::encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($encoded === '') {
            throw new \RuntimeException('flow run record cannot be encoded as JSON');
        }
        // V5-27: the write is ATOMIC. `file_put_contents(..., LOCK_EX)`
        // only serialises concurrent writers — a crash in the middle of the
        // write left a truncated JSON file behind, and the whole run became
        // unrecoverable (loadRun() -> null, listRuns() skipped it, resume()
        // threw "run not found"). Writing a temporary file and renaming it
        // over the target is atomic on POSIX and on Windows: the target
        // either holds the previous content or the new content, never a
        // half-written file.
        $path = $this->path($runId);
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        try {
            $written = file_put_contents(
                $temporary,
                $encoded,
                LOCK_EX,
            );
            if ($written === false || $written !== strlen($encoded)) {
                throw new \RuntimeException("failed to write flow run record: {$runId}");
            }
            if (!rename($temporary, $path)) {
                throw new \RuntimeException("failed to publish flow run record: {$runId}");
            }
        } finally {
            // The temporary file must never survive a failed write: the
            // store directory would fill up with orphaned checkpoints.
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadRun(string $runId): ?array
    {
        $path = $this->path($runId);
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRuns(): array
    {
        $records = [];
        $paths = glob($this->directory . '/*.json') ?: [];
        sort($paths);
        foreach ($paths as $path) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                $records[] = $decoded;
            }
        }
        return $records;
    }

    /**
     * Resolve the checkpoint file of a run id.
     *
     * M-23: the run id used to be "sanitised" by STRIPPING every character
     * outside [A-Za-z0-9_-]. Distinct run ids therefore collapsed onto one
     * file: `abc/def` and `abcdef` shared `abcdef.json`, and loading
     * `abc/def` returned ANOTHER run's checkpoint. An unsafe run id is now
     * REJECTED instead of being rewritten — the same rule the session store
     * applies to a session id (JsonlSessionStore::safeId()).
     */
    private function path(string $runId): string
    {
        $value = (string) $runId;
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            throw new \InvalidArgumentException('run_id contains unsafe characters');
        }
        return $this->directory . DIRECTORY_SEPARATOR . "{$value}.json";
    }
}
