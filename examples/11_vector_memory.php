<?php

declare(strict_types=1);

/**
 * Example 11 — Vector memory adapter (a CUSTOM MemoryProtocol backend).
 *
 * Python counterpart: example/11.vector_memory_adapter.py
 *
 * The Python original demonstrates a custom memory adapter: a local
 * "vector memory" backend that plugs into LightAgent through the
 * MemoryProtocol seam (`store` / `retrieve`) WITHOUT depending on any one
 * vector database.
 *
 * The PHP example of the same number used to demo the BUILT-IN SQLite FTS5
 * KNOWLEDGE-BASE provider instead — the opposite meaning under the same
 * name, which taught the reader the wrong thing. That demo now lives in
 * examples/12_rag_fts5.php.
 *
 * Everything here is dependency-free: no vector DB, no network, no model
 * call beyond the offline mock. The point of the example is the SEAM —
 * replace the in-memory list with Qdrant / Chroma / Milvus / FAISS / mem0 /
 * ClawMem while keeping the same two methods.
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/ExampleHelper.php';

use LightAgent\Core\LightAgent;
use LightAgent\Hooks\HookContext;
use LightAgent\Protocol\MemoryPolicy;
use LightAgent\Protocol\MemoryProtocol;
use LightAgent\Protocol\MemoryScope;

error_reporting(E_ALL & ~E_DEPRECATED);

/**
 * A local "vector memory" backend behind the MemoryProtocol seam.
 *
 * The records are kept in a plain list; the scoring is a token overlap.
 * A real adapter would call its embedding model and store the vectors in
 * the selected vector database — but the SIGNATURE stays the same, which
 * is the whole point of the seam.
 */
final class LocalVectorMemoryAdapter implements MemoryProtocol
{
    /**
     * @var list<array{memory: string, user_id: string, tokens: list<string>, metadata: array<string, mixed>}>
     */
    private array $records = [];

    public function __construct(
        public readonly string $agentName = 'example-agent',
        public readonly int $topK = 3,
    ) {
    }

    /**
     * Append a memory record and return the storage receipt.
     *
     * The third argument is OPTIONAL and is NOT part of the MemoryProtocol
     * interface: the framework passes its own provenance metadata
     * (source / scope / agent_name / trace_id) when it writes a memory, and
     * the adapter honours it. A plain `store($data, $userId)` call falls
     * back to the default USER scope — the same default the Python
     * original uses (`MemoryScope.user(...)`).
     *
     * @param array<string, mixed>|null $metadata
     * @return array<string, mixed>
     */
    public function store(string $data, string $userId, ?array $metadata = null): mixed
    {
        $recordMetadata = MemoryScope::user(
            agentName: $this->agentName,
            metadata: ['user_id' => $userId],
        )->toMetadata();
        if ($metadata !== null && $metadata !== []) {
            // The framework's own provenance wins over the adapter default.
            $recordMetadata = array_merge($recordMetadata, $metadata);
        }
        $recordMetadata['user_id'] = $userId;

        $this->records[] = [
            'memory' => $data,
            'user_id' => $userId,
            'tokens' => self::tokenize($data),
            'metadata' => $recordMetadata,
        ];

        return ['stored' => true, 'user_id' => $userId];
    }

    /**
     * Retrieve the memories of ONE user, ranked by shared-token count.
     *
     * @return array{results: list<array<string, mixed>>}
     */
    public function retrieve(string $query, string $userId): mixed
    {
        $queryTokens = self::tokenize($query);
        if ($queryTokens === []) {
            // A query without meaningful terms carries no signal.
            return ['results' => []];
        }

        $scored = [];
        foreach ($this->records as $index => $record) {
            if ($record['user_id'] !== $userId) {
                continue;
            }
            $score = count(array_intersect($queryTokens, $record['tokens']));
            if ($score > 0) {
                $scored[] = [$score, $index, $record];
            }
        }
        // Highest score first; the insertion order breaks a tie.
        usort($scored, static fn (array $a, array $b): int => ($b[0] <=> $a[0]) ?: ($a[1] <=> $b[1]));

        $results = [];
        foreach ($scored as [$score, , $record]) {
            if (count($results) >= $this->topK) {
                break;
            }
            $results[] = [
                'memory' => $record['memory'],
                'score' => (float) $score,
                'user_id' => $record['user_id'],
                'metadata' => $record['metadata'],
            ];
        }
        return ['results' => $results];
    }

