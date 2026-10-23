<?php

declare(strict_types=1);

namespace LightAgent\Memory;

use LightAgent\Protocol\MemoryProtocol;
use LightAgent\Protocol\MemoryScope;

/**
 * In-memory append-first shared memory backend (shared_memory.py SharedMemoryPool).
 *
 * Satisfies MemoryProtocol: store(data, user_id) / retrieve(query, user_id).
 * Designed as a lightweight prototype for LightSwarm/LightFlow experiments,
 * not as durable storage.
 *
 * CAPACITY (V5-46): the pool is bounded. It is an APPEND-FIRST store, so
 * the OLDEST records are evicted when the capacity is exceeded — the most
 * recent memories are the ones an agent still reasons about.
 *
 * USER ID (V5-14): the user id is the SCOPED id. An agent that uses
 * `MemoryPolicy::scopedUserId()` writes and reads `"{namespace}:{user}"`,
 * so a record stored under the RAW id (`alice`) is NOT found by a
 * namespaced read (`tenant-a:alice`). Use
 * `MemoryPolicy::scopedUserId($userId)` on BOTH sides — writing with the
 * raw id and reading with the scoped id silently returns nothing.
 */
final class SharedMemoryPool implements MemoryProtocol
{
    /** @var list<SharedMemoryRecord> */
    private array $records = [];

    public function __construct(
        public readonly ?string $agentName = null,
        public readonly string $defaultSource = 'user',
        public readonly string $defaultScope = 'user',
        public readonly int $maxResults = 5,
        /**
         * Maximum number of records kept in the pool. The OLDEST records
         * are evicted when the limit is exceeded.
         */
        public readonly int $maxRecords = 1000,
    ) {
        if ($this->maxResults <= 0) {
            throw new \InvalidArgumentException('max_results must be greater than 0');
        }
        if ($this->maxRecords <= 0) {
            throw new \InvalidArgumentException('max_records must be greater than 0');
        }
    }

    /**
     * Append a memory record and return storage metadata.
     *
     * The user id is the SCOPED id: an agent that uses
     * `MemoryPolicy::scopedUserId()` writes `"{namespace}:{user}"` here and
     * reads the same id back. Writing with the RAW id and reading with the
     * scoped id silently returns nothing (V5-14).
     *
     * @param array<string, mixed>|null $metadata
     * @return array<string, mixed>
     */
    public function store(string $data, string $userId, ?array $metadata = null, ?MemoryScope $scope = null): array
    {
        $recordMetadata = $this->buildMetadata($userId, $metadata, $scope);
        $record = new SharedMemoryRecord(
            memory: (string) $data,
            userId: (string) $userId,
            metadata: $recordMetadata,
        );
        $this->records[] = $record;
        $this->evictOverflow();
        return ['stored' => true, 'memory_id' => $record->memoryId, 'user_id' => $record->userId];
    }

