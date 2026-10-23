<?php

declare(strict_types=1);

/**
 * PHP side of the differential harness.
 *
 * Reads tests/Differential/scenarios.json, executes every scenario against
 * the PHP port and prints the observations as JSON:
 *
 *   { "<id>": <observed>, ... }
 *
 * The observed value is always a scalar that the Python side can produce
 * with the same meaning:
 *   kind=bool   -> true  = the write / call was ALLOWED
 *                  false = it was BLOCKED
 *   kind=scalar -> an int / string describing the outcome
 *
 * Usage: php php_runner.php <scenarios.json>
 */

require __DIR__ . '/../../autoload.php';

use LightAgent\Capabilities\BaseCapabilityProvider;
use LightAgent\Capabilities\CapabilityRegistry;
use LightAgent\Capabilities\CapabilityScope;
use LightAgent\Capabilities\CapabilitySpec;
use LightAgent\Capabilities\PermissionSet;
use LightAgent\Capabilities\RuntimeContext;
use LightAgent\Hooks\HookContext;
use LightAgent\Hooks\HookManager;
use LightAgent\Hooks\PolicyHook;
use LightAgent\Memory\SharedMemoryPool;
use LightAgent\Protocol\MemoryPolicy;
use LightAgent\Security\CapabilityGate;
use LightAgent\Security\SecurityContext;

$scenarioFile = $argv[1] ?? (__DIR__ . '/scenarios.json');
$raw = (string) file_get_contents($scenarioFile);
$definition = json_decode($raw, true);
if (!is_array($definition) || !isset($definition['scenarios'])) {
    fwrite(STDERR, "cannot read scenarios from {$scenarioFile}\n");
    exit(2);
}

/**
 * Minimal capability provider for the scope-shadowing scenario.
 */
final class DiffProvider extends BaseCapabilityProvider
{
    public function __construct(CapabilitySpec $spec, string $name)
    {
        parent::__construct([$spec]);
        $this->name = $name;
    }

    public function invoke(string $capability, mixed ...$arguments): mixed
    {
        return 'invoked';
    }
}

$observations = [];

foreach ($definition['scenarios'] as $scenario) {
    $id = (string) $scenario['id'];
    $group = (string) $scenario['group'];
    $input = $scenario['input'] ?? [];
    try {
        $observations[$id] = match ($group) {
            'memory-write-patterns' => observeWritePatterns($input),
            'memory-tokenize' => observeTokenize($input),
            'memory-recall-partition' => observeRecallPartition($input),
            'hook-failure-mode' => observeHookFailureMode($input),
            'capability-scope' => observeCapabilityScope($input),
            'sandbox-checker' => observeSandboxChecker($input),
            'permission-narrow' => observePermissionNarrow($input),
            'session-event' => observeSessionEvent($input),
            'context-budget' => observeContextBudget($input),
            'subagent-permissions' => observeSubagentPermissions($input),
            'fts-owner-filter' => observeFtsOwnerFilter($input),
            default => 'unsupported-group',
        };
    } catch (\Throwable $e) {
        $observations[$id] = 'php-error:' . $e::class;
    }
}

