<?php

declare(strict_types=1);

namespace LightAgent\Session;

/**
 * SQLite SessionStore with transactional saves (session.py SqliteSessionStore).
 */
final class SqliteSessionStore implements SessionStore
{
    private \PDO $pdo;

    public function __construct(
        public readonly string $path,
    ) {
        $dir = dirname($this->path);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $this->pdo = new \PDO('sqlite:' . $this->path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA busy_timeout=30000');
        $this->initialize();
    }

    private function initialize(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS sessions (
                session_id TEXT PRIMARY KEY,
                metadata TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                schema_version INTEGER NOT NULL
            )',
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS session_events (
                session_id TEXT NOT NULL,
                sequence INTEGER NOT NULL,
                event_id TEXT NOT NULL,
                payload TEXT NOT NULL,
                PRIMARY KEY (session_id, sequence),
                -- Scoped uniqueness (M24): the same event id may appear in a
                -- forked session copy; only per-session duplicates collide.
                UNIQUE (session_id, event_id),
                FOREIGN KEY (session_id) REFERENCES sessions(session_id) ON DELETE CASCADE
            )',
        );
        $this->migrateEventIdUniqueness();
    }

    /**
     * Databases created before M24 carry a global UNIQUE(event_id), which
     * breaks copying a session into a new id. Rebuild the table in place
     * when the legacy index is detected.
     */
    private function migrateEventIdUniqueness(): void
    {
        $indexes = $this->pdo->query("PRAGMA index_list('session_events')")->fetchAll(\PDO::FETCH_ASSOC);
        $hasScopedUnique = false;
        $hasLegacyUnique = false;
        foreach ($indexes as $index) {
            $cols = $this->pdo->prepare("PRAGMA index_info('{$index['name']}')");
            $cols->execute();
            $columns = array_map(static fn (array $r): string => (string) $r['name'], $cols->fetchAll(\PDO::FETCH_ASSOC));
            if ((int) $index['unique'] === 1 && $columns === ['session_id', 'event_id']) {
                $hasScopedUnique = true;
            }
            if ((int) $index['unique'] === 1 && $columns === ['event_id']) {
                $hasLegacyUnique = true;
            }
        }
        if (!$hasLegacyUnique || $hasScopedUnique) {
            return;
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('ALTER TABLE session_events RENAME TO session_events_legacy');
            $this->pdo->exec(
                'CREATE TABLE session_events (
                    session_id TEXT NOT NULL,
                    sequence INTEGER NOT NULL,
                    event_id TEXT NOT NULL,
                    payload TEXT NOT NULL,
                    PRIMARY KEY (session_id, sequence),
                    UNIQUE (session_id, event_id),
                    FOREIGN KEY (session_id) REFERENCES sessions(session_id) ON DELETE CASCADE
                )',
            );
            $this->pdo->exec('INSERT INTO session_events(session_id, sequence, event_id, payload)
                SELECT session_id, sequence, event_id, payload FROM session_events_legacy');
            $this->pdo->exec('DROP TABLE session_events_legacy');
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function create(?Session $session = null, ?array $metadata = null): Session
    {
        $value = $session ?? new Session(metadata: $metadata ?? []);
        $stmt = $this->pdo->prepare('SELECT 1 FROM sessions WHERE session_id = ?');
        $stmt->execute([$value->sessionId]);
        if ($stmt->fetch() !== false) {
            throw new \InvalidArgumentException("session already exists: {$value->sessionId}");
        }
        $this->save($value);
        return Session::fromDict($value->toDict());
    }

    public function get(string $sessionId): ?Session
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sessions WHERE session_id = ?');
        $stmt->execute([(string) $sessionId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $eventStmt = $this->pdo->prepare(
            'SELECT payload FROM session_events WHERE session_id = ? ORDER BY sequence',
        );
        $eventStmt->execute([(string) $sessionId]);
        $events = [];
        foreach ($eventStmt->fetchAll() as $eventRow) {
            $events[] = SessionEvent::fromDict(json_decode((string) $eventRow['payload'], true));
        }
        return new Session(
            sessionId: (string) $row['session_id'],
            metadata: json_decode((string) $row['metadata'], true) ?? [],
            events: $events,
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
            schemaVersion: (int) $row['schema_version'],
        );
    }

    public function save(Session $session): void
    {
        $session->validate();
        // Stale-write guard (M-33): the in-memory store refuses to replace
        // a session with an OLDER copy; the persistent backend has to
        // enforce the same rule. Without it the DELETE below physically
        // removed the events that another writer had appended in the
        // meantime.
        $this->assertNotOlderThanStored($session);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('PRAGMA foreign_keys=ON');
            $upsert = $this->pdo->prepare(
                'INSERT INTO sessions(session_id, metadata, created_at, updated_at, schema_version)
                 VALUES (?, ?, ?, ?, ?)
                 ON CONFLICT(session_id) DO UPDATE SET
                     metadata=excluded.metadata,
                     updated_at=excluded.updated_at,
                     schema_version=excluded.schema_version',
            );
            $upsert->execute([
                $session->sessionId,
                // Redact at WRITE time (V4-10): the metadata is a public
                // array and may have been mutated after the session was
                // built.
                json_encode(SessionConstants::jsonSafe($session->metadata) ?? [], JSON_UNESCAPED_UNICODE),
                $session->createdAt,
                $session->updatedAt,
                $session->schemaVersion,
            ]);
            $last = end($session->events);
            $lastSequence = $last === false ? 0 : $last->sequence;
            // Incremental write: only the events BEYOND the stored log are
            // inserted, and the rows already stored are updated in place.
            // The previous implementation deleted EVERY row and re-inserted
            // the whole log, so a single stale writer could erase the
            // events another process had just appended.
            $truncate = $this->pdo->prepare('DELETE FROM session_events WHERE session_id = ? AND sequence > ?');
            $truncate->execute([$session->sessionId, $lastSequence]);
            $insert = $this->pdo->prepare(
                'INSERT INTO session_events(session_id, sequence, event_id, payload) VALUES (?, ?, ?, ?)
                 ON CONFLICT(session_id, sequence) DO UPDATE SET
                     event_id=excluded.event_id,
                     payload=excluded.payload',
            );
            foreach ($session->events as $event) {
                $insert->execute([
                    $session->sessionId,
                    $event->sequence,
                    $event->eventId,
                    json_encode($event->toDict(), JSON_UNESCAPED_UNICODE),
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Refuse a save whose event log is OLDER than the stored one.
     *
     * Mirrors `InMemorySessionStore::save()`: the stored log is the newer
     * one, so the incoming copy is stale and is rejected BEFORE the
     * transaction starts — the stored log stays untouched.
     *
     * @throws \InvalidArgumentException when the incoming copy is stale
     */
    private function assertNotOlderThanStored(Session $session): void
    {
        if ($session->events === []) {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT MAX(sequence) AS last_sequence FROM session_events WHERE session_id = ?');
        $stmt->execute([$session->sessionId]);
        $stored = $stmt->fetch();
        if ($stored === false || $stored['last_sequence'] === null) {
            return;
        }
        $incoming = end($session->events);
        if ($incoming === false) {
            return;
        }
        if ($incoming->sequence < (int) $stored['last_sequence']) {
            throw new \InvalidArgumentException(
                'refusing to replace a Session with an older event sequence',
            );
        }
    }

    public function list(int $limit = 100): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('limit must be at least 1');
        }
        $stmt = $this->pdo->prepare('SELECT session_id FROM sessions ORDER BY updated_at DESC LIMIT ?');
        $stmt->execute([$limit]);
        $sessions = [];
        foreach ($stmt->fetchAll() as $row) {
            $session = $this->get((string) $row['session_id']);
            if ($session !== null) {
                $sessions[] = $session;
            }
        }
        return $sessions;
    }

    public function delete(string $sessionId): bool
    {
        $this->pdo->exec('PRAGMA foreign_keys=ON');
        $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE session_id = ?');
        $stmt->execute([(string) $sessionId]);
        return $stmt->rowCount() > 0;
    }
}