    /**
     * Retrieve scoped memories using simple token overlap ranking.
     *
     * The user id is the SCOPED id — see `MemoryPolicy::scopedUserId()`.
     *
     * A query that tokenises to an EMPTY set carries no signal: it is
     * either empty or made only of stopwords ("to", "the"). Returning
     * every stored memory for such a query is relevance-blind — the
     * stopword list exists precisely to prevent those spurious matches —
     * so the result is empty (M-28). Use `listRecords()` to enumerate
     * the stored memories.
     *
     * @return array{results: list<array<string, mixed>>}
     */
    public function retrieve(
        string $query,
        string $userId,
        ?string $agentName = null,
        ?string $source = null,
        ?string $scope = null,
        ?int $limit = null,
    ): array {
        $maxResults = $limit ?? $this->maxResults;
        if ($maxResults <= 0) {
            return ['results' => []];
        }

        $queryTokens = self::tokenize($query);
        if ($queryTokens === []) {
            // No meaningful query terms -> no results (M-28).
            return ['results' => []];
        }
        $candidates = [];
        foreach ($this->records as $record) {
            if ($this->recordMatches($record, (string) $userId, $agentName, $source, $scope)) {
                $candidates[] = $record;
            }
        }

        $scored = [];
        foreach ($candidates as $index => $record) {
            $scored[] = [self::score($queryTokens, $record), $index, $record];
        }
        usort($scored, static function (array $a, array $b): int {
            return ($b[0] <=> $a[0]) ?: ($b[1] <=> $a[1]);
        });

        $results = [];
        foreach ($scored as [$score, , $record]) {
            if (count($results) >= $maxResults) {
                break;
            }
            if ($score > 0) {
                $results[] = $record->toResult();
            }
        }
        return ['results' => $results];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRecords(
        ?string $userId = null,
        ?string $agentName = null,
        ?string $source = null,
        ?string $scope = null,
    ): array {
        $out = [];
        foreach ($this->records as $record) {
            if ($this->recordMatches($record, $userId, $agentName, $source, $scope)) {
                $out[] = $record->toResult();
            }
        }
        return $out;
    }

    public function clear(?string $userId = null): int
    {
        if ($userId === null) {
            $count = count($this->records);
            $this->records = [];
            return $count;
        }
        $before = count($this->records);
        $this->records = array_values(array_filter(
            $this->records,
            static fn (SharedMemoryRecord $r): bool => $r->userId !== (string) $userId,
        ));
        return $before - count($this->records);
    }

    public function count(): int
    {
        return count($this->records);
    }

    /**
     * Drop the OLDEST records until the pool fits its capacity.
     *
     * The pool is append-first, so the newest records are the valuable
     * ones: the eviction policy is FIFO (V5-46).
     */
    private function evictOverflow(): void
    {
        $overflow = count($this->records) - $this->maxRecords;
        if ($overflow <= 0) {
            return;
        }
        $this->records = array_slice($this->records, $overflow);
    }

    /**
     * @param array<string, mixed>|null $metadata
     * @return array<string, mixed>
     */
    private function buildMetadata(string $userId, ?array $metadata, ?MemoryScope $scope): array
    {
        if ($scope !== null) {
            $recordMetadata = $scope->toMetadata();
        } else {
            $recordMetadata = (new MemoryScope(
                source: $this->defaultSource,
                scope: $this->defaultScope,
                agentName: $this->agentName,
                metadata: ['user_id' => (string) $userId],
            ))->toMetadata();
        }
        if ($metadata !== null && $metadata !== []) {
            $recordMetadata = array_merge($recordMetadata, $metadata);
        }
        if (!array_key_exists('user_id', $recordMetadata)) {
            $recordMetadata['user_id'] = (string) $userId;
        }
        return $recordMetadata;
    }

    private function recordMatches(
        SharedMemoryRecord $record,
        ?string $userId,
        ?string $agentName,
        ?string $source,
        ?string $scope,
    ): bool {
        $metadata = $record->metadata;
        if ($userId !== null && $record->userId !== (string) $userId) {
            return false;
        }
        if ($agentName !== null && ($metadata['agent_name'] ?? null) !== $agentName) {
            return false;
        }
        if ($source !== null && ($metadata['source'] ?? null) !== $source) {
            return false;
        }
        if ($scope !== null && ($metadata['scope'] ?? null) !== $scope) {
            return false;
        }
        return true;
    }

    /**
     * Split a text into scoring tokens.
     *
     * English (and any space-delimited script) is split on whitespace, the
     * surrounding punctuation is stripped and the stopwords are dropped.
     *
     * CJK text has no word boundaries, so a Chinese sentence would be ONE
     * token and only an identical query could ever match it — `乌龙茶` could
     * not recall `用户喜欢喝乌龙茶`, and the whole recall path silently
     * returned nothing. To give the scorer a shared vocabulary, every CJK
     * run additionally contributes its character bigrams: `乌龙茶` yields
     * `乌龙` and `龙茶`, both of which occur in the longer memory. The
     * English path is bit-for-bit unchanged (a token without CJK contributes
     * no bigrams).
     *
     * @return list<string>
     */
    private static function tokenize(string $text): array
    {
        $tokens = [];
        foreach (preg_split('/\s+/', mb_strtolower($text)) ?: [] as $token) {
            // Strip surrounding punctuation (A-followup: "peanuts." must match
            // the query "peanuts") and skip stopwords that otherwise produce
            // spurious matches ("food to avoid" hit records via "to").
            $clean = preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $token) ?? $token;
            if ($clean === '' || in_array($clean, self::STOPWORDS, true)) {
                continue;
            }
            $tokens[$clean] = true;
            foreach (self::cjkBigrams($clean) as $bigram) {
                if (in_array($bigram, self::CJK_STOPWORDS, true)) {
                    continue;
                }
                $tokens[$bigram] = true;
            }
        }
        return array_keys($tokens);
    }

    /**
     * The character bigrams of every CJK run in the text.
     *
     * @return list<string>
     */
    private static function cjkBigrams(string $text): array
    {
        if (preg_match(self::CJK_PATTERN, $text) !== 1) {
            return [];
        }
        if (preg_match_all('/[\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}]+/u', $text, $runs) === false) {
            return [];
        }
        $bigrams = [];
        foreach ($runs[0] as $run) {
            $chars = preg_split('//u', $run, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($chars) < 2) {
                // A single character cannot form a bigram; keep it so a
                // one-character query still has something to match.
                if ($chars !== []) {
                    $bigrams[] = $chars[0];
                }
                continue;
            }
            for ($i = 0, $n = count($chars) - 1; $i < $n; $i++) {
                $bigrams[] = $chars[$i] . $chars[$i + 1];
            }
        }
        return $bigrams;
    }

    /** Matches any CJK (Chinese / Japanese / Korean) character. */
    private const CJK_PATTERN = '/[\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}]/u';

    /**
     * High-frequency Chinese function-word bigrams.
     *
     * They carry no retrieval signal but would otherwise be shared by almost
     * every memory and inflate the score.
     *
     * @var list<string>
     */
    private const CJK_STOPWORDS = ['我们', '你们', '他们', '她们', '它们', '这个', '那个',
        '这些', '那些', '什么', '怎么', '因为', '所以', '但是', '如果', '虽然', '可以',
        '应该', '需要', '已经', '还是', '或者', '以及', '对于', '关于', '通过', '进行',
        '一个', '一些', '一下', '一样', '时候', '现在', '不是', '就是', '知道', '觉得',
        '因为', '然后', '而且', '或者', '由于', '为了', '根据', '按照', '随着', '以及'];

    /** @var list<string> */
    private const STOPWORDS = ['a', 'an', 'and', 'are', 'as', 'at', 'be', 'but', 'by',
        'for', 'from', 'had', 'has', 'have', 'he', 'her', 'his', 'i', 'in', 'is',
        'it', 'its', 'my', 'not', 'of', 'on', 'or', 'our', 'she', 'so', 'that',
        'the', 'their', 'them', 'there', 'they', 'this', 'to', 'was', 'we',
        'were', 'will', 'with', 'you', 'your'];

    /**
     * @param list<string> $queryTokens
     */
    private static function score(array $queryTokens, SharedMemoryRecord $record): int
    {
        if ($queryTokens === []) {
            // A query without meaningful terms matches nothing (M-28).
            return 0;
        }
        $recordTokens = self::tokenize($record->memory);
        return count(array_intersect($queryTokens, $recordTokens));
    }
}