echo json_encode($observations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// ------------------------------------------------------------------
// observers
// ------------------------------------------------------------------

function observeWritePatterns(array $input): bool
{
    $policy = new MemoryPolicy(rejectWritePatterns: (array) ($input['patterns'] ?? []));
    $decision = $policy->allowsWrite((string) ($input['candidate'] ?? ''));
    return (bool) $decision->allowed;
}

function observeTokenize(array $input): int
{
    $pool = new SharedMemoryPool();
    $pool->store((string) ($input['stored'] ?? ''), 'alice', metadata: ['source' => 'user']);
    $result = $pool->retrieve((string) ($input['query'] ?? ''), 'alice');
    return count($result['results'] ?? []);
}

function observeRecallPartition(array $input): int
{
    $pool = new SharedMemoryPool();
    foreach ((array) ($input['records'] ?? []) as $record) {
        $pool->store(
            (string) ($record['memory'] ?? ''),
            'alice',
            metadata: ['source' => 'user', 'scope' => (string) ($record['scope'] ?? 'user')],
        );
    }
    $scope = $input['scope'] ?? null;
    $result = $pool->retrieve(
        (string) ($input['query'] ?? ''),
        'alice',
        scope: $scope === null ? null : (string) $scope,
    );
    return count($result['results'] ?? []);
}

function observeHookFailureMode(array $input): string
{
    $throwing = static function (HookContext $ctx): void {
        throw new \RuntimeException('boom');
    };
    $hooks = !empty($input['wrapped_in_policy_hook'])
        ? [new PolicyHook($throwing, failureMode: 'block')]
        : [$throwing];
    $decision = (new HookManager($hooks))->run(
        new HookContext(phase: 'before_tool_call', payload: ['tool_name' => 'demo']),
    );
    $action = $decision->action;
    return is_object($action) && property_exists($action, 'value')
        ? (string) $action->value
        : (string) $action;
}

function observeCapabilityScope(array $input): string
{
    $strict = new DiffProvider(
        new CapabilitySpec(
            'file.write',
            risk: \LightAgent\Capabilities\CapabilityRisk::DESTRUCTIVE,
            write: true,
            requiresApproval: true,
        ),
        'files',
    );
    $loose = new DiffProvider(
        new CapabilitySpec(
            'file.write',
            risk: \LightAgent\Capabilities\CapabilityRisk::READ_ONLY,
            read: true,
        ),
        'files',
    );
    $registry = new CapabilityRegistry();
    $registry->register($strict, scope: CapabilityScope::RUNTIME);
    $registry->register($loose, scope: CapabilityScope::AGENT, ownerId: 'a1');

    $resolved = $registry->resolve('file.write', new RuntimeContext(agentId: 'a1'));
    $spec = $resolved->capabilities()['file.write'];
    return $spec->risk->value . '|' . ($spec->requiresApproval ? 'approval' : 'no-approval');
}

function observeSandboxChecker(array $input): bool
{
    [$allowed] = (new \LightAgent\BuiltinTools\PythonSourceChecker())->check((string) ($input['code'] ?? ''));
    return (bool) $allowed;
}

function observePermissionNarrow(array $input): string
{
    $parent = new SecurityContext(
        tenantId: 't',
        projectId: 'p',
        policyVersion: (string) ($input['parent_policy_version'] ?? '1'),
        approvalTokenIds: (array) ($input['parent_token_ids'] ?? []),
        permissions: new PermissionSet(allowed: ['file.write']),
    );
    $child = $parent->narrow(taskId: 'child');
    return $child->policyVersion . '|' . count($child->approvalTokenIds);
}

function observeSessionEvent(array $input): string
{
    $event = new \LightAgent\Session\SessionEvent(
        type: 'message',
        sessionId: 's1',
        data: ['content' => (string) ($input['payload'] ?? '')],
    );
    $dict = $event->toDict();
    return isset($dict['data']['content']) ? 'ok' : 'empty';
}

function observeContextBudget(array $input): int
{
    $chars = (int) ($input['chars'] ?? 0);
    $text = $input['cjk'] ?? false
        ? str_repeat('中', $chars)
        : str_repeat('a', $chars);
    $budget = new \LightAgent\Session\ContextBudget(maxTokens: 100000, reservedOutputTokens: 0);
    return $budget->estimate([
        ['role' => 'user', 'content' => $text],
    ]);
}

/**
 * A child agent must never end up with MORE capabilities than its parent.
 *
 * The scenario registers a NARROW parent and then asks for a child whose
 * caller-supplied `parent_permissions` are WIDER than the parent actually
 * holds. The original narrows against the caller-supplied set, so it grants
 * the escalation; the port narrows against the parent's EFFECTIVE set and
 * refuses it. The refusal is the observed outcome: false = blocked.
 *
 * (The previous fixture registered a ROOT agent with a caller-supplied
 * allowlist, which both sides treat identically — it never tested an
 * escalation at all.)
 */
function observeSubagentPermissions(array $input): bool
{
    $manager = new \LightAgent\Runtime\SubagentManager();
    $narrow = new PermissionSet(
        allowed: ['fs.read'],
        maxRisk: \LightAgent\Capabilities\CapabilityRisk::READ_ONLY,
        unrestricted: false,
    );
    $wider = new PermissionSet(
        allowed: ['fs.read', 'fs.write', 'net.fetch'],
        maxRisk: \LightAgent\Capabilities\CapabilityRisk::DESTRUCTIVE,
        unrestricted: false,
    );
    try {
        $parent = $manager->register(new \stdClass(), null, $narrow);
        $child = $manager->register(new \stdClass(), $parent->agentId, $wider);
    } catch (\Throwable) {
        // The registration was REFUSED: the child never got the wider set.
        return false;
    }
    return $child->permissions->allows(
        'fs.write',
        \LightAgent\Capabilities\CapabilityRisk::DESTRUCTIVE,
    );
}

/**
 * The owner filter must be applied before the result limit, otherwise a
 * tenant's own document can be pushed out of the candidate window.
 * Observed: the number of hits the owner actually gets.
 */
function observeFtsOwnerFilter(array $input): int
{
    $db = sys_get_temp_dir() . '/la_diff_fts_' . bin2hex(random_bytes(4)) . '.sqlite3';
    $rag = new \LightAgent\Knowledge\SqliteFtsRetrievalProvider($db, chunkSize: 400, chunkOverlap: 40);
    $foreign = (int) ($input['foreign_documents'] ?? 60);
    for ($i = 0; $i < $foreign; $i++) {
        $rag->ingest(new \LightAgent\Knowledge\RetrievalDocument(
            content: "machine learning document {$i}",
            title: "doc{$i}",
            source: 'docs',
            scope: 'workspace',
            ownerId: 'bob',
        ));
    }
    $rag->ingest(new \LightAgent\Knowledge\RetrievalDocument(
        content: 'machine learning alice unique document',
        title: 'alice',
        source: 'docs',
        scope: 'workspace',
        ownerId: 'alice',
    ));
    $hits = $rag->search('machine learning', limit: 5, scope: 'workspace', ownerId: 'alice');
    @unlink($db);
    return count($hits);
}
