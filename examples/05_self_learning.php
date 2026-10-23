<?php

declare(strict_types=1);

/**
 * Example 05 — Self-learning (memory promotion candidates).
 * Python counterpart: example/05.self_learning.py
 * Demonstrates that internal (reflection) memories become non-injectable
 * candidates requiring explicit promotion.
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/ExampleHelper.php';

use LightAgent\Memory\SharedMemoryPool;
use LightAgent\Protocol\MemoryCandidate;
use LightAgent\Protocol\MemoryPromotionDecision;

error_reporting(E_ALL & ~E_DEPRECATED);

ExampleHelper::header('05 — Self-learning memory promotion');

$pool = new SharedMemoryPool();

// Simulate a reflection candidate (the agent creates these internally)
$candidate = new MemoryCandidate(
    data: 'User seems to prefer concise answers',
    memoryUserId: 'agent',
    originalUserId: 'alice',
    source: 'reflection',
    scope: 'agent',
    agentName: 'writer',
);

echo "candidate status: {$candidate->status}\n";
echo "candidate injectable: " . var_export($candidate->injectable, true) . "\n";
echo "candidate id: {$candidate->id()}\n";

// Promote it explicitly
$promoted = $candidate->withUpdates([
    'status' => 'promoted',
    'injectable' => true,
    'metadata' => array_merge($candidate->toMetadata(), [
        'promotion_status' => 'promoted',
        'injectable' => true,
    ]),
]);

echo "\nafter promotion:\n";
echo "  status: {$promoted->status}\n";
echo "  injectable: " . var_export($promoted->injectable, true) . "\n";
echo "  metadata.promotion_status: {$promoted->toMetadata()['promotion_status']}\n";

// Store the promoted memory in the pool
$pool->store($promoted->data, $promoted->memoryUserId, metadata: $promoted->toMetadata());
echo "\npool records after store: " . $pool->count() . "\n";

ExampleHelper::done('05');
