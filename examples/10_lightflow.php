<?php

declare(strict_types=1);

/**
 * Example 10 — LightFlow workflow.
 * Python counterpart: example/10.lightflow.py
 * Multi-step workflow: research → write → review, with checkpoint/resume.
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/ExampleHelper.php';

use LightAgent\Flow\JsonLightFlowStore;
use LightAgent\Flow\LightFlow;
use LightAgent\Result\RunResult;

error_reporting(E_ALL & ~E_DEPRECATED);

ExampleHelper::header('10 — LightFlow workflow');

$tmpDir = sys_get_temp_dir() . '/la_flow_example_' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0777, true);

// Simulated agents (offline — no model needed)
$research = new class {
    public function run(string $query, array $kwargs = []): RunResult
    {
        return new RunResult(content: 'research facts: PHP 8.1+ / Amp kernel / SQLite', trace: []);
    }
};
$write = new class {
    public function run(string $query, array $kwargs = []): RunResult
    {
        return new RunResult(content: 'article draft based on: ' . $query, trace: []);
    }
};

$flow = (new LightFlow(store: new JsonLightFlowStore($tmpDir)))
    ->step('research', agent: $research)
    ->step('write', agent: $write, dependsOn: ['research']);

$result = $flow->run('write about the LightAgent PHP port', ['trace' => true]);
echo "status: {$result->status}\n";
echo "content: {$result->content}\n";
echo "steps: ";
foreach ($result->steps as $step) {
    echo "{$step->name}({$step->status}) ";
}
echo "\n";

// The write step receives research output via "Previous step outputs:"
$writeResult = $result->steps[1];
echo "\nwrite input_summary: {$writeResult->inputSummary}\n";

// Trace event types
echo "trace events: " . implode(' → ', array_map(
    static fn (array $e): string => $e['type'],
    $result->trace,
)) . "\n";

// Cleanup
foreach (glob($tmpDir . '/*.json') ?: [] as $f) {
    @unlink($f);
}
@rmdir($tmpDir);

ExampleHelper::done('10');
