<?php

declare(strict_types=1);

namespace LightAgent\Knowledge;

use LightAgent\Capabilities\BaseCapabilityProvider;
use LightAgent\Capabilities\CapabilityRisk;
use LightAgent\Capabilities\CapabilitySpec;
use LightAgent\Capabilities\ProviderHealth;

/**
 * Dependency-free SQLite FTS5 retrieval with source-aware chunks
 * (port of knowledge.py SqliteFTSRetrievalProvider).
 *
 * PORTING-DIFF: the Python port keeps a persistent connection; PHP opens one
 * per operation (PDO is cheap for SQLite and avoids long-lived handles).
 */
final class SqliteFtsRetrievalProvider extends BaseCapabilityProvider implements RetrievalProvider
{
    public readonly string $path;
    private bool $fts5 = true;
    /**
     * Whether the TRIGRAM index is available.
     *
     * FTS5's default `unicode61` tokenizer treats a run of CJK characters as
     * ONE token (the boundaries are the non-alphanumeric characters), so a
     * Chinese document becomes a handful of long tokens and a Chinese query
     * matches nothing but the exact original segment. `trigram` indexes every
     * 3-character window instead, which makes substring search work for
     * Chinese without any external dependency.
     *
     * It needs SQLite >= 3.34; on older builds the flag stays false and the
     * provider falls back to the LIKE substring scan, which also matches
     * Chinese (just without ranking).
     */
    private bool $trigram = false;

    public function __construct(
        string $path,
        public readonly int $chunkSize = 1200,
        public readonly int $chunkOverlap = 120,
    ) {
        if ($this->chunkSize < 100) {
            throw new \InvalidArgumentException('chunk_size must be at least 100');
        }
        if ($this->chunkOverlap < 0 || $this->chunkOverlap >= $this->chunkSize) {
            throw new \InvalidArgumentException('chunk_overlap must be non-negative and smaller than chunk_size');
        }
        $this->path = $path;
        $this->name = 'sqlite-fts5-rag';
        $this->version = '1';
        $dir = dirname($this->path);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        parent::__construct([
            new CapabilitySpec('rag.ingest', write: true, persistent: true, risk: CapabilityRisk::ISOLATED_WRITE),
            new CapabilitySpec('rag.remove', write: true, persistent: true, risk: CapabilityRisk::DESTRUCTIVE),
            new CapabilitySpec('rag.list_documents', read: true),
            new CapabilitySpec('rag.search', read: true),
            new CapabilitySpec('rag.read_chunk', read: true),
            new CapabilitySpec('rag.reindex', write: true, persistent: true, risk: CapabilityRisk::SENSITIVE),
        ]);
        $this->initialize();
    }

    private function connect(): \PDO
    {
        $pdo = new \PDO('sqlite:' . $this->path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        // FK enforcement is per-connection in SQLite; without this the
        // rag_chunks CASCADE never fires on remove() (M26).
        $pdo->exec('PRAGMA foreign_keys=ON');
        // WAL lets a reader keep its cursor open while a writer commits:
        // without it a concurrent ingest blocked for the whole busy
        // timeout and then failed with "database is locked" (M-1).
        $pdo->exec('PRAGMA journal_mode=WAL');
        // A bounded busy timeout: a concurrent writer is waited for a
        // little instead of failing immediately.
        $pdo->exec('PRAGMA busy_timeout=5000');
        return $pdo;
    }

    private function initialize(): void
    {
        $pdo = $this->connect();
        $pdo->exec('CREATE TABLE IF NOT EXISTS rag_documents (document_id TEXT PRIMARY KEY, payload TEXT NOT NULL)');
        // The ownership columns are DENORMALISED (V4-08): the scope/owner/
        // tenant filter has to run in SQL, otherwise the LIMIT is applied
        // to the foreign chunks and the caller's own chunks are dropped.
        $this->ensureOwnershipColumns($pdo);
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS rag_chunks (
                chunk_id TEXT PRIMARY KEY,
                document_id TEXT NOT NULL,
                position INTEGER NOT NULL,
                content TEXT NOT NULL,
                FOREIGN KEY(document_id) REFERENCES rag_documents(document_id) ON DELETE CASCADE
            )',
        );
        try {
            $pdo->exec('CREATE VIRTUAL TABLE IF NOT EXISTS rag_chunks_fts USING fts5(chunk_id UNINDEXED, content)');
        } catch (\PDOException) {
            $this->fts5 = false;
        }
        if ($this->fts5) {
            try {
                // The TRIGRAM index is the Chinese path. It is a SECOND
                // index over the same content: the unicode61 index stays the
                // default for every other script, so the English behaviour
                // (ranking, prefix queries, boolean operators) is bit-for-bit
                // unchanged. Only a query that actually contains CJK is
                // routed here.
                $pdo->exec(
                    "CREATE VIRTUAL TABLE IF NOT EXISTS rag_chunks_cjk USING fts5(chunk_id UNINDEXED, content, tokenize='trigram')",
                );
                $this->trigram = true;
            } catch (\PDOException) {
                // SQLite < 3.34 has no trigram tokenizer. The LIKE
                // substring fallback still matches Chinese.
                $this->trigram = false;
            }
        }
    }

