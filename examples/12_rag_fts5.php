<?php

declare(strict_types=1);

/**
 * Example 12 — Retrieval-augmented generation with the built-in SQLite FTS5
 * knowledge-base provider.
 *
 * This is the KNOWLEDGE-BASE side of the framework:
 * `LightAgent\Knowledge\SqliteFtsRetrievalProvider` implements
 * `LightAgent\Knowledge\RetrievalProvider` (ingest / search / scoped search
 * / remove / reindex) and keeps everything in a local SQLite file — no
 * external service and no embedding model.
 *
 * The Chinese search at the end demonstrates the TRIGRAM index. The default
 * `unicode61` tokenizer treats a run of Chinese characters as ONE token, so
 * `乌龙茶` could never match `用户喜欢喝乌龙茶`; a query that contains CJK is
 * therefore routed to a SECOND FTS5 index built with `tokenize='trigram'`,
 * which indexes every 3-character window. A non-CJK query keeps using the
 * original `unicode61` index, unchanged.
 *
 * (The memory-ADAPTER side of the original example 11 — a custom
 * MemoryProtocol backend — lives in examples/11_vector_memory.php.)
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/ExampleHelper.php';

use LightAgent\Knowledge\RetrievalDocument;
use LightAgent\Knowledge\RetrievalProvider;
use LightAgent\Knowledge\SqliteFtsRetrievalProvider;

error_reporting(E_ALL & ~E_DEPRECATED);

ExampleHelper::header('12 — RAG knowledge base (SQLite FTS5)');

$dbPath = sys_get_temp_dir() . '/la_rag_example_' . bin2hex(random_bytes(4)) . '.db';
$provider = new SqliteFtsRetrievalProvider($dbPath, chunkSize: 200, chunkOverlap: 20);

echo 'provider satisfies RetrievalProvider: '
    . var_export($provider instanceof RetrievalProvider, true) . "\n";

// Ingest knowledge documents
$docId1 = $provider->ingest(new RetrievalDocument(
    content: 'LightAgent is a lightweight agent framework supporting MCP, tool calling, and multi-agent workflows. It uses a ReAct loop with optional Tree-of-Thought planning.',
    title: 'LightAgent Overview',
    source: 'docs/intro',
));
$docId2 = $provider->ingest(new RetrievalDocument(
    content: 'The PHP port uses Amp and Revolt for async, PDO SQLite for persistence, and implements MCP JSON-RPC 2.0 over stdio, SSE, and Streamable HTTP.',
    title: 'PHP Port Architecture',
    source: 'docs/architecture',
));
$docId3 = $provider->ingest(new RetrievalDocument(
    content: '用户喜欢喝乌龙茶，也喜欢喝普洱茶。这是一个中文知识库文档，用来演示三元组索引。',
    title: '用户偏好',
    source: 'docs/preferences',
));

echo "ingested: {$docId1}, {$docId2}, {$docId3}\n";

// Search. FTS5 combines the terms with an implicit AND, so EVERY term has
// to occur in the chunk: "MCP transport" finds nothing here because the
// corpus never uses the word "transport".
$results = $provider->search('MCP transport', limit: 3);
echo "\nsearch 'MCP transport':\n";
foreach ($results as $r) {
    printf("  [%s] %s\n", $r->citationId, substr($r->content, 0, 80));
}

$hits = $provider->search('MCP', limit: 3);
echo "\nsearch 'MCP':\n";
foreach ($hits as $r) {
    printf("  [%s] %s\n", $r->citationId, substr($r->content, 0, 80));
}

// Scoped filtering
$scoped = $provider->search('LightAgent', scope: 'workspace');
echo "\nscoped search results: " . count($scoped) . "\n";

// Chinese search — the query is a SUBSTRING of the document. The trigram
// index is what makes this work; the unicode61 index would return nothing.
$chinese = $provider->search('乌龙茶', limit: 3);
echo "\nsearch '乌龙茶' (chinese, trigram index):\n";
foreach ($chinese as $r) {
    printf("  [%s] %s\n", $r->citationId, $r->content);
}

// A short Chinese term (fewer than 3 characters) cannot be expressed as
// trigrams, so it falls back to the LIKE substring scan — which also
// matches Chinese.
$short = $provider->search('普洱', limit: 3);
echo "\nsearch '普洱' (short chinese term, LIKE fallback): " . count($short) . " result(s)\n";

// An English query does not match the Chinese document, and a Chinese
// query does not match the English documents.
$english = $provider->search('LightAgent', limit: 5);
echo "english query 'LightAgent' -> " . count($english) . " hit(s): ";
echo implode(', ', array_map(static fn ($r): string => (string) $r->title, $english)) . "\n";

// Cleanup
foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $f) {
    @unlink($f);
}

ExampleHelper::done('12');
