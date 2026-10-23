<?php

declare(strict_types=1);

/**
 * Example 03 — Memory with SharedMemoryPool.
 * Python counterpart: example/03.memory_mem0.py (uses the built-in pool
 * instead of mem0, keeping the example fully offline).
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/ExampleHelper.php';

use LightAgent\Memory\SharedMemoryPool;

error_reporting(E_ALL & ~E_DEPRECATED);

ExampleHelper::header('03 — Memory (SharedMemoryPool as MemoryProtocol)');

$pool = new SharedMemoryPool(agentName: 'memory-agent');

// Store memories for two users
$pool->store('Alice prefers quiet beach towns', 'alice');
$pool->store('Alice is learning PHP', 'alice');
$pool->store('Bob prefers crowded ski resorts', 'bob');

// Retrieve with token-overlap ranking
$aliceMemories = $pool->retrieve('php learning', 'alice');
echo "alice memories matching 'php learning':\n";
foreach ($aliceMemories['results'] as $r) {
    echo "  - {$r['memory']} (metadata.source={$r['metadata']['source']})\n";
}

// Provenance filtering
$pool->store('internal reflection', 'agent', metadata: ['source' => 'reflection', 'scope' => 'agent']);
$reflections = $pool->listRecords(source: 'reflection');
echo "\nreflections: " . count($reflections) . " record(s)\n";

// MemoryProtocol shape — the pool plugs directly into LightAgent memory=
echo "\npool satisfies MemoryProtocol: "
    . var_export($pool instanceof \LightAgent\Protocol\MemoryProtocol, true) . "\n";
echo "total records: " . $pool->count() . "\n";

ExampleHelper::done('03');