    /**
     * Add the ownership columns to a database created before they existed.
     */
    private function ensureOwnershipColumns(\PDO $pdo): void
    {
        $existing = [];
        foreach ($pdo->query('PRAGMA table_info(rag_documents)')->fetchAll() as $row) {
            $existing[(string) $row['name']] = true;
        }
        foreach (['scope', 'owner_id', 'tenant_id'] as $column) {
            if (!isset($existing[$column])) {
                $pdo->exec("ALTER TABLE rag_documents ADD COLUMN {$column} TEXT");
            }
        }
    }

    public function health(): ProviderHealth
    {
        return new ProviderHealth(
            healthy: true,
            status: $this->fts5 ? 'ready' : 'degraded',
            message: $this->fts5 ? null : 'SQLite FTS5 unavailable; LIKE search fallback is active',
            degradedCapabilities: $this->fts5 ? [] : ['rag.search'],
        );
    }

    /**
     * Ingest (or replace) a document and its chunks.
     *
     * The write is retried ONCE when the database is busy: a concurrent
     * reader used to block the ingest for the whole busy timeout and then
     * fail with "database is locked" (M-1).
     */
    public function ingest(RetrievalDocument|array $document): string
    {
        $value = $document instanceof RetrievalDocument ? $document : RetrievalDocument::fromDict($document);
        $chunks = $this->chunks($value->content);
        $attempts = 2;
        for ($attempt = 1; ; $attempt++) {
            try {
                $this->writeDocument($value, $chunks);
                return $value->documentId;
            } catch (\PDOException $e) {
                if ($attempt >= $attempts || !self::isBusyError($e)) {
                    throw $e;
                }
                // Another connection holds the lock: wait a moment and try
                // once more instead of losing the write.
                usleep(100_000 * $attempt);
            }
        }
    }