    /**
     * Split a text into scoring tokens.
     *
     * English (and any space-delimited script) is split on whitespace, the
     * surrounding punctuation is stripped and the stopwords are dropped.
     *
     * CJK has no word boundaries, so a Chinese sentence would be ONE token
     * and only an identical query could ever match it. Every CJK run
     * therefore ALSO contributes its character bigrams: `乌龙茶` yields
     * `乌龙` and `龙茶`, both of which occur in `用户喜欢喝乌龙茶`.
     *
     * This is the defect the Python original's toy embedding has:
     * `Counter(findall(r"[a-z0-9]+", text.lower()))` produces an EMPTY
     * embedding for Chinese, so it recalls nothing at all.
     *
     * @return list<string>
     */
    private static function tokenize(string $text): array
    {
        $tokens = [];
        foreach (preg_split('/\s+/', mb_strtolower($text)) ?: [] as $token) {
            $clean = preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $token) ?? $token;
            if ($clean === '' || in_array($clean, self::STOPWORDS, true)) {
                continue;
            }
            $tokens[$clean] = true;
            foreach (self::cjkBigrams($clean) as $bigram) {
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
        if (preg_match_all(
            '/[\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}]+/u',
            $text,
            $runs,
        ) === false) {
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

    /** @var list<string> */
    private const STOPWORDS = ['a', 'an', 'and', 'are', 'as', 'at', 'be', 'but', 'by',
        'for', 'from', 'had', 'has', 'have', 'he', 'her', 'his', 'i', 'in', 'is',
        'it', 'its', 'my', 'not', 'of', 'on', 'or', 'our', 'she', 'so', 'that',
        'the', 'their', 'them', 'there', 'they', 'this', 'to', 'was', 'we',
        'were', 'will', 'with', 'you', 'your'];
}

/**
 * Print the memory block the agent actually injected into the prompt.
 *
 * The hook is a plain closure: the framework calls it for EVERY phase, so
 * it has to pick the phase it cares about itself.
 */
$printRecalledMemory = static function (HookContext $context): void {
    if ($context->phase !== 'before_model_request') {
        return;
    }
    $params = $context->payload['params'] ?? [];
    $messages = is_array($params['messages'] ?? null) ? $params['messages'] : [];
    foreach ($messages as $message) {
        $content = (string) ($message['content'] ?? '');
        if (!str_contains($content, '##用户偏好')) {
            continue;
        }
        // Only the recalled-memory block, not the question that follows.
        $position = strpos($content, '##用户提问');
        $block = $position === false ? $content : substr($content, 0, $position);
        echo "  recalled memory injected into the prompt:\n";
        foreach (explode("\n", $block) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            echo "    {$line}\n";
        }
    }
};

ExampleHelper::header('11 — Vector memory adapter (custom MemoryProtocol)');

$baseUrl = ExampleHelper::startMockServer('plain');

$memory = new LocalVectorMemoryAdapter(agentName: 'travel-agent');
$policy = new MemoryPolicy(
    namespace: 'demo',
    allowUnattributedResults: false,
    allowedSources: ['user'],
    allowedScopes: ['user'],
);

// The framework scopes BOTH the read and the write side with the
// namespace, so a preference has to be stored under the SAME scoped id —
// writing with the raw id and reading with the scoped id silently returns
// nothing.
$scopedUserId = $policy->scopedUserId('user_01');

$agent = new LightAgent([
    'model' => 'gpt-4o-mini',
    'api_key' => 'example-key',
    'base_url' => $baseUrl,
    'instructions' => 'You are LightAgent with a custom vector memory adapter.',
    'memory' => $memory,
    'memory_policy' => $policy,
    'auto_discover_skills' => false,
    'hooks' => [$printRecalledMemory],
]);

echo 'adapter satisfies MemoryProtocol: '
    . var_export($memory instanceof MemoryProtocol, true) . "\n";

// Store a Chinese preference. Replace the in-memory list with Qdrant /
// Chroma / Milvus / FAISS / mem0 / ClawMem while keeping the same two
// methods (`store` / `retrieve`).
$memory->store('用户喜欢喝乌龙茶', $scopedUserId);

// Retrieve it with a SHORTER Chinese query. The Python original's toy
// embedding is EMPTY for Chinese and recalls nothing here.
echo "\nretrieve '乌龙茶' from the adapter:\n";
$recalled = $memory->retrieve('乌龙茶', $scopedUserId);
foreach ($recalled['results'] as $result) {
    printf(
        "  %s (score=%.0f, source=%s, scope=%s)\n",
        $result['memory'],
        $result['score'],
        $result['metadata']['source'] ?? '-',
        $result['metadata']['scope'] ?? '-',
    );
}

// A different user never sees another user's memory.
$otherUser = $memory->retrieve('乌龙茶', $policy->scopedUserId('user_02'));
echo 'another user sees: ' . count($otherUser['results']) . " record(s)\n";

// The same round trip THROUGH the agent: it retrieves the preference and
// injects it into the prompt as the "##用户偏好" block.
echo "\nagent round trip (query: '乌龙茶'):\n";
$answer = $agent->run('乌龙茶', ['user_id' => 'user_01']);
echo "  agent answered: {$answer}\n";

ExampleHelper::stopMockServer();
ExampleHelper::done('11');
