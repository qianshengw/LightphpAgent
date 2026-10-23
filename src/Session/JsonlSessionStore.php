<?php

declare(strict_types=1);

namespace LightAgent\Session;

/**
 * One append-readable JSONL file per Session with atomic full saves
 * (session.py JsonlSessionStore).
 */
final class JsonlSessionStore implements SessionStore
{
    public function __construct(
        public readonly string $directory,
    ) {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0777, true);
        }
    }

    private static function safeId(string $sessionId): string
    {
        $value = (string) $sessionId;
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            throw new \InvalidArgumentException('session_id contains unsafe characters');
        }
        return $value;
    }

    private function path(string $sessionId): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . self::safeId($sessionId) . '.jsonl';
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function create(?Session $session = null, ?array $metadata = null): Session
    {
        $value = $session ?? new Session(metadata: $metadata ?? []);
        if (is_file($this->path($value->sessionId))) {
            throw new \InvalidArgumentException("session already exists: {$value->sessionId}");
        }
        $this->save($value);
        return Session::fromDict($value->toDict());
    }

    public function get(string $sessionId): ?Session
    {
        $path = $this->path($sessionId);
        if (!is_file($path)) {
            return null;
        }
        $lines = [];
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("cannot open {$path}");
        }
        try {
            while (($line = fgets($handle)) !== false) {
                if (trim($line) !== '') {
                    $lines[] = json_decode($line, true);
                }
            }
        } finally {
            fclose($handle);
        }
        if ($lines === [] || (($lines[0]['kind'] ?? null) !== 'session')) {
            throw new \InvalidArgumentException("invalid Session JSONL header: {$path}");
        }
        $header = $lines[0]['value'];
        $events = [];
        foreach (array_slice($lines, 1) as $line) {
            if (($line['kind'] ?? null) === 'event' && is_array($line['value'] ?? null)) {
                $events[] = SessionEvent::fromDict($line['value']);
            }
        }
        return new Session(
            sessionId: (string) $header['session_id'],
            metadata: is_array($header['metadata'] ?? null) ? $header['metadata'] : [],
            events: $events,
            createdAt: (string) ($header['created_at'] ?? '') ?: SessionConstants::utcNow(),
            updatedAt: (string) ($header['updated_at'] ?? '') ?: SessionConstants::utcNow(),
            schemaVersion: (int) ($header['schema_version'] ?? SessionConstants::SCHEMA_VERSION),
        );
    }

    public function save(Session $session): void
    {
        $session->validate();
        $path = $this->path($session->sessionId);
        // Stale-write guard (M-33): a writer holding an OLD copy of the
        // session must not be able to replace a NEWER log. The in-memory
        // store refuses such a save; the persistent stores have to enforce
        // the same rule, otherwise a late save physically deletes the
        // events that were appended in the meantime.
        $this->assertNotOlderThanStored($session, $path);
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        $header = [
            'session_id' => $session->sessionId,
            // Redact at WRITE time (V4-10): the metadata is a public array
            // and may have been mutated after the session was built.
            'metadata' => SessionConstants::jsonSafe($session->metadata) ?? [],
            'created_at' => $session->createdAt,
            'updated_at' => $session->updatedAt,
            'schema_version' => $session->schemaVersion,
        ];
        try {
            $handle = fopen($temporary, 'w');
            if ($handle === false) {
                throw new \RuntimeException("cannot write {$temporary}");
            }
            try {
                fwrite($handle, json_encode(['kind' => 'session', 'value' => $header], JSON_UNESCAPED_UNICODE) . "\n");
                foreach ($session->events as $event) {
                    fwrite($handle, json_encode(['kind' => 'event', 'value' => $event->toDict()], JSON_UNESCAPED_UNICODE) . "\n");
                }
                fflush($handle);
            } finally {
                fclose($handle);
            }
            $this->replaceFile($temporary, $path);
        } finally {
            // The temporary file must never survive a failed save: a
            // leftover `.tmp-*` file is garbage that the reader ignores
            // but that still holds the payload in clear text.
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * Refuse a save whose event log is OLDER than the stored one.
     *
     * Mirrors `InMemorySessionStore::save()`: the stored log is the newer
     * one, so the incoming copy is stale and is rejected BEFORE anything
     * is written. The stored log is left untouched.
     *
     * @throws \InvalidArgumentException when the incoming copy is stale
     */
    private function assertNotOlderThanStored(Session $session, string $path): void
    {
        if ($session->events === []) {
            return;
        }
        $stored = $this->storedLastSequence($path);
        if ($stored === null) {
            return;
        }
        $incoming = end($session->events);
        if ($incoming === false) {
            return;
        }
        if ($incoming->sequence < $stored) {
            throw new \InvalidArgumentException(
                'refusing to replace a Session with an older event sequence',
            );
        }
    }

    /**
     * The highest event sequence currently stored in the JSONL file.
     *
     * Returns null when the file does not exist, cannot be read or holds
     * no parsable event (an unreadable log must not block every write).
     */
    private function storedLastSequence(string $path): ?int
    {
        if (!is_file($path)) {
            return null;
        }
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return null;
        }
        $last = null;
        try {
            while (($line = fgets($handle)) !== false) {
                if (trim($line) === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (!is_array($decoded) || ($decoded['kind'] ?? null) !== 'event') {
                    continue;
                }
                $value = $decoded['value'] ?? null;
                if (!is_array($value) || !isset($value['sequence'])) {
                    continue;
                }
                $sequence = (int) $value['sequence'];
                if ($last === null || $sequence > $last) {
                    $last = $sequence;
                }
            }
        } finally {
            fclose($handle);
        }
        return $last;
    }

    /**
     * Atomically replace the session file with the freshly written one.
     *
     * `rename()` does not throw when it fails: on Windows it emits a
     * WARNING and returns false when the target file is held open by
     * another process (a sharing violation). The unchecked result made the
     * save look successful while the new event was silently dropped, so
     * the result is checked here and turned into an exception the caller
     * records as `session_store_error` (V5-21).
     *
     * @throws \RuntimeException when the file cannot be replaced
     */
    private function replaceFile(string $temporary, string $path): void
    {
        $attempts = 3;
        $reason = 'unknown error';
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $warning = null;
            set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
                $warning = $message;
                return true;
            });
            try {
                $renamed = rename($temporary, $path);
            } finally {
                restore_error_handler();
            }
            if ($renamed) {
                return;
            }
            $reason = $warning ?? 'rename() returned false';
            if ($attempt < $attempts) {
                // A sharing violation on Windows is transient: the other
                // process may close the handle in a moment.
                usleep(50_000 * $attempt);
            }
        }
        throw new \RuntimeException("cannot replace session file {$path}: {$reason}");
    }

    public function list(int $limit = 100): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('limit must be at least 1');
        }
        $paths = glob($this->directory . DIRECTORY_SEPARATOR . '*.jsonl') ?: [];
        // Sort by mtime descending (Python: sorted by st_mtime, reverse)
        usort($paths, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $sessions = [];
        foreach ($paths as $path) {
            $session = $this->get(basename($path, '.jsonl'));
            if ($session !== null) {
                $sessions[] = $session;
            }
            if (count($sessions) >= $limit) {
                break;
            }
        }
        return $sessions;
    }

    public function delete(string $sessionId): bool
    {
        $path = $this->path($sessionId);
        if (!is_file($path)) {
            return false;
        }
        return unlink($path);
    }
}