    /**
     * @param list<string> $chunks
     */
    private function writeDocument(RetrievalDocument $value, array $chunks): void
    {
        $pdo = $this->connect();
        $pdo->beginTransaction();
        try {
            // CROSS-TENANT OVERWRITE GUARD. The upsert below replaces a
            // document by id, so re-ingesting somebody else's document id
            // would silently DESTROY that document — the other tenant's
            // content disappears with no error. A replacement is only
            // allowed inside the same owner/tenant boundary.
            $existing = $pdo->prepare(
                'SELECT owner_id, tenant_id FROM rag_documents WHERE document_id = ?',
            );
            $existing->execute([$value->documentId]);
            $row = $existing->fetch();
            if (is_array($row)) {
                $storedOwner = $row['owner_id'] ?? null;
                $storedTenant = $row['tenant_id'] ?? null;
                if (($storedOwner !== null && $storedOwner !== $value->ownerId)
                    || ($storedTenant !== null && $storedTenant !== $value->tenantId)
                ) {
                    throw new \RuntimeException(sprintf(
                        'refusing to replace document `%s`: it belongs to a different owner/tenant',
                        $value->documentId,
                    ));
                }
            }
            if ($this->fts5) {
                // Collect stale FTS rowids BEFORE the REPLACE cascade removes
                // the rag_chunks rows they would join against (M26).
                $old = $pdo->prepare(
                    'SELECT f.rowid AS rowid
                     FROM rag_chunks_fts f JOIN rag_chunks c ON c.chunk_id = f.chunk_id
                     WHERE c.document_id = ?',
                );
                $old->execute([$value->documentId]);
                $staleRowIds = array_map(static fn (array $r): int => (int) $r['rowid'], $old->fetchAll());
                $del = $pdo->prepare('DELETE FROM rag_chunks_fts WHERE rowid = ?');
                foreach ($staleRowIds as $rowid) {
                    $del->execute([$rowid]);
                }
                if ($this->trigram) {
                    // The CJK index holds the same chunks and has to be
                    // cleaned in the same transaction, otherwise a replaced
                    // document would leave its old trigrams searchable.
                    $oldCjk = $pdo->prepare(
                        'SELECT f.rowid AS rowid
                         FROM rag_chunks_cjk f JOIN rag_chunks c ON c.chunk_id = f.chunk_id
                         WHERE c.document_id = ?',
                    );
                    $oldCjk->execute([$value->documentId]);
                    $delCjk = $pdo->prepare('DELETE FROM rag_chunks_cjk WHERE rowid = ?');
                    foreach ($oldCjk->fetchAll() as $row) {
                        $delCjk->execute([(int) $row['rowid']]);
                    }
                }
            }
            $pdo->prepare(
                'INSERT INTO rag_documents(document_id, payload, scope, owner_id, tenant_id)
                 VALUES (?, ?, ?, ?, ?)
                 ON CONFLICT(document_id) DO UPDATE SET
                     payload=excluded.payload,
                     scope=excluded.scope,
                     owner_id=excluded.owner_id,
                     tenant_id=excluded.tenant_id',
            )->execute([
                $value->documentId,
                json_encode($value->toDict(), JSON_UNESCAPED_UNICODE),
                $value->scope,
                $value->ownerId,
                $value->tenantId,
            ]);
            $pdo->prepare('DELETE FROM rag_chunks WHERE document_id = ?')->execute([$value->documentId]);
            foreach ($chunks as $position => $content) {
                $chunkId = "{$value->documentId}:{$position}";
                $pdo->prepare('INSERT INTO rag_chunks(chunk_id, document_id, position, content) VALUES (?, ?, ?, ?)')
                    ->execute([$chunkId, $value->documentId, $position, $content]);
                if ($this->fts5) {
                    $pdo->prepare('INSERT INTO rag_chunks_fts(chunk_id, content) VALUES (?, ?)')
                        ->execute([$chunkId, $content]);
                    if ($this->trigram) {
                        $pdo->prepare('INSERT INTO rag_chunks_cjk(chunk_id, content) VALUES (?, ?)')
                            ->execute([$chunkId, $content]);
                    }
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Whether the failure is a transient lock contention.
     */
    private static function isBusyError(\PDOException $error): bool
    {
        $message = strtolower($error->getMessage());
        return str_contains($message, 'locked') || str_contains($message, 'busy');
    }

    public function remove(string $documentId, ?string $ownerId = null, ?string $tenantId = null): bool
    {
        $pdo = $this->connect();
        // Ownership check (H9): refuse to delete documents outside the
        // caller's owner/tenant boundary.
        if ($ownerId !== null || $tenantId !== null) {
            $stmt = $pdo->prepare('SELECT payload FROM rag_documents WHERE document_id = ?');
            $stmt->execute([$documentId]);
            $row = $stmt->fetch();
            if ($row === false) {
                return false;
            }
            $document = RetrievalDocument::fromDict(json_decode((string) $row['payload'], true));
            if ($ownerId !== null && $document->ownerId !== $ownerId) {
                return false;
            }
            if ($tenantId !== null && $document->tenantId !== $tenantId) {
                return false;
            }
        }
        if ($this->fts5) {
            // Delete FTS rows via rowid (joined against live chunks) — plain
            // DELETE handles unindexed columns fine, but only while the
            // rag_chunks row still exists.
            $rows = $pdo->prepare(
                'SELECT f.rowid AS rowid
                 FROM rag_chunks_fts f JOIN rag_chunks c ON c.chunk_id = f.chunk_id
                 WHERE c.document_id = ?',
            );
            $rows->execute([$documentId]);
            $del = $pdo->prepare('DELETE FROM rag_chunks_fts WHERE rowid = ?');
            foreach ($rows->fetchAll() as $row) {
                $del->execute([(int) $row['rowid']]);
            }
            if ($this->trigram) {
                $rowsCjk = $pdo->prepare(
                    'SELECT f.rowid AS rowid
                     FROM rag_chunks_cjk f JOIN rag_chunks c ON c.chunk_id = f.chunk_id
                     WHERE c.document_id = ?',
                );
                $rowsCjk->execute([$documentId]);
                $delCjk = $pdo->prepare('DELETE FROM rag_chunks_cjk WHERE rowid = ?');
                foreach ($rowsCjk->fetchAll() as $row) {
                    $delCjk->execute([(int) $row['rowid']]);
                }
            }
        }
        $cursor = $pdo->prepare('DELETE FROM rag_documents WHERE document_id = ?');
        $cursor->execute([$documentId]);
        return $cursor->rowCount() > 0;
    }

    /**
     * @return list<RetrievalDocument>
     */
    public function listDocuments(?string $ownerId = null, ?string $tenantId = null): array
    {
        $pdo = $this->connect();
        [$conditions, $params] = self::ownershipConditions($ownerId, $tenantId, 'd');
        $sql = 'SELECT payload FROM rag_documents d';
        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY d.rowid';        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $documents = array_map(
            static fn (array $row): RetrievalDocument => RetrievalDocument::fromDict(json_decode((string) $row['payload'], true)),
            $rows,
        );
        if ($ownerId === null && $tenantId === null) {
            return $documents;
        }
        // Databases created before the ownership columns were added hold
        // the owner inside the payload only.
        return array_values(array_filter(
            $documents,
            static function (RetrievalDocument $document) use ($ownerId, $tenantId): bool {
                if ($ownerId !== null && $document->ownerId !== $ownerId) {
                    return false;
                }
                if ($tenantId !== null && $document->tenantId !== $tenantId) {
                    return false;
                }
                return true;
            },
        ));
    }

    /**
     * The SQL conditions that restrict a query to the given owner/tenant.
     *
     * The filter has to run IN SQL (V4-08): it used to run in PHP AFTER
     * the `LIMIT`, so the foreign chunks filled the result window and the
     * caller's own chunks were silently dropped.
     *
     * @return array{0: list<string>, 1: list<mixed>}
     */
    private static function ownershipConditions(
        ?string $ownerId,
        ?string $tenantId,
        string $alias,
    ): array {
        $conditions = [];
        $params = [];
        if ($ownerId !== null) {
            $conditions[] = "{$alias}.owner_id = ?";
            $params[] = $ownerId;
        }
        if ($tenantId !== null) {
            $conditions[] = "{$alias}.tenant_id = ?";
            $params[] = $tenantId;
        }
        return [$conditions, $params];
    }

    /**
     * Search the indexed chunks.
     *
     * The scope/owner/tenant filter runs IN SQL (V4-08): the previous
     * implementation fetched `limit * 10` rows and filtered them in PHP,
     * so a corpus dominated by foreign chunks hid the caller's own
     * chunks completely.
     *
     * @return list<RetrievalResult>
     */
    public function search(
        string $query,
        int $limit = 5,
        ?string $scope = null,
        ?string $ownerId = null,
        ?string $tenantId = null,
    ): array {
        if (trim($query) === '') {
            return [];
        }
        if ($limit < 1) {
            throw new \InvalidArgumentException('limit must be at least 1');
        }
        $pdo = $this->connect();
        [$conditions, $params] = self::ownershipConditions($ownerId, $tenantId, 'd');
        if ($scope !== null) {
            $conditions[] = 'd.scope = ?';
            $params[] = $scope;
        }
        // The conditions are appended to the WHERE clause that every
        // query already has.
        $and = $conditions === [] ? '' : (' AND ' . implode(' AND ', $conditions));
        $isCjk = self::containsCjk($query);
        $rows = null;
        if ($this->fts5) {
            // --- CJK path -------------------------------------------------
            // A query that contains CJK is routed to the TRIGRAM index.
            // `unicode61` treats a run of Chinese characters as ONE token,
            // so `乌龙茶` could never match `用户喜欢喝乌龙茶`; the
            // trigram index matches the 3-character windows instead.
            //
            // A term shorter than 3 characters cannot be expressed as
            // trigrams, so such a query goes straight to the LIKE substring
            // scan (which also matches Chinese, just without ranking).
            if ($this->trigram && self::containsCjk($query) && self::hasTrigramLengthTerm($query)) {
                $escaped = self::escapeTrigramQuery($query);
                if ($escaped !== null) {
                    try {
                        $stmt = $pdo->prepare(
                            'SELECT c.*, d.payload, bm25(rag_chunks_cjk) AS rank
                             FROM rag_chunks_cjk
                             JOIN rag_chunks c ON c.chunk_id = rag_chunks_cjk.chunk_id
                             JOIN rag_documents d ON d.document_id = c.document_id
                             WHERE rag_chunks_cjk MATCH ?' . $and . '
                             ORDER BY rank
                             LIMIT ?',
                        );
                        $stmt->execute([$escaped, ...$params, $limit]);
                        $rows = $stmt->fetchAll();
                        if ($rows === []) {
                            // The trigram index found nothing: fall through
                            // to the LIKE substring scan, which also matches
                            // Chinese (just without ranking).
                            $rows = null;
                        }
                    } catch (\PDOException) {
                        $rows = null;
                    }
                }
            }
            // --- default path ---------------------------------------------
            if ($rows === null && !$isCjk) {
                $escaped = self::escapeFtsQuery($query);
                if ($escaped !== null) {
                    try {
                        $stmt = $pdo->prepare(
                            'SELECT c.*, d.payload, bm25(rag_chunks_fts) AS rank
                             FROM rag_chunks_fts
                             JOIN rag_chunks c ON c.chunk_id = rag_chunks_fts.chunk_id
                             JOIN rag_documents d ON d.document_id = c.document_id
                             WHERE rag_chunks_fts MATCH ?' . $and . '
                             ORDER BY rank
                             LIMIT ?',
                        );
                        $stmt->execute([$escaped, ...$params, $limit]);
                        $rows = $stmt->fetchAll();
                    } catch (\PDOException) {
                        // Malformed query after escaping (e.g. bare operators):
                        // fall back to LIKE instead of throwing (H7).
                        $rows = null;
                    }
                }
            }
            if ($rows === null) {
                $stmt = $pdo->prepare(
                    'SELECT c.*, d.payload, NULL AS rank
                     FROM rag_chunks c
                     JOIN rag_documents d ON d.document_id = c.document_id
                     WHERE lower(c.content) LIKE ?' . $and . '
                     ORDER BY c.rowid
                     LIMIT ?',
                );
                $stmt->execute(['%' . strtolower($query) . '%', ...$params, $limit]);
                $rows = $stmt->fetchAll();
            }
        } else {
            $stmt = $pdo->prepare(
                'SELECT c.*, d.payload, NULL AS rank
                 FROM rag_chunks c
                 JOIN rag_documents d ON d.document_id = c.document_id
                 WHERE lower(c.content) LIKE ?' . $and . '
                 ORDER BY c.rowid
                 LIMIT ?',
            );
            $stmt->execute(['%' . strtolower($query) . '%', ...$params, $limit]);
            $rows = $stmt->fetchAll();
        }
        $results = [];
        foreach ($rows as $row) {
            $document = RetrievalDocument::fromDict(json_decode((string) $row['payload'], true));
            // The SQL filter already applied the ownership rules; the
            // checks below are the safety net for databases created before
            // the ownership columns existed.
            if ($scope !== null && $document->scope !== $scope) {
                continue;
            }
            if ($ownerId !== null && $document->ownerId !== $ownerId) {
                continue;
            }
            if ($tenantId !== null && $document->tenantId !== $tenantId) {
                continue;
            }
            $results[] = self::toResult($row, $document);
            if (count($results) >= $limit) {
                break;
            }
        }
        return $results;
    }

    public function readChunk(string $chunkId, ?string $ownerId = null, ?string $tenantId = null): ?RetrievalResult
    {
        $pdo = $this->connect();
        $stmt = $pdo->prepare(
            'SELECT c.*, d.payload, NULL AS rank
             FROM rag_chunks c JOIN rag_documents d ON d.document_id = c.document_id
             WHERE c.chunk_id = ?',
        );
        $stmt->execute([$chunkId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $document = RetrievalDocument::fromDict(json_decode((string) $row['payload'], true));
        // Ownership filter (H9): a leaked chunk id must not cross tenant or
        // owner boundaries.
        if ($ownerId !== null && $document->ownerId !== $ownerId) {
            return null;
        }
        if ($tenantId !== null && $document->tenantId !== $tenantId) {
            return null;
        }
        return self::toResult($row, $document);
    }

    /**
     * Stamp the CALLER's identity onto a document.
     *
     * A payload that already names a DIFFERENT owner or tenant is
     * REFUSED instead of being silently rewritten: a mismatch is either a
     * confused caller or an attempt to file a document under somebody
     * else's identity, and both deserve a loud failure.
     *
     * @param RetrievalDocument|array<string, mixed> $document
     */
    private function bindIdentity(mixed $document, ?string $ownerId, ?string $tenantId): RetrievalDocument
    {
        $value = $document instanceof RetrievalDocument ? $document : RetrievalDocument::fromDict($document);
        if ($ownerId !== null && $value->ownerId !== null && $value->ownerId !== $ownerId) {
            throw new \RuntimeException(sprintf(
                'refusing to ingest document `%s` as owner `%s`: it claims owner `%s`',
                $value->documentId,
                $ownerId,
                $value->ownerId,
            ));
        }
        if ($tenantId !== null && $value->tenantId !== null && $value->tenantId !== $tenantId) {
            throw new \RuntimeException(sprintf(
                'refusing to ingest document `%s` in tenant `%s`: it claims tenant `%s`',
                $value->documentId,
                $tenantId,
                $value->tenantId,
            ));
        }
        return $value->withIdentity($ownerId, $tenantId);
    }

    /**
     * True when the text contains CJK (Chinese / Japanese / Korean) characters.
     */
    private static function containsCjk(string $text): bool
    {
        return preg_match(
            '/[\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}\x{F900}-\x{FAFF}]/u',
            $text,
        ) === 1;
    }

    /**
     * True when at least one term of the query is long enough to be
     * expressed as trigrams (3 characters or more).
     */
    private static function hasTrigramLengthTerm(string $query): bool
    {
        foreach (preg_split('/\s+/u', trim($query)) ?: [] as $term) {
            $term = trim($term);
            if ($term !== '' && mb_strlen($term) >= 3) {
                return true;
            }
        }
        return false;
    }

    /**
     * Wrap a query into a trigram-index phrase expression.
     *
     * The trigram tokenizer matches CHARACTER WINDOWS, so the query is
     * quoted per term and the terms are combined with an implicit AND.
     * Unlike the unicode61 path the boolean operators are NOT preserved:
     * under the trigram tokenizer `AND` is a literal three-character window,
     * not an operator, so keeping it would silently change the query.
     */
    private static function escapeTrigramQuery(string $query): ?string
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }
        $quoted = [];
        foreach (preg_split('/\s+/u', $query) ?: [] as $term) {
            $term = trim($term);
            if ($term === '') {
                continue;
            }
            // A term made purely of FTS5 syntax characters is not searchable.
            if (preg_match('/^["*()^:\-]+$/u', $term) === 1) {
                continue;
            }
            $quoted[] = '"' . str_replace('"', '""', $term) . '"';
        }
        if ($quoted === []) {
            return null;
        }
        return implode(' ', $quoted);
    }

    /**
     * Wrap a user query into a safe FTS5 phrase expression.
     *
     * Returns null when the query contains no searchable term (bare special
     * characters such as a bare `*` or an unclosed quote), so the caller
     * falls back to LIKE. Each term is double-quoted with internal quotes
     * doubled (H7). Boolean/NEAR operators are preserved (A8): standalone
     * OR / AND / NOT / NEAR(...) tokens keep their FTS5 meaning instead of
     * silently becoming literal search words.
     */
    private static function escapeFtsQuery(string $query): ?string
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }
        $terms = preg_split('/\s+/u', $query) ?: [];
        $quoted = [];
        foreach ($terms as $term) {
            $clean = trim($term);
            if ($clean === '') {
                continue;
            }
            // Boolean operators keep their native semantics (A8).
            if (in_array(strtoupper($clean), ['OR', 'AND', 'NOT'], true)) {
                $quoted[] = strtoupper($clean);
                continue;
            }
            // NEAR(...) phrase: keep verbatim when syntactically intact.
            if (preg_match('/^NEAR\([a-zA-Z0-9\s"\']+(,\s*\d+)?\)$/i', $clean) === 1) {
                $quoted[] = $clean;
                continue;
            }
            // Trailing * keeps FTS5 prefix semantics ("alpha"*).
            $prefix = str_ends_with($clean, '*') ? '*' : '';
            $clean = rtrim($clean, '*');
            // A term made purely of FTS5 syntax characters is not searchable.
            if ($clean === '' || preg_match('/^["*()^:\-]+$/u', $clean) === 1) {
                continue;
            }
            $quoted[] = '"' . str_replace('"', '""', $clean) . '"' . $prefix;
        }
        if ($quoted === []) {
            return null;
        }
        return implode(' ', $quoted);
    }

    public function reindex(): void
    {
        if (!$this->fts5) {
            return;
        }
        $pdo = $this->connect();
        $pdo->exec('DELETE FROM rag_chunks_fts');
        $insert = $pdo->prepare('INSERT INTO rag_chunks_fts(chunk_id, content) VALUES (?, ?)');
        foreach ($pdo->query('SELECT chunk_id, content FROM rag_chunks ORDER BY rowid')->fetchAll() as $row) {
            $insert->execute([(string) $row['chunk_id'], (string) $row['content']]);
        }
        if ($this->trigram) {
            $pdo->exec('DELETE FROM rag_chunks_cjk');
            $insertCjk = $pdo->prepare('INSERT INTO rag_chunks_cjk(chunk_id, content) VALUES (?, ?)');
            foreach ($pdo->query('SELECT chunk_id, content FROM rag_chunks ORDER BY rowid')->fetchAll() as $row) {
                $insertCjk->execute([(string) $row['chunk_id'], (string) $row['content']]);
            }
        }
    }

    public function invoke(string $capability, mixed ...$arguments): mixed
    {
        $method = substr($capability, 4); // strip "rag."
        switch ($method) {
            case 'ingest':
                // The identity comes from the INVOCATION CONTEXT, never
                // from the payload: the payload is model-supplied, so
                // taking the owner from it would let any principal file a
                // document under somebody else's identity (H-7).
                [$owner, $tenant] = $this->resolveIdentity();
                return $this->ingest($this->bindIdentity(
                    $arguments['document'] ?? $arguments[0] ?? [],
                    $owner,
                    $tenant,
                ));
            case 'remove':
                // The identity comes from the INVOCATION CONTEXT, never
                // from the arguments (H-7).
                [$owner, $tenant] = $this->resolveIdentity();
                return $this->remove(
                    (string) ($arguments['document_id'] ?? $arguments[0] ?? ''),
                    $owner,
                    $tenant,
                );
            case 'list_documents':
                [$owner, $tenant] = $this->resolveIdentity();
                return $this->listDocuments($owner, $tenant);
            case 'search':
                [$owner, $tenant] = $this->resolveIdentity();
                return $this->search(
                    (string) ($arguments['query'] ?? $arguments[0] ?? ''),
                    (int) ($arguments['limit'] ?? 5),
                    $arguments['scope'] ?? null,
                    // The caller's identity OVERRIDES the model-supplied
                    // owner/tenant arguments.
                    $owner,
                    $tenant,
                );
            case 'read_chunk':
                [$owner, $tenant] = $this->resolveIdentity();
                return $this->readChunk(
                    (string) ($arguments['chunk_id'] ?? $arguments[0] ?? ''),
                    $owner,
                    $tenant,
                );
            case 'reindex':
                return $this->reindex();
            default:
                throw new \RuntimeException("unknown capability: {$capability}");
        }
    }

    /**
     * The identity of the caller, taken from the INVOCATION CONTEXT.
     *
     * The context is bound by the registry right before the provider runs
     * (`CapabilityRegistry::invoke()` → `bindContext()`), so it is the
     * only trustworthy source of "who is calling". The model-supplied
     * `owner_id`/`tenant_id` arguments are ignored: a caller that simply
     * omits them (or names somebody else) used to read or delete ANY
     * tenant's document (H-7).
     *
     * When no identity is available the call FAILS CLOSED — "no owner"
     * must never mean "unrestricted".
     *
     * @return array{0: string|null, 1: string|null} the owner and tenant
     */
    private function resolveIdentity(): array
    {
        $context = $this->invocationContext ?? $this->context;
        $owner = $context?->userId;
        $tenant = $context?->tenantId;
        if (($owner === null || $owner === '') && ($tenant === null || $tenant === '')) {
            $security = $context?->securityContext;
            if ($security instanceof \LightAgent\Security\SecurityContext) {
                $owner = $owner ?? $security->userId;
                $tenant = $tenant ?? $security->tenantId;
            }
        }
        if (($owner === null || $owner === '') && ($tenant === null || $tenant === '')) {
            throw new \RuntimeException(
                'the RAG capability requires an identified invocation context; '
                . 'the caller identity is never taken from the request arguments',
            );
        }
        return [
            ($owner === null || $owner === '') ? null : $owner,
            ($tenant === null || $tenant === '') ? null : $tenant,
        ];
    }

    /**
     * @return list<string>
     */
    private function chunks(string $content): array
    {
        if ($content === '') {
            return [''];
        }
        $chunks = [];
        $step = $this->chunkSize - $this->chunkOverlap;
        $length = mb_strlen($content);
        for ($index = 0; $index < $length; $index += $step) {
            $chunks[] = mb_substr($content, $index, $this->chunkSize);
        }
        return $chunks === [] ? [''] : $chunks;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toResult(array $row, RetrievalDocument $document): RetrievalResult
    {
        $rank = $row['rank'] ?? null;
        return new RetrievalResult(
            documentId: $document->documentId,
            chunkId: (string) $row['chunk_id'],
            content: (string) $row['content'],
            citationId: 'rag:' . $row['chunk_id'],
            title: $document->title,
            source: $document->source,
            position: (int) $row['position'],
            score: $rank === null ? null : (float) (-1 * (float) $rank),
            scope: $document->scope,
            ownerId: $document->ownerId,
            tenantId: $document->tenantId,
            metadata: $document->metadata,
        );
    }
}
