<?php

declare(strict_types=1);

namespace LightAgent\Tests\Audit;

use LightAgent\Async\AsyncMutex;
use LightAgent\Capabilities\CapabilityRisk;
use LightAgent\Capabilities\PermissionSet;
use LightAgent\Evaluation\EvaluationCase;
use LightAgent\Evaluation\LightEvaluator;
use LightAgent\Flow\JsonLightFlowStore;
use LightAgent\Flow\LightFlow;
use LightAgent\Llm\OpenAiCompletions;
use LightAgent\Llm\OpenAiClient;
use LightAgent\Llm\ToolCallAssembler;
use LightAgent\Tools\ToolFunction;
use LightAgent\Protocol\MemoryPolicy;
use LightAgent\Review\HumanApprovalHook;
use LightAgent\Hooks\HookContext;
use LightAgent\Runtime\JobManager;
use LightAgent\Security\SecurityContext;
use LightAgent\Security\SecurityUtils;
use LightAgent\Session\ContextProjector;
use LightAgent\Session\Session;
use LightAgent\Skills\SkillManager;
use LightAgent\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the audit findings (AUDIT_REPORT.md).
 * Each test maps to a finding id and fails if the bug resurfaces.
 */
final class AuditRegressionTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/audit_regr_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
    }

    // ------------------------------------------------------------------
    // C1 · stream flag must reach the request body
    // ------------------------------------------------------------------

    public function test_c1_client_request_body_keeps_stream_flag(): void
    {
        $captureFile = $this->tmpDir . '/captured_body.json';
        $router = $this->tmpDir . '/router.php';
        file_put_contents($router, '<?php
$raw = file_get_contents("php://input") ?: "{}";
file_put_contents(__DIR__ . "/captured_body.json", $raw);
header("Content-Type: text/event-stream");
echo "data: {\"choices\":[{\"delta\":{}}]}\n\ndata: [DONE]\n\n";
');
        $port = 19040;
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        while ($fp !== false) {
            fclose($fp);
            $port++;
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        }
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        try {
            for ($i = 0; $i < 50; $i++) {
                $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if ($fp !== false) {
                    fclose($fp);
                    break;
                }
                usleep(100000);
            }
            $client = new OpenAiClient('test-key', 'http://127.0.0.1:' . $port . '/v1');
            foreach ($client->streamRequest(['model' => 'm', 'messages' => [], 'stream' => true]) as $_) {
                break;
            }
            self::assertFileExists($captureFile);
            $body = json_decode((string) file_get_contents($captureFile), true);
            self::assertTrue($body['stream'] ?? false, 'stream=true must reach the request body');
        } finally {
            if ($process !== false) {
                $status = proc_get_status($process);
                exec('taskkill /PID ' . $status['pid'] . ' /F 2>nul');
                proc_close($process);
            }
        }
    }

    // ------------------------------------------------------------------
    // C2 · projector emits protocol-valid tool history
    // ------------------------------------------------------------------

    public function test_c2_projector_produces_valid_tool_protocol(): void
    {
        $session = new Session();
        $session->append('message.received', ['role' => 'user', 'content' => 'q']);
        $session->append('tool.requested', ['id' => 'call_1', 'name' => 't', 'arguments' => '{}']);
        $session->append('tool.completed', ['id' => 'call_1', 'name' => 't', 'output' => 'out']);
        $session->append('assistant.completed', ['content' => 'done']);

        $messages = (new ContextProjector())->messages($session);
        $declared = [];
        foreach ($messages as $message) {
            if (($message['role'] ?? '') === 'assistant' && isset($message['tool_calls'])) {
                foreach ($message['tool_calls'] as $call) {
                    $declared[$call['id']] = true;
                }
            } elseif (($message['role'] ?? '') === 'tool') {
                self::assertNotNull($message['tool_call_id'] ?? null, 'tool message must carry an id');
                self::assertArrayHasKey($message['tool_call_id'], $declared, 'tool result must follow its assistant declaration');
            }
        }
    }

    public function test_c2_legacy_events_without_ids_still_bind(): void
    {
        $session = new Session();
        $session->append('message.received', ['role' => 'user', 'content' => 'q']);
        $session->append('tool.requested', ['name' => 't', 'arguments' => '{}']);
        $session->append('tool.completed', ['name' => 't', 'output' => 'out']);

        $messages = (new ContextProjector())->messages($session);
        self::assertSame(
            $messages[1]['tool_calls'][0]['id'],
            $messages[2]['tool_call_id'],
            'synthesized ids must match for legacy sessions',
        );
    }

    // ------------------------------------------------------------------
    // C4 · tool argument binding
    // ------------------------------------------------------------------

    public function test_c4_mcp_style_array_closure_receives_named_map(): void
    {
        $tool = new ToolFunction(static fn (array $params = []): array => $params, []);
        self::assertSame(['query' => 'php'], $tool->invoke(['query' => 'php']));
    }

    public function test_c4_snake_case_schema_binds_to_camel_case_callable(): void
    {
        $tool = new ToolFunction(static fn (string $cityName): string => $cityName, []);
        self::assertSame('Paris', $tool->invoke(['city_name' => 'Paris']));
    }

    public function test_c4_defaults_keep_positions_aligned(): void
    {
        $tool = new ToolFunction(
            static fn (string $code, int $timeout = 30, ?string $inputData = null): string => implode('|', [$code, (string) $timeout, $inputData ?? '']),
            [],
        );
        self::assertSame('print(1)|30|stdin', $tool->invoke(['code' => 'print(1)', 'input_data' => 'stdin']));
    }

    // ------------------------------------------------------------------
    // C5 · cancel()
    // ------------------------------------------------------------------

    public function test_c5_cancel_running_job_marks_cancelled(): void
    {
        $manager = new JobManager();
        $record = $manager->start('job', static fn (): int => 1);
        self::assertTrue($manager->cancel($record->jobId, 'reason'));
        self::assertSame('cancelled', $manager->wait($record->jobId)->status->value);
    }

    public function test_c5_cancel_terminal_job_returns_false(): void
    {
        $manager = new JobManager();
        $record = $manager->start('job', static fn (): int => 1);
        $manager->wait($record->jobId);
        self::assertFalse($manager->cancel($record->jobId));
        self::assertFalse($manager->cancel('unknown-id'));
    }

    // ------------------------------------------------------------------
    // H2 / H3 · empty-list narrowing semantics
    // ------------------------------------------------------------------

    public function test_h2_narrow_empty_allowlist_denies_everything(): void
    {
        $parent = new PermissionSet(allowed: ['rag.search'], maxRisk: CapabilityRisk::READ_ONLY);
        $child = $parent->narrow(allowed: []);
        self::assertFalse($child->allows('shell.exec', CapabilityRisk::READ_ONLY));
        self::assertFalse($child->allows('rag.search'));
        self::assertFalse(PermissionSet::denyAll()->allows('anything'));
        // legacy default stays unrestricted
        self::assertTrue((new PermissionSet())->allows('x'));
    }

    /**
     * A1 counterpart case: a deny-all parent must not allow a child to
     * re-add capabilities through a second narrow() call.
     */
    public function test_a1_deny_all_parent_cannot_regain_capabilities(): void
    {
        $parent = new PermissionSet(allowed: ['rag.search'], maxRisk: CapabilityRisk::READ_ONLY);
        $denied = $parent->narrow(allowed: []);
        try {
            $denied->narrow(allowed: ['shell.exec']);
            self::fail('narrow() on a deny-all parent must reject new capabilities');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('cannot add capabilities', $e->getMessage());
        }
        // same through SecurityContext::narrow
        $context = new SecurityContext(userId: 'u', permissions: $parent);
        $child = $context->narrow(allowedCapabilities: []);
        $this->expectException(\InvalidArgumentException::class);
        $child->narrow(allowedCapabilities: ['shell.exec']);
    }

    /**
     * A2 counterpart case: serialization must preserve empty-list semantics
     * in BOTH directions (unrestricted stays permissive, deny-all stays
     * locked).
     */
    public function test_a2_serialization_round_trip_preserves_semantics(): void
    {
        // unrestricted default survives the round trip
        $permissive = SecurityContext::fromDict((new SecurityContext(userId: 'u'))->toDict());
        self::assertTrue($permissive->permissions->allows('cap.exec'));

        // explicit deny-all survives the round trip and stays locked
        $locked = (new SecurityContext(userId: 'u', permissions: new PermissionSet(
            allowed: ['a'],
            maxRisk: CapabilityRisk::READ_ONLY,
        )))->narrow(allowedCapabilities: []);
        $restored = SecurityContext::fromDict($locked->toDict());
        self::assertFalse($restored->permissions->allows('a'));
        try {
            $restored->narrow(allowedCapabilities: ['shell.exec']);
            self::fail('round-tripped deny-all must not accept new capabilities');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_h3_narrow_empty_resources_blocks_gate(): void
    {
        $context = new SecurityContext(userId: 'u', resources: ['workspace:a']);
        $narrowed = $context->narrow(resources: []);
        $spec = new \LightAgent\Capabilities\CapabilitySpec('cap', risk: CapabilityRisk::READ_ONLY, read: true);
        $gate = new \LightAgent\Security\CapabilityGate();
        $this->expectException(\RuntimeException::class);
        $gate->authorize($spec, 'prov', [], $narrowed, resource: 'workspace:b');
    }

    // ------------------------------------------------------------------
    // H4 · python checker subscript bypass
    // ------------------------------------------------------------------

    public function test_h4_subscript_access_to_eval_is_blocked(): void
    {
        $checker = new \LightAgent\BuiltinTools\PythonSourceChecker();
        // Called subscripts are blocked (Python ast.Subscript parity):
        [$allowed] = $checker->check('f["eval"]()');
        self::assertFalse($allowed);
        [$allowed] = $checker->check("f['exec']()");
        self::assertFalse($allowed);
        // benign code still passes
        [$allowed] = $checker->check("d = {'a': 1}\nprint(d['a'])");
        self::assertTrue($allowed);
    }

    /**
     * A4 counterpart case: ordinary dictionary access with common keys must
     * stay allowed — only CALLED subscripts carry the full danger list.
     */
    public function test_a4_plain_subscript_reads_stay_allowed(): void
    {
        $checker = new \LightAgent\BuiltinTools\PythonSourceChecker();
        foreach (["value = config['run']", "x = d['input']", "x = d['system']", "print({'run': 1}['run'])"] as $code) {
            [$allowed, $reason] = $checker->check($code);
            self::assertTrue($allowed, "benign subscript read must pass: {$code} ({$reason})");
        }
        // called subscripts remain blocked
        foreach (["f['run']()", "f['system']()", "f['eval']()"] as $code) {
            [$allowed] = $checker->check($code);
            self::assertFalse($allowed, "called subscript must stay blocked: {$code}");
        }
    }

    // ------------------------------------------------------------------
    // H5 · unattributed memory records pass through filters
    // ------------------------------------------------------------------

    public function test_h5_unattributed_record_still_filtered(): void
    {
        $policy = new MemoryPolicy(allowedSources: ['user'], allowedScopes: ['user'], minConfidence: 0.9);
        $bad = ['content' => 'x', 'source' => 'external', 'scope' => 'global', 'confidence' => 0.1];
        self::assertFalse($policy->allowsResult($bad, 'u', 'u'));
        $good = ['content' => 'x', 'source' => 'user', 'scope' => 'user', 'confidence' => 0.95];
        self::assertTrue($policy->allowsResult($good, 'u', 'u'));
    }

    // ------------------------------------------------------------------
    // H6/M8 · redaction
    // ------------------------------------------------------------------

    public function test_h6_sensitive_key_variants_redacted(): void
    {
        foreach (['access_token', 'client_secret', 'private_key', 'x-api-key', 'db_password'] as $key) {
            self::assertSame('[redacted]', \LightAgent\Session\SessionConstants::jsonSafe('sk-secret', $key), $key);
        }
        self::assertSame('sk-secret', \LightAgent\Session\SessionConstants::jsonSafe('sk-secret', 'note'));
    }

    public function test_h6_trace_events_are_redacted(): void
    {
        $event = new \LightAgent\Tracing\TraceEvent('model_request', ['api_key' => 'sk-leak']);
        self::assertSame('[redacted]', $event->toDict()['data']['api_key']);
    }

    public function test_m8_llm_error_message_masks_api_key(): void
    {
        $exception = \LightAgent\Llm\LlmException::fromHttpStatus(
            401,
            json_encode(['error' => ['message' => 'Incorrect API key provided: sk-abcdef1234567890']]),
        );
        self::assertStringNotContainsString('sk-abcdef1234567890', $exception->getMessage());
    }

    public function test_m7_llm_exception_loads_via_psr4(): void
    {
        spl_autoload_call('LightAgent\Llm\LlmException');
        self::assertTrue(class_exists('LightAgent\Llm\LlmException', false));
    }

    // ------------------------------------------------------------------
    // H7 · FTS5 query safety
    // ------------------------------------------------------------------

    public function test_h7_fts_special_tokens_degrade_not_throw(): void
    {
        $provider = new \LightAgent\Knowledge\SqliteFtsRetrievalProvider($this->tmpDir . '/fts.sqlite3');
        $provider->ingest(new \LightAgent\Knowledge\RetrievalDocument(content: 'quick brown fox'));
        foreach (['*', 'NOT', 'quick "brown', 'NEAR/2'] as $query) {
            try {
                $provider->search($query);
                $this->addToAssertionCount(1);
            } catch (\PDOException) {
                self::fail("query `{$query}` must not throw");
            }
        }
        self::assertGreaterThanOrEqual(1, count($provider->search('fox')));
        self::assertGreaterThanOrEqual(1, count($provider->search('fo*')));
    }

    /**
     * A8 counterpart case: boolean operators keep native FTS5 semantics.
     */
    public function test_a8_boolean_operators_keep_semantics(): void
    {
        $provider = new \LightAgent\Knowledge\SqliteFtsRetrievalProvider($this->tmpDir . '/fts8.sqlite3');
        $provider->ingest(new \LightAgent\Knowledge\RetrievalDocument(content: 'java enterprise frameworks'));
        $provider->ingest(new \LightAgent\Knowledge\RetrievalDocument(content: 'python scripting tools'));
        self::assertSame(2, count($provider->search('java OR python')));
        self::assertSame(1, count($provider->search('java AND frameworks')));
        self::assertSame(1, count($provider->search('java NOT python')));
        self::assertSame(0, count($provider->search('java python')));
        // safety fallback still works for malformed input
        foreach (['*', 'NEAR/2', 'quick "brown'] as $query) {
            $provider->search($query);
            $this->addToAssertionCount(1);
        }
    }

    // ------------------------------------------------------------------
    // H9 · ownership scoping
    // ------------------------------------------------------------------

    public function test_h9_read_and_remove_honor_owner(): void
    {
        $provider = new \LightAgent\Knowledge\SqliteFtsRetrievalProvider($this->tmpDir . '/own.sqlite3');
        $docId = $provider->ingest(new \LightAgent\Knowledge\RetrievalDocument(content: 'secret doc', ownerId: 'alice'));
        self::assertNull($provider->readChunk("{$docId}:0", ownerId: 'bob'));
        self::assertNotNull($provider->readChunk("{$docId}:0", ownerId: 'alice'));
        self::assertFalse($provider->remove($docId, ownerId: 'bob'));
        self::assertTrue($provider->remove($docId, ownerId: 'alice'));
    }

    // ------------------------------------------------------------------
    // H10 · approval decisions
    // ------------------------------------------------------------------

    public function test_h10_null_reviewer_rejects(): void
    {
        $hook = new HumanApprovalHook(reviewer: static fn (): mixed => null);
        $context = new HookContext(phase: 'before_tool_call', payload: ['tool_name' => 'x', 'arguments' => []]);
        self::assertSame('block', $hook($context)?->action);
    }

    /**
     * H10 / M-41: an approval is bound to the ACTION — the same tool with
     * the same arguments in the same phase — and NOT to the per-run
     * random id.
     *
     * The run id is regenerated for every `LightAgent::run()` call, so the
     * old binding made the documented "mode 1" flow impossible: the first
     * run produced a request bound to its own run id, and the second run
     * could never consume that approval, so the tool stayed blocked
     * forever. The argument binding is NOT weakened: a different tool or
     * different arguments are still rejected.
     */
    public function test_h10_approval_replay_across_runs_is_bound_to_the_action(): void
    {
        $hook = new HumanApprovalHook(reviewer: static fn (): mixed => true);
        $first = $hook(new HookContext(phase: 'before_tool_call', payload: ['tool_name' => 'x', 'arguments' => ['p' => '1']], runId: 'run-1'));
        $requestId = $first->metadata['approval_request_id'];
        $replay = $hook(new HookContext(
            phase: 'before_tool_call',
            payload: ['tool_name' => 'x', 'arguments' => ['p' => '1']],
            runId: 'run-2',
            metadata: ['approval_id' => $requestId],
        ));
        self::assertSame('continue', $replay->action, 'the approval must be consumable in the next run');
        $sameRun = $hook(new HookContext(
            phase: 'before_tool_call',
            payload: ['tool_name' => 'x', 'arguments' => ['p' => '1']],
            runId: 'run-1',
            metadata: ['approval_id' => $requestId],
        ));
        self::assertSame('continue', $sameRun->action);

        // The ACTION binding is unchanged: a different tool is rejected.
        $otherTool = $hook(new HookContext(
            phase: 'before_tool_call',
            payload: ['tool_name' => 'y', 'arguments' => ['p' => '1']],
            runId: 'run-2',
            metadata: ['approval_id' => $requestId],
        ));
        self::assertSame('block', $otherTool->action, 'a different tool must not reuse the approval');

        // ... and so are different arguments.
        $otherArguments = $hook(new HookContext(
            phase: 'before_tool_call',
            payload: ['tool_name' => 'x', 'arguments' => ['p' => '2']],
            runId: 'run-2',
            metadata: ['approval_id' => $requestId],
        ));
        self::assertSame('block', $otherArguments->action, 'different arguments must not reuse the approval');
    }

    /**
     * M-41: a caller that wants a stricter binding supplies a STABLE
     * correlation id; an approval bound to one may only be consumed in the
     * same correlation scope.
     */
    public function test_h10_approval_can_be_bound_to_a_stable_correlation_id(): void
    {
        $hook = new HumanApprovalHook(reviewer: static fn (): mixed => true);
        $first = $hook(new HookContext(
            phase: 'before_tool_call',
            payload: ['tool_name' => 'x', 'arguments' => ['p' => '1']],
            runId: 'run-1',
            correlationId: 'session-a',
        ));
        $requestId = $first->metadata['approval_request_id'];

        $sameCorrelation = $hook(new HookContext(
            phase: 'before_tool_call',
            payload: ['tool_name' => 'x', 'arguments' => ['p' => '1']],
            runId: 'run-2',
            correlationId: 'session-a',
            metadata: ['approval_id' => $requestId],
        ));
        self::assertSame('continue', $sameCorrelation->action);

        $otherCorrelation = $hook(new HookContext(
            phase: 'before_tool_call',
            payload: ['tool_name' => 'x', 'arguments' => ['p' => '1']],
            runId: 'run-2',
            correlationId: 'session-b',
            metadata: ['approval_id' => $requestId],
        ));
        self::assertSame('block', $otherCorrelation->action, 'a different correlation scope must not reuse the approval');
    }

    // ------------------------------------------------------------------
    // H11 · evaluator isolation
    // ------------------------------------------------------------------

    public function test_h11_unstringable_result_does_not_kill_evaluation(): void
    {
        $evaluator = new LightEvaluator();
        $target = new class {
            public function run(string $query, array $kwargs): object
            {
                $result = new \stdClass();
                $result->content = new \stdClass();

                return $result;
            }
        };
        $report = $evaluator->run($target, [new EvaluationCase(name: 'case', query: 'q')]);
        self::assertNotNull($report->results[0]->error);
    }

    // ------------------------------------------------------------------
    // H12 · wall-clock timeout enforcement
    // ------------------------------------------------------------------

    public function test_h12_blocking_agent_over_deadline_reports_timeout(): void
    {
        $flow = new LightFlow();
        $agent = new class {
            public function run(string $q, array $k): string
            {
                usleep(600000);

                return 'late';
            }
        };
        $step = new \LightAgent\Flow\LightFlowStep(name: 'slow', agent: $agent, timeout: 0.2);
        $method = new \ReflectionMethod(LightFlow::class, 'callAgent');
        [, $timedOut] = $method->invoke($flow, $agent, $step, 'q', 'u', false, null, null, new \LightAgent\Async\CancellationToken(), 'k');
        self::assertTrue($timedOut, 'a blocking agent exceeding its deadline must be reported as timedOut');
    }

    /**
     * H12 regression counterpart (the AUDIT_REVERIFY finding): a SUSPENDING
     * agent must also be reported timedOut — via the caught
     * AsyncTimeoutException, not as a raised error.
     */
    public function test_h12_suspending_agent_timeout_is_caught(): void
    {
        $flow = new LightFlow();
        $agent = new class {
            public function run(string $q, array $k): string
            {
                \LightAgent\Async\AsyncRuntime::sleep(1.0);

                return 'late';
            }
        };
        $step = new \LightAgent\Flow\LightFlowStep(name: 'slow', agent: $agent, timeout: 0.2);
        $method = new \ReflectionMethod(LightFlow::class, 'callAgent');
        [$result, $timedOut] = $method->invoke($flow, $agent, $step, 'q', 'u', false, null, null, new \LightAgent\Async\CancellationToken(), 'k');
        self::assertTrue($timedOut, 'suspending agent timeout must be caught, not raised');
        self::assertNull($result);
    }

    /**
     * H12 followup: a per-step timeout must NOT cancel the flow-level token
     * — the fallback agent must still run.
     */
    public function test_h12_step_timeout_does_not_kill_fallback(): void
    {
        $slow = new class {
            public function run(string $q, array $k): string
            {
                \LightAgent\Async\AsyncRuntime::sleep(0.3);

                return 'late';
            }
        };
        $fallback = new class {
            public function run(string $q, array $k): string
            {
                return 'fallback done';
            }
        };
        $flow = (new LightFlow())->step('work', agent: $slow, timeout: 0.05, fallbackAgent: $fallback, allowTimeoutOverlap: true);
        $result = $flow->run('hello');
        self::assertTrue($result->isSuccess(), 'fallback after step timeout must succeed, got: ' . ($result->error ?? 'no error'));
        self::assertSame('fallback done', $result->content);
    }

    // ------------------------------------------------------------------
    // H13 · skill path traversal
    // ------------------------------------------------------------------

    public function test_h13_sibling_directory_escape_blocked(): void
    {
        $base = $this->tmpDir . '/skills/demo';
        foreach (['scripts', 'scripts_evil', 'references', 'references_evil'] as $dir) {
            mkdir($base . '/' . $dir, 0777, true);
        }
        file_put_contents($base . '/SKILL.md', "---\nname: demo\ndescription: probe\n---\nbody");
        file_put_contents($base . '/scripts_evil/pwn.bat', '@echo PWNED');
        file_put_contents($base . '/references_evil/secret.txt', 'TOP-SECRET');

        $manager = new SkillManager([$this->tmpDir . '/skills']);
        $manager->discoverSkills();
        self::assertArrayHasKey('demo', $manager->skills);
        self::assertStringContainsString('Security violation', $manager->executeScript('demo', '../scripts_evil/pwn.bat'));
        self::assertStringContainsString('Security violation', $manager->readReference('demo', '../references_evil/secret.txt'));
    }

    // ------------------------------------------------------------------
    // H14 · reentrant mutex
    // ------------------------------------------------------------------

    public function test_h14_nested_synchronized_releases_cleanly(): void
    {
        $mutex = new AsyncMutex();
        $result = $mutex->synchronized(static fn () => $mutex->synchronized(static fn (): string => 'inner'));
        self::assertSame('inner', $result);
        self::assertFalse($mutex->isLocked());
    }

    // ------------------------------------------------------------------
    // H15 · approve() keeps the full checkpoint
    // ------------------------------------------------------------------

    public function test_h15_approve_from_store_preserves_record(): void
    {
        $store = new JsonLightFlowStore($this->tmpDir);
        $store->saveRun('run15', [
            'run_id' => 'run15',
            'query' => 'original query',
            'status' => 'waiting_approval',
            'steps' => [['name' => 's1', 'status' => 'completed', 'output' => 'done']],
        ]);
        $flow = new LightFlow(store: $store);
        $flow->approve('run15', 's1', true);
        $saved = $store->loadRun('run15');
        self::assertSame('original query', $saved['query'] ?? null);
        self::assertSame('run15', $saved['run_id'] ?? null);
        self::assertArrayHasKey('approvals', $saved);
    }

    // ------------------------------------------------------------------
    // M1/M2 · assembler
    // ------------------------------------------------------------------

    public function test_m1_assembler_consumes_parallel_tool_calls_in_one_delta(): void
    {
        $assembler = new ToolCallAssembler();
        $assembler->consumeChunk([
            'delta' => ['tool_calls' => [
                ['index' => 0, 'id' => 'call_a', 'function' => ['name' => 't1', 'arguments' => '{}']],
                ['index' => 1, 'id' => 'call_b', 'function' => ['name' => 't2', 'arguments' => '{}']],
            ]],
        ]);
        $calls = $assembler->getToolCalls();
        self::assertCount(2, $calls);
        self::assertSame('t1', $calls[0]['name']);
        self::assertSame('t2', $calls[1]['name']);
    }

    public function test_m2_assembler_out_of_order_indexes_stay_aligned(): void
    {
        $assembler = new ToolCallAssembler();
        $assembler->consumeChunk(['delta' => ['tool_calls' => [['index' => 1, 'id' => 'b', 'function' => ['name' => 'second', 'arguments' => '2']]]]]);
        $assembler->consumeChunk(['delta' => ['tool_calls' => [['index' => 0, 'id' => 'a', 'function' => ['name' => 'first', 'arguments' => '1']]]]]);
        $calls = $assembler->getToolCalls();
        self::assertSame('first', $calls[0]['name']);
        self::assertSame('second', $calls[1]['name']);
        self::assertSame('1', $calls[0]['arguments']);
        self::assertSame('2', $calls[1]['arguments']);
    }

    // ------------------------------------------------------------------
    // N14 · checkpointSession public API
    // ------------------------------------------------------------------

    public function test_n14_checkpoint_session_does_not_crash(): void
    {
        $agent = new \LightAgent\Core\LightAgent([
            'model' => 'm', 'api_key' => 'k', 'base_url' => 'http://127.0.0.1:1/v1',
            'auto_discover_skills' => false,
            'session_store' => new \LightAgent\Session\InMemorySessionStore(),
        ]);
        $agent->run('hello', ['session_id' => 'n14']);
        $out = $agent->checkpointSession('label');
        self::assertArrayHasKey('created_at', $out);
        self::assertNotSame('', $out['created_at']);
    }

    // ------------------------------------------------------------------
    // R11 · TaskSpec auto taskId
    // ------------------------------------------------------------------

    public function test_r11_taskspec_generates_task_id_when_omitted(): void
    {
        $spec = new \LightAgent\Dag\TaskSpec(goal: 'g', workerKey: 'w', verifierKey: 'v');
        self::assertNotSame('', $spec->taskId);
        $fromDict = \LightAgent\Dag\TaskSpec::fromDict(['goal' => 'g', 'worker_key' => 'w', 'verifier_key' => 'v']);
        self::assertNotSame('', $fromDict->taskId);
        // explicit id preserved + self-dependency still rejected
        $explicit = new \LightAgent\Dag\TaskSpec(goal: 'g', workerKey: 'w', verifierKey: 'v', taskId: 'x');
        self::assertSame('x', $explicit->taskId);
        $this->expectException(\InvalidArgumentException::class);
        new \LightAgent\Dag\TaskSpec(goal: 'g', workerKey: 'w', verifierKey: 'v', taskId: 'same', dependsOn: ['same']);
    }

    // ------------------------------------------------------------------
    // C2b · interleaved tool events project to a valid protocol
    // ------------------------------------------------------------------

    public function test_c2b_interleaved_tool_events_project_valid_protocol(): void
    {
        $session = new Session();
        $session->append('message.received', ['role' => 'user', 'content' => 'q']);
        foreach (['A', 'B'] as $key) {
            $session->append('tool.requested', ['id' => 'call_' . $key, 'name' => 'w', 'arguments' => '{}']);
            $session->append('tool.completed', ['id' => 'call_' . $key, 'name' => 'w', 'output' => $key]);
        }
        $session->append('assistant.completed', ['content' => 'F']);
        $messages = (new ContextProjector())->messages($session);
        for ($i = 0; $i < count($messages); $i++) {
            if (($messages[$i]['role'] ?? '') === 'assistant' && isset($messages[$i]['tool_calls'])) {
                $needed = array_map(static fn (array $c): string => $c['id'], $messages[$i]['tool_calls']);
                $answered = [];
                $j = $i + 1;
                while ($j < count($messages) && ($messages[$j]['role'] ?? '') === 'tool') {
                    $answered[] = $messages[$j]['tool_call_id'];
                    $j++;
                }
                sort($needed);
                sort($answered);
                self::assertSame($needed, $answered, "assistant message [{$i}] must be immediately answered");
            }
        }
    }

    // ------------------------------------------------------------------
    // R1/R12 · resourcesRestricted serialization + guard
    // ------------------------------------------------------------------

    public function test_r1_resources_restricted_survives_serialization(): void
    {
        $context = new SecurityContext(userId: 'u', resources: ['workspace:a']);
        $narrowed = $context->narrow(taskId: 'tk', resources: []);
        $dict = $narrowed->toDict();
        self::assertTrue($dict['resources_restricted'] ?? false);
        $restored = SecurityContext::fromDict($dict);
        self::assertTrue($restored->isResourceRestricted());
        $gate = new \LightAgent\Security\CapabilityGate();
        $spec = new \LightAgent\Capabilities\CapabilitySpec('cap', risk: CapabilityRisk::READ_ONLY, read: true);
        $this->expectException(\RuntimeException::class);
        $gate->authorize($spec, 'prov', [], $restored, resource: 'workspace:a');
    }

    public function test_r12_task_requirements_cannot_escape_deny_all_boundary(): void
    {
        $context = new SecurityContext(userId: 'u', resources: ['workspace:a']);
        $denied = $context->narrow(resources: []);
        $this->expectException(\InvalidArgumentException::class);
        $denied->narrow(taskId: 'tk', resources: ['workspace:x']);
    }

    // ------------------------------------------------------------------
    // R8 · slash-delimited reject patterns
    // ------------------------------------------------------------------

    public function test_r8_slash_flag_pattern_matches_across_slashes(): void
    {
        $policy = new MemoryPolicy(rejectWritePatterns: ['/etc\/passwd/i']);
        self::assertFalse($policy->allowsWrite('went to /etc/passwd/')->allowed);
        self::assertTrue($policy->allowsWrite('dark mode')->allowed);
    }

    // ------------------------------------------------------------------
    // R13 · questions land in the utterance scope
    // ------------------------------------------------------------------

    public function test_r13_user_query_stored_in_utterance_scope(): void
    {
        $pool = new \LightAgent\Memory\SharedMemoryPool();
        $agent = new \LightAgent\Core\LightAgent([
            'model' => 'm', 'api_key' => 'k', 'base_url' => 'http://127.0.0.1:1/v1',
            'auto_discover_skills' => false, 'memory' => $pool,
        ]);
        try {
            $agent->run('What is my favourite colour?');
        } catch (\Throwable) {
            // LLM unreachable — the memory write happens before/independently
        }
        $prop = new \ReflectionProperty($pool, 'records');
        $records = $prop->getValue($pool);
        self::assertGreaterThanOrEqual(1, count($records));
        self::assertSame('utterance', $records[0]->metadata['scope'] ?? null);
    }

    // ------------------------------------------------------------------
    // R9 · nested dangerous calls are inspected
    // ------------------------------------------------------------------

    public function test_r9_nested_eval_call_is_blocked(): void
    {
        $checker = new \LightAgent\BuiltinTools\PythonSourceChecker();
        [$allowed] = $checker->check('print(eval("1+1"))');
        self::assertFalse($allowed);
        [$allowed] = $checker->check('print(exec("x=1"))');
        self::assertFalse($allowed);
    }

    // ------------------------------------------------------------------
    // R10 · f-strings / bytes / raw strings accepted
    // ------------------------------------------------------------------

    public function test_r10_modern_string_forms_accepted(): void
    {
        $checker = new \LightAgent\BuiltinTools\PythonSourceChecker();
        foreach ([
            'print(f"hello {name}")',
            "x = b\"bytes\"",
            "y = r'raw\\path'",
            "x = rb\"rawbytes\"",
        ] as $code) {
            [$allowed, $reason] = $checker->check($code);
            self::assertTrue($allowed, "modern string form must pass: {$code} ({$reason})");
        }
    }

    // ------------------------------------------------------------------
    // M25 · compactor keeps every system message
    // ------------------------------------------------------------------

    public function test_m25_compactor_keeps_all_system_messages(): void
    {
        $compactor = new \LightAgent\Session\ContextCompactor();
        $result = $compactor->compact([
            ['role' => 'system', 'content' => 's1'], ['role' => 'user', 'content' => 'u1'],
            ['role' => 'assistant', 'content' => 'a1'], ['role' => 'system', 'content' => 's2'],
            ['role' => 'user', 'content' => 'u2'],
        ], 3);
        $systemCount = count(array_filter($result->messages, static fn (array $m): bool => ($m['role'] ?? null) === 'system'));
        self::assertSame(2, $systemCount);
        self::assertSame(2, $result->removedCount);
        self::assertLessThanOrEqual(3, count($result->messages));
    }

    // ------------------------------------------------------------------
    // M32 · decomposition proposal without revision is accepted shape
    // ------------------------------------------------------------------

    public function test_m32_proposal_without_revision_defaults_to_null(): void
    {
        $proposal = new \LightAgent\Dag\DecompositionProposal(
            parentTaskId: 'p1',
            newTasks: [new \LightAgent\Dag\TaskSpec(goal: 'g', workerKey: 'w', verifierKey: 'v', taskId: 'c1')],
        );
        self::assertNull($proposal->expectedGraphRevision);
        $explicit = new \LightAgent\Dag\DecompositionProposal(
            parentTaskId: 'p1',
            newTasks: [new \LightAgent\Dag\TaskSpec(goal: 'g', workerKey: 'w', verifierKey: 'v', taskId: 'c1')],
            expectedGraphRevision: 3,
        );
        self::assertSame(3, $explicit->expectedGraphRevision);
    }

    // ------------------------------------------------------------------
    // M24 · session copy with new id persists
    // ------------------------------------------------------------------

    public function test_m24_session_copy_persists_with_new_id(): void
    {
        $store = new \LightAgent\Session\SqliteSessionStore($this->tmpDir . '/m24.sqlite3');
        $session = $store->create(new Session());
        $session->append('message.received', ['role' => 'user', 'content' => 'x']);
        $store->save($session);
        $copy = $session->copyWithSessionId('copied-1');
        $store->save($copy);
        $loaded = $store->get('copied-1');
        self::assertNotNull($loaded);
        self::assertCount(1, $loaded->events);
        self::assertSame(1, count($store->get($session->sessionId)->events));
    }

    // ------------------------------------------------------------------
    // M29 · client curl escapes
    // ------------------------------------------------------------------

    public function test_m29_client_accepts_ca_bundle_and_headers(): void
    {
        $client = new OpenAiClient('k', 'http://127.0.0.1:1/v1', caBundle: '/tmp/ca.pem', proxy: 'http://proxy:8080', extraHeaders: ['X-Trace' => 't1']);
        self::assertSame('/tmp/ca.pem', $client->caBundle);
        self::assertSame('http://proxy:8080', $client->proxy);
        $headers = $client->withExtraHeaders(['Content-Type: application/json']);
        self::assertContains('X-Trace: t1', $headers);
        // defaults unchanged
        $default = new OpenAiClient('k');
        self::assertNull($default->caBundle);
        self::assertNull($default->proxy);
        self::assertSame([], $default->extraHeaders);
    }

    // ------------------------------------------------------------------
    // M3/M4 · registry hygiene
    // ------------------------------------------------------------------

    public function test_m3_python_tool_block_is_normalized(): void
    {
        self::assertTrue(ToolRegistry::isUnsafePythonTool('EXECUTE_PYTHON_CODE'));
        self::assertTrue(ToolRegistry::isUnsafePythonTool('execute_python_code '));
    }

    public function test_m4_invalid_tool_name_rejected_at_registration(): void
    {
        $registry = new ToolRegistry();
        $this->expectException(\InvalidArgumentException::class);
        $registry->registerTool(new ToolFunction(static fn (): string => 'x', [
            'tool_name' => 'bad-name!',
            'tool_description' => 'd',
            'tool_params' => [],
        ]));
    }

    // ------------------------------------------------------------------
    // A3 · failed tool calls are answered in the projection
    // ------------------------------------------------------------------

    public function test_a3_failed_tool_call_gets_a_response_message(): void
    {
        $session = new Session();
        $session->append('message.received', ['role' => 'user', 'content' => 'call the tool']);
        $session->append('tool.requested', ['id' => 'call_bad', 'name' => 't', 'arguments' => '{bad json']);
        $session->append('error.recorded', ['stage' => 'tool_arguments', 'tool' => 't', 'error' => '[LA-JSON] invalid JSON']);
        $session->append('assistant.completed', ['content' => 'final answer']);

        $messages = (new ContextProjector())->messages($session);
        $declared = [];
        $answered = [];
        foreach ($messages as $message) {
            if (($message['role'] ?? '') === 'assistant' && isset($message['tool_calls'])) {
                foreach ($message['tool_calls'] as $call) {
                    $declared[$call['id']] = false;
                }
            } elseif (($message['role'] ?? '') === 'tool') {
                $answered[$message['tool_call_id']] = true;
            }
        }
        self::assertSame([], array_values(array_diff(array_keys($declared), array_keys($answered))), 'no orphan tool_calls');
        self::assertTrue((bool) preg_grep('/LA-JSON/', array_map('strval', array_column($messages, 'content'))), 'error text reaches the model context');
    }

    /**
     * A3-附带 counterpart: parallel tool calls stay in ONE assistant message.
     */
    public function test_a3_parallel_tool_calls_share_one_assistant_message(): void
    {
        $session = new Session();
        $session->append('message.received', ['role' => 'user', 'content' => 'q']);
        $session->append('tool.requested', ['id' => 'call_A', 'name' => 't1', 'arguments' => '{}']);
        $session->append('tool.requested', ['id' => 'call_B', 'name' => 't2', 'arguments' => '{}']);
        $session->append('tool.completed', ['id' => 'call_A', 'name' => 't1', 'output' => 'A']);
        $session->append('tool.completed', ['id' => 'call_B', 'name' => 't2', 'output' => 'B']);
        $session->append('assistant.completed', ['content' => 'done']);

        $messages = (new ContextProjector())->messages($session);
        $assistantWithToolCalls = array_values(array_filter(
            $messages,
            static fn (array $m): bool => ($m['role'] ?? '') === 'assistant' && isset($m['tool_calls']),
        ));
        self::assertCount(1, $assistantWithToolCalls);
        self::assertCount(2, $assistantWithToolCalls[0]['tool_calls']);
    }

    // ------------------------------------------------------------------
    // A5 · slash-delimited reject patterns
    // ------------------------------------------------------------------

    public function test_a5_slash_delimited_patterns_match_literally(): void
    {
        $pathPolicy = new MemoryPolicy(rejectWritePatterns: ['/etc/passwd/']);
        self::assertFalse($pathPolicy->allowsWrite('user went to /etc/passwd/ today')->allowed);
        self::assertTrue($pathPolicy->allowsWrite('innocuous text')->allowed);

        $flaggedPolicy = new MemoryPolicy(rejectWritePatterns: ['/CASE/i']);
        self::assertFalse($flaggedPolicy->allowsWrite('mixed CASE text')->allowed);

        $invalidPolicy = new MemoryPolicy(rejectWritePatterns: ['secret)']);
        self::assertFalse($invalidPolicy->allowsWrite('anything')->allowed, 'invalid regex fails closed');
    }

    // ------------------------------------------------------------------
    // A7 · single array param with snake_case key
    // ------------------------------------------------------------------

    public function test_a7_single_array_param_binds_snake_case_value(): void
    {
        $tool = new ToolFunction(static fn (array $inputData): string => json_encode($inputData), []);
        self::assertSame('[1,2]', $tool->invoke(['input_data' => [1, 2]]));
        self::assertSame('[1,2]', $tool->invoke(['inputData' => [1, 2]]));
        // unrelated keys still bind the whole map (MCP wrapper convention)
        $wrapper = new ToolFunction(static fn (array $params = []): array => $params, []);
        self::assertSame(['query' => 'php'], $wrapper->invoke(['query' => 'php']));
    }

    // ------------------------------------------------------------------
    // A6 · cancelled job result discarded
    // ------------------------------------------------------------------

    public function test_a6_cancel_discards_running_job_result(): void
    {
        $manager = new JobManager();
        $record = $manager->start('job', static function (): string {
            usleep(200000);

            return 'late result';
        });
        $manager->cancel($record->jobId, 'stop now');
        $final = $manager->wait($record->jobId);
        self::assertSame('cancelled', $final->status->value);
        self::assertNull($final->result, 'a cancelled job must not publish its result');
    }

    // ------------------------------------------------------------------
    // A-followup · memory tokenization
    // ------------------------------------------------------------------

    public function test_memory_tokenizer_strips_punctuation(): void
    {
        $pool = new \LightAgent\Memory\SharedMemoryPool();
        $pool->store('The user is allergic to peanuts.', 'u1');
        self::assertGreaterThanOrEqual(1, count($pool->retrieve('peanuts', 'u1')['results']));
        // stopword-only overlap must not produce a spurious match
        self::assertSame(0, count($pool->retrieve('food to avoid', 'u1')['results']));
    }

    // ------------------------------------------------------------------
    // M13/M14 · memory policy
    // ------------------------------------------------------------------

    public function test_m13_internal_memory_requires_promotion(): void
    {
        $policy = new MemoryPolicy();
        $selfDeclared = ['content' => 'x', 'source' => 'reflection', 'scope' => 'agent', 'injectable' => true];
        self::assertFalse($policy->allowsResult($selfDeclared, 'u', 'u'), 'self-declared injectable must be blocked');
        $promoted = $selfDeclared + ['promotion_status' => 'promoted'];
        self::assertTrue($policy->allowsResult($promoted, 'u', 'u'), 'promoted internal memory may be injected');
    }

    public function test_m14_invalid_reject_pattern_fails_closed(): void
    {
        $policy = new MemoryPolicy(rejectWritePatterns: ['secret)']);
        self::assertFalse($policy->allowsWrite('my secret) data')->allowed);
    }

    // ------------------------------------------------------------------
    // M31 · DAG event sequence SQL injection
    // ------------------------------------------------------------------

    public function test_m31_run_id_with_quotes_is_safe(): void
    {
        $store = new \LightAgent\Dag\SqliteTaskGraphStore($this->tmpDir . '/dag.sqlite3');
        $dag = new \LightAgent\Dag\LightDAG(store: $store, artifactRoot: $this->tmpDir . '/art');
        $dag->registerWorker('w', new \LightAgent\Dag\CallableWorker(static fn (): string => 'ok'));
        $dag->registerVerifier('v', new \LightAgent\Dag\CallableVerifier(key: 'v', function: static fn (): bool => true));
        $context = new SecurityContext(userId: 'u', tenantId: 't', projectId: 'p');
        $spec = new \LightAgent\Dag\TaskSpec(goal: 'g', workerKey: 'w', verifierKey: 'v', taskId: 'a');
        $run = $dag->createRun([$spec], $context, runId: "broken'id");
        self::assertSame("broken'id", $run->runId);
        $injected = "evil' OR '1'='1";
        $run2 = $dag->createRun([$spec], $context, runId: $injected);
        self::assertSame($injected, $run2->runId);
        // Sequences stay per-run (no global max bleed-through).
        $events = $store->events($run->runId);
        foreach ($events as $index => $event) {
            self::assertSame($index + 1, $event->sequence, 'sequences must start at 1 for each run');
        }
    }

    // ------------------------------------------------------------------
    // M35 · hook-blocked step reflected in flow status
    // ------------------------------------------------------------------

    public function test_m35_hook_block_sets_non_success_status(): void
    {
        $hooks = new \LightAgent\Hooks\HookManager([
            static fn (\LightAgent\Hooks\HookContext $ctx): ?\LightAgent\Hooks\HookDecision => $ctx->phase === 'before_flow_step'
                ? \LightAgent\Hooks\HookDecision::block('nope')
                : null,
        ]);
        $flow = new LightFlow(hooks: $hooks);
        $flow->step('a', new class {
            public function run(string $query, array $kwargs): string
            {
                return 'ok';
            }
        });
        $result = $flow->run('q');
        self::assertNotSame('success', $result->status);
        self::assertNotNull($result->error);
    }

    // ------------------------------------------------------------------
    // C3 · handoff cycle guard (direct unit on path logic)
    // ------------------------------------------------------------------

    public function test_c3_handoff_depth_option_survives_run_boundary(): void
    {
        // The path is plumbed through run options; a seeded path containing
        // the current agent trips the guard before any target resolution.
        $agent = new class (['model' => 'm', 'api_key' => 'k', 'base_url' => 'http://127.0.0.1:1/v1', 'name' => 'AgentA']) extends \LightAgent\Core\LightAgent {
            public function detectIntent(string $query, ?\LightAgent\Core\LightSwarm $lightSwarm = null): ?array
            {
                return ['transfer_to' => 'AgentB'];
            }
        };
        $swarm = new \LightAgent\Core\LightSwarm();
        $swarm->registerAgent($agent);
        $result = $agent->run('go', [
            'light_swarm' => $swarm,
            '_handoff_path' => ['AgentB', 'AgentA'],
        ]);
        $output = is_string($result) ? $result : implode('', is_iterable($result) ? iterator_to_array($result) : []);
        self::assertStringContainsString('cycle detected', $output);
    }

    // ------------------------------------------------------------------
    // R2 · legacy security-context payloads must not degrade to deny-all
    // ------------------------------------------------------------------

    public function test_r2_legacy_payload_without_unrestricted_stays_unrestricted(): void
    {
        $context = new SecurityContext(userId: 'u', tenantId: 't', projectId: 'p');
        $payload = $context->toDict();
        // A payload written BEFORE the flag existed (schema 1) has no
        // 'unrestricted' key. Such a payload must keep the legacy meaning:
        // an empty allowlist then meant "unrestricted", never "deny all".
        unset($payload['permissions']['unrestricted']);
        $payload['schema_version'] = 1;
        $restored = SecurityContext::fromDict($payload);
        self::assertTrue(
            $restored->permissions->allows('cap.exec'),
            'a legacy (schema 1) payload means "unrestricted" and must not load as deny-all',
        );
    }

    /**
     * B6: a payload of the CURRENT schema without the 'unrestricted' key is
     * not a legacy payload — it is hand-built or corrupted. Loading it as
     * "unrestricted" would hand every capability to whoever supplies it.
     */
    public function test_b6_current_schema_without_unrestricted_fails_closed(): void
    {
        $context = new SecurityContext(userId: 'u', tenantId: 't', projectId: 'p');
        $payload = $context->toDict();
        unset($payload['permissions']['unrestricted']);
        $restored = SecurityContext::fromDict($payload);
        self::assertFalse(
            $restored->permissions->allows('cap.exec'),
            'a current-schema payload without the flag must fail closed',
        );

        // A payload without any schema version is not legacy either.
        $noVersion = $context->toDict();
        unset($noVersion['permissions']['unrestricted'], $noVersion['schema_version']);
        $restoredNoVersion = SecurityContext::fromDict($noVersion);
        self::assertFalse(
            $restoredNoVersion->permissions->allows('cap.exec'),
            'a payload without a schema version must fail closed',
        );
    }

    /**
     * T3: the Python original builds a child security context with
     * `dataclasses.replace(self, ...)`, which keeps every field the call did
     * not mention. The PHP port rebuilt the object from scratch and reset
     * the policy version, the granted approval token ids and the schema
     * version to their defaults — a child context then claimed policy
     * version "1" and carried no approvals.
     */
    public function test_b6_narrowed_security_context_keeps_policy_and_approvals(): void
    {
        $context = new SecurityContext(
            userId: 'u',
            tenantId: 't',
            projectId: 'p',
            policyVersion: '7',
            approvalTokenIds: ['token-a', 'token-b'],
        );

        $child = $context->narrow(taskId: 'task-1');

        self::assertSame('7', $child->policyVersion, 'the child must keep the policy version');
        self::assertSame(
            ['token-a', 'token-b'],
            $child->approvalTokenIds,
            'the child must keep the approvals granted to the parent',
        );
        self::assertSame(
            SecurityUtils::SECURITY_CONTEXT_SCHEMA_VERSION,
            $child->schemaVersion,
            'the child must keep the schema version',
        );
        self::assertSame('task-1', $child->taskId);
    }

    public function test_r2_explicit_deny_all_still_round_trips_as_deny_all(): void
    {
        $denyAll = (new PermissionSet())->narrow(allowed: []);
        $context = new SecurityContext(userId: 'u', permissions: $denyAll);
        $restored = SecurityContext::fromDict($context->toDict());
        self::assertFalse(
            $restored->permissions->allows('cap.exec'),
            'the deny-all state is always serialised explicitly and must survive',
        );
    }

    // ------------------------------------------------------------------
    // R3/R8 · reject-pattern normalisation
    // ------------------------------------------------------------------

    public function test_r3_delimited_reject_pattern_matches_intended_content(): void
    {
        $policy = new MemoryPolicy(rejectWritePatterns: ['/etc/passwd/']);
        self::assertFalse(
            $policy->allowsWrite('the path is /etc/passwd')->allowed,
            'a delimited path pattern must match the content it was written for',
        );
        self::assertTrue(
            $policy->allowsWrite('the user prefers dark mode')->allowed,
            'an unrelated write must not be blocked',
        );
    }

    public function test_r8_delimited_pattern_with_flags_does_not_reject_everything(): void
    {
        $policy = new MemoryPolicy(rejectWritePatterns: ['/etc/passwd/i']);
        self::assertTrue(
            $policy->allowsWrite('the user prefers dark mode')->allowed,
            'a valid /body/flags pattern must not fail closed on every write',
        );
        self::assertFalse(
            $policy->allowsWrite('the path is /etc/passwd')->allowed,
            'the same pattern must still reject the content it targets',
        );
    }

    public function test_r8_escaped_and_unescaped_delimited_patterns_are_equivalent(): void
    {
        $escaped = new MemoryPolicy(rejectWritePatterns: ['/etc\/passwd/']);
        $plain = new MemoryPolicy(rejectWritePatterns: ['/etc/passwd/']);
        self::assertFalse($escaped->allowsWrite('the path is /etc/passwd')->allowed);
        self::assertFalse($plain->allowsWrite('the path is /etc/passwd')->allowed);
    }

    public function test_m14_genuinely_broken_pattern_still_fails_closed(): void
    {
        $policy = new MemoryPolicy(rejectWritePatterns: ['secret)']);
        self::assertFalse(
            $policy->allowsWrite('my secret) data')->allowed,
            'an unparsable pattern must keep failing closed',
        );
    }

    // ------------------------------------------------------------------
    // H4 · a builtin pulled out of a builtin container is blocked
    // ------------------------------------------------------------------

    public function test_h4_builtin_read_out_of_builtins_container_is_blocked(): void
    {
        $checker = new \LightAgent\BuiltinTools\PythonSourceChecker();
        foreach ([
            'x = vars(__builtins__)["eval"] ; x("2+2")',
            "x = globals()['__builtins__']['eval'] ; x('3+3')",
            'x = vars(__builtins__)["exec"] ; x("1")',
        ] as $code) {
            [$allowed, $reason] = $checker->check($code);
            self::assertFalse($allowed, "must be blocked: {$code} ({$reason})");
        }
    }

    public function test_h4_ordinary_dictionary_reads_stay_allowed(): void
    {
        $checker = new \LightAgent\BuiltinTools\PythonSourceChecker();
        foreach ([
            "d = {'eval': 1}\nprint(d['eval'])",
            "value = config['run']",
            "x = d['input']",
            "x = d['system']",
            "print({'run': 1}['run'])",
            "items = ['eval', 'exec']",
        ] as $code) {
            [$allowed, $reason] = $checker->check($code);
            self::assertTrue($allowed, "plain data access must stay allowed: {$code} ({$reason})");
        }
    }

    /**
     * MCP stdio transport: the proc_open descriptor directions must be
     *   fd0 -> ['pipe','r'] (child reads stdin, parent writes)
     *   fd1/fd2 -> ['pipe','w'] (child writes stdout/stderr, parent reads)
     * The inverted mapping silently discarded every server reply and made
     * writes to stdin fail with errno=9, so the transport could never finish
     * a request. Because the defect was masked behind an environment-gated
     * "skip", it is re-asserted here WITHOUT any skip: a regression must fail
     * the suite loudly, never hide behind a skipped test.
     */
    public function test_c6_stdio_transport_round_trip_survives_descriptor_mapping(): void
    {
        $serverScript = dirname(__DIR__) . '/Mcp/fixture/test_mcp_server.php';
        self::assertFileExists($serverScript);

        \LightAgent\Async\AsyncRuntime::run(function () use ($serverScript): void {
            $transport = new \LightAgent\Mcp\StdioTransport(
                command: PHP_BINARY,
                args: [$serverScript],
            );
            try {
                $transport->start();

                $init = $transport->request('initialize', [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => [],
                    'clientInfo' => ['name' => 'LightAgent-Audit', 'version' => '1.0.0'],
                ]);
                self::assertSame('test-mcp-server', $init['serverInfo']['name'] ?? null);

                $tools = $transport->request('tools/list');
                self::assertSame(['add_numbers'], array_column($tools['tools'] ?? [], 'name'));

                $result = $transport->request('tools/call', [
                    'name' => 'add_numbers',
                    'arguments' => ['a' => 20, 'b' => 22],
                ]);
                self::assertSame(['sum' => 42], json_decode($result['content'][0]['text'] ?? '', true));
            } finally {
                $transport->stop();
            }
        });
    }

    // ------------------------------------------------------------------
    // H-14 / H-18 / C-5 / M-6 / H-17
    // ------------------------------------------------------------------

    /**
     * H-14: the Composer install path must resolve the vendored coroutine
     * libraries. composer.json cannot map them through PSR-4 (they are not
     * published Composer packages), so they are wired by the hand-written
     * `coroutine_bootstrap.php` listed in autoload.files.
     *
     * The check runs in a CHILD process that only applies the rules declared
     * in composer.json — inside PHPUnit the classes are already loaded by
     * the manual autoloader, so an in-process check would prove nothing.
     */
    public function test_h14_composer_install_path_resolves_vendored_coroutine_classes(): void
    {
        $root = dirname(__DIR__, 2);
        $code = <<<'PHP'
$root = %s;
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
spl_autoload_register(static function (string $class) use ($composer, $root): void {
    foreach (($composer['autoload']['psr-4'] ?? []) as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $root . '/' . rtrim($dir, '/') . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
            return;
        }
    }
});
foreach (($composer['autoload']['files'] ?? []) as $file) {
    $full = $root . '/' . $file;
    if (is_file($full)) {
        require_once $full;
    }
}
$missing = [];
foreach ([
    'Amp\\Internal\\FutureState',
    'Amp\\Future',
    'Revolt\\EventLoop',
    'Symfony\\Component\\Yaml\\Yaml',
] as $class) {
    if (!class_exists($class)) {
        $missing[] = $class;
    }
}
if (!interface_exists('Amp\\Sync\\Mutex')) {
    $missing[] = 'Amp\\Sync\\Mutex (interface)';
}
if (!function_exists('LightAgent\\Skills\\create_skill_tools')) {
    $missing[] = 'LightAgent\\Skills\\create_skill_tools() (namespaced function)';
}
$gather = \LightAgent\Async\AsyncRuntime::gather([
    static fn (): string => 'A',
    static fn (): string => 'B',
]);
echo json_encode(['missing' => $missing, 'gather' => $gather]);
PHP;
        $code = sprintf($code, var_export(str_replace('\\', '/', $root), true));

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open([PHP_BINARY, '-r', $code], $descriptors, $pipes, $root, null, ['bypass_shell' => true]);
        self::assertIsResource($proc);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($proc);

        $result = json_decode((string) $stdout, true);
        self::assertIsArray($result, 'child process produced no JSON: ' . $stderr);
        self::assertSame([], $result['missing'], 'vendored coroutine classes unresolved: ' . json_encode($result['missing']));
        self::assertSame(['A', 'B'], $result['gather']);
    }

    /**
     * H-18: the LightAgent constructor must expose the enterprise transport
     * options that OpenAiClient already supports (custom CA bundle, HTTP
     * proxy, extra gateway headers). Without them there is no framework-level
     * way to reach a self-signed / intercepting endpoint.
     */
    public function test_h18_light_agent_exposes_ca_bundle_proxy_and_extra_headers(): void
    {
        $agent = new \LightAgent\Core\LightAgent([
            'model' => 'mock',
            'api_key' => 'k',
            'base_url' => 'http://127.0.0.1:1/v1',
            'ca_bundle' => '/tmp/ca.pem',
            'proxy' => 'http://127.0.0.1:9',
            'extra_headers' => ['api-key' => 'secret'],
        ]);

        self::assertSame('/tmp/ca.pem', $agent->client->caBundle);
        self::assertSame('http://127.0.0.1:9', $agent->client->proxy);
        self::assertSame(['api-key' => 'secret'], $agent->client->extraHeaders);

        // The raw-header spelling must be folded into the same map.
        $raw = new \LightAgent\Core\LightAgent([
            'model' => 'mock',
            'api_key' => 'k',
            'base_url' => 'http://127.0.0.1:1/v1',
            'extra_headers' => ['api-key: secret'],
        ]);
        self::assertSame(['api-key' => 'secret'], $raw->client->extraHeaders);
    }

    /**
     * C-5: the MCP HTTP transport must send a well-formed Content-Type.
     *
     * The previous implementation appended pre-rendered header strings to the
     * associative header map and then zipped array_keys() with array_values(),
     * which produced a header literally named "0" and left curl to fall back
     * to application/x-www-form-urlencoded — every strict MCP server rejects
     * that. A real socket capture proves what the server actually receives.
     */
    public function test_c5_http_transport_sends_well_formed_content_type(): void
    {
        $dir = sys_get_temp_dir() . '/audit_c5_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $dump = $dir . '/capture.txt';
        $serverScript = $dir . '/server.php';
        file_put_contents($serverScript, <<<'PHP'
<?php
$port = (int) ($argv[1] ?? 0);
$dump = $argv[2] ?? '';
$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if ($server === false) {
    exit(1);
}
$deadline = microtime(true) + 20;
while (microtime(true) < $deadline) {
    $conn = @stream_socket_accept($server, 1.0);
    if ($conn === false) {
        continue;
    }
    stream_set_timeout($conn, 3);
    $raw = '';
    $len = null;
    while (microtime(true) < $deadline) {
        $chunk = @fread($conn, 8192);
        if (is_string($chunk) && $chunk !== '') {
            $raw .= $chunk;
        }
        if (str_contains($raw, "\r\n\r\n")) {
            $head = substr($raw, 0, (int) strpos($raw, "\r\n\r\n"));
            foreach (explode("\r\n", $head) as $line) {
                if (stripos($line, 'content-length:') === 0) {
                    $len = (int) trim(substr($line, 15));
                }
            }
            $bodyStart = (int) strpos($raw, "\r\n\r\n") + 4;
            if ($len !== null && strlen($raw) - $bodyStart >= $len) {
                break;
            }
        }
        usleep(20000);
    }
    file_put_contents($dump, $raw);
    $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2024-11-05']]);
    @fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
    fclose($conn);
    exit(0);
}
PHP);

        $port = 0;
        for ($p = 24100; $p < 24500; $p++) {
            $fp = @fsockopen('127.0.0.1', $p, $e1, $e2, 0.2);
            if ($fp === false) {
                $port = $p;
                break;
            }
            fclose($fp);
        }
        self::assertGreaterThan(0, $port, 'no free port for the capture server');

        $proc = proc_open(
            [PHP_BINARY, $serverScript, (string) $port, $dump],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        self::assertIsResource($proc);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        usleep(700000);

        try {
            $transport = new \LightAgent\Mcp\HttpTransport(
                url: "http://127.0.0.1:{$port}/mcp",
                headers: ['Authorization' => 'Bearer test-token-123'],
                timeout: 10.0,
            );
            try {
                $transport->request('tools/list');
            } catch (\Throwable) {
                // The capture is the assertion; the reply body is irrelevant.
            }
            usleep(700000);

            $raw = (string) @file_get_contents($dump);
            self::assertNotSame('', $raw, 'the capture server received nothing');
            $head = substr($raw, 0, (int) strpos($raw, "\r\n\r\n"));

            self::assertMatchesRegularExpression(
                '/^Content-Type:\s*application\/json\s*$/mi',
                $head,
                'Content-Type must be application/json',
            );
            self::assertDoesNotMatchRegularExpression(
                '/^Content-Type:\s*application\/x-www-form-urlencoded\s*$/mi',
                $head,
                'curl must not fall back to form-urlencoded',
            );
            self::assertDoesNotMatchRegularExpression(
                '/^\d+:\s/m',
                $head,
                'no header may be sent with a numeric name',
            );
            self::assertStringContainsString('Authorization: Bearer test-token-123', $head);
        } finally {
            if (is_resource($proc)) {
                $status = proc_get_status($proc);
                @exec('taskkill /PID ' . $status['pid'] . ' /F 2>nul');
                @proc_close($proc);
            }
            @unlink($serverScript);
            @unlink($dump);
            @rmdir($dir);
        }
    }

    /**
     * M-6: the `/body/flags` dialect must keep the modifier case. Lower-casing
     * turned `S` into `s` (dotall), `U` into `u` (utf8) and `X` into `x`
     * (extended) — different matching semantics under the same name.
     *
     * Only *valid* PCRE modifiers are captured. An unsupported modifier
     * letter is not captured either, so the whole string falls back to the
     * literal form: the `/body/flags` spelling is inherently ambiguous
     * (`/path/to/e` is "body `path/to` with modifier `e`" OR "the literal
     * path `/path/to/e`"), and capturing unsupported letters made every such
     * rule an invalid PCRE that rejected ALL memory writes.
     */
    public function test_m6_pattern_modifiers_keep_their_pcre_case(): void
    {
        // `S` (non-atomic) must NOT behave like `s` (dotall).
        $dotall = new MemoryPolicy(rejectWritePatterns: ['/x.y/s']);
        $nonAtomic = new MemoryPolicy(rejectWritePatterns: ['/x.y/S']);
        $subject = "x\ny";
        self::assertFalse(
            $dotall->allowsWrite($subject)->allowed,
            's (dotall) must match a newline and therefore reject the write',
        );
        self::assertTrue(
            $nonAtomic->allowsWrite($subject)->allowed,
            'S (non-atomic) must NOT behave like s (dotall)',
        );

        // `X` (extended-more) must NOT behave like `x` (extended).
        $extended = new MemoryPolicy(rejectWritePatterns: ['/(a  b)/x']);
        $extendedMore = new MemoryPolicy(rejectWritePatterns: ['/(a  b)/X']);
        self::assertFalse(
            $extended->allowsWrite('ab')->allowed,
            'x (extended) must ignore whitespace',
        );
        self::assertTrue(
            $extendedMore->allowsWrite('ab')->allowed,
            'X (extended-more) must NOT behave like x (extended)',
        );

        // Unsupported modifier letters are NOT captured: the rule stays a
        // usable literal instead of becoming an invalid PCRE that rejects
        // every write.
        foreach (['e', 'E'] as $bad) {
            $policy = new MemoryPolicy(rejectWritePatterns: ["/secret/{$bad}"]);
            $decision = $policy->allowsWrite('totally unrelated text');
            self::assertTrue(
                $decision->allowed,
                "an unsupported modifier '{$bad}' must not reject unrelated writes",
            );
            self::assertStringNotContainsString(
                'fail-closed',
                (string) $decision->reason,
                "an unsupported modifier '{$bad}' must not be read as an invalid modifier",
            );
        }

        // The ordinary spellings keep working.
        $plain = new MemoryPolicy(rejectWritePatterns: ['/etc/passwd/']);
        self::assertFalse($plain->allowsWrite('the path is /etc/passwd')->allowed);
        $flagged = new MemoryPolicy(rejectWritePatterns: ['/etc/passwd/i']);
        self::assertFalse($flagged->allowsWrite('the path is /etc/passwd')->allowed);
    }

    /**
     * H-17: a transport failure on the streaming path must surface as a
     * transport error, not as "the provider returned an empty response".
     * The stream is lazy, so the failure happens while the generator is
     * consumed; the last error must be reported instead of a generic string.
     */
    public function test_h17_stream_transport_error_is_not_reported_as_empty_response(): void
    {
        // Port 9 (discard) refuses connections.
        $options = [
            'model' => 'mock',
            'api_key' => 'k',
            'base_url' => 'http://127.0.0.1:9/v1',
            'instructions' => 't',
            'auto_discover_skills' => false,
        ];

        $agent = new \LightAgent\Core\LightAgent($options);
        $nonStream = (string) $agent->run('hi');
        self::assertStringNotContainsString(
            'LA-EMPTY-RESPONSE',
            $nonStream,
            'the non-stream path must not report a transport failure as an empty response',
        );

        $streaming = new \LightAgent\Core\LightAgent($options);
        $streamed = '';
        foreach ($streaming->run('hi', ['stream' => true]) as $chunk) {
            if (is_string($chunk)) {
                $streamed .= $chunk;
            }
        }
        self::assertStringNotContainsString(
            'LA-EMPTY-RESPONSE',
            $streamed,
            'the stream path must not report a transport failure as an empty response',
        );
        self::assertNotSame(
            'Failed to stream a valid response.',
            $streamed,
            'the stream path must not swallow the transport error behind a generic string',
        );
    }

    // ------------------------------------------------------------------
    // T6 / M-6
    // ------------------------------------------------------------------

    /**
     * T6: `(bool) 'false'` is TRUE in PHP, so every place that read a
     * boolean-ish value with a plain cast turned a "deny" into an "allow".
     * Six call sites were affected; the human-approval one was the worst
     * (a reviewer's "reject" became an "approve").
     */
    public function test_t6_string_false_is_never_treated_as_allowed(): void
    {
        // 1) GuardrailManager
        $guardrails = new \LightAgent\Guardrails\GuardrailManager(
            outputGuardrails: [static fn ($value, array $context): array => ['allowed' => 'false']],
        );
        self::assertFalse($guardrails->checkOutput('x')->allowed, 'a guardrail answering "false" must block');

        $guardrailsTrue = new \LightAgent\Guardrails\GuardrailManager(
            outputGuardrails: [static fn ($value, array $context): array => ['allowed' => 'true']],
        );
        self::assertTrue($guardrailsTrue->checkOutput('x')->allowed, '"true" must still allow');

        // 2) PolicyEngine
        $engine = new \LightAgent\Capabilities\PolicyEngine();
        $engine->add(static fn ($request): array => ['allowed' => 'false']);
        $decision = $engine->evaluate(new \LightAgent\Capabilities\PolicyRequest(
            capability: new \LightAgent\Capabilities\CapabilitySpec('cap.demo', read: true),
            providerName: 'p',
            arguments: [],
            context: new \LightAgent\Capabilities\RuntimeContext(runtimeId: 'r'),
        ));
        self::assertFalse($decision->allowed, 'a policy answering "false" must block');

        // 3) MemoryPolicy
        $policy = new MemoryPolicy(memoryWriteAdmission: static fn ($candidate, array $context): array => ['allowed' => 'false']);
        self::assertFalse($policy->allowsWrite('candidate')->allowed, 'a memory admission answering "false" must block');

        // 4) HumanApprovalHook — the human "reject" must not become an "approve".
        $hook = new \LightAgent\Review\HumanApprovalHook(
            reviewer: static fn ($request): array => ['approved' => 'false'],
            tools: ['delete_file'],
        );
        $hookDecision = $hook(new \LightAgent\Hooks\HookContext(
            phase: 'before_tool_call',
            payload: ['tool_name' => 'delete_file'],
        ));
        self::assertSame('block', $hookDecision->action, 'a reviewer answering "false" must block the action');

        $hookAllowed = new \LightAgent\Review\HumanApprovalHook(
            reviewer: static fn ($request): array => ['allowed' => 'false'],
            tools: ['delete_file'],
        );
        self::assertSame('block', $hookAllowed(new \LightAgent\Hooks\HookContext(
            phase: 'before_tool_call',
            payload: ['tool_name' => 'delete_file'],
        ))->action, 'a reviewer answering allowed="false" must block the action');

        $hookApprove = new \LightAgent\Review\HumanApprovalHook(
            reviewer: static fn ($request): array => ['approved' => 'true'],
            tools: ['delete_file'],
        );
        self::assertSame('continue', $hookApprove(new \LightAgent\Hooks\HookContext(
            phase: 'before_tool_call',
            payload: ['tool_name' => 'delete_file'],
        ))->action, '"true" must still approve');

        // 5/6) SecurityContext: "no network" / "sandbox required" must not flip.
        $network = SecurityContext::fromDict(['network_allowed' => 'false']);
        self::assertFalse($network->networkAllowed, '"network_allowed"="false" must stay false');
        $sandbox = SecurityContext::fromDict(['sandbox_required' => 'false']);
        self::assertFalse($sandbox->sandboxRequired, '"sandbox_required"="false" must stay false');
        $networkTrue = SecurityContext::fromDict(['network_allowed' => 'true']);
        self::assertTrue($networkTrue->networkAllowed, '"network_allowed"="true" must stay true');
    }

    /**
     * T6: a *missing* key keeps the caller-specific default. A guardrail
     * without an "allowed" key expresses no opinion (allow); a context
     * without "network_allowed" keeps the safe default (deny).
     */
    public function test_t6_missing_key_keeps_the_caller_default(): void
    {
        $guardrails = new \LightAgent\Guardrails\GuardrailManager(
            outputGuardrails: [static fn ($value, array $context): array => ['reason' => 'no opinion']],
        );
        self::assertTrue($guardrails->checkOutput('x')->allowed, 'a guardrail without "allowed" means no opinion');

        $context = SecurityContext::fromDict([]);
        self::assertFalse($context->networkAllowed, 'a context without "network_allowed" denies network');
        self::assertFalse($context->sandboxRequired, 'a context without "sandbox_required" requires no sandbox');
    }

    /**
     * M-6: the `/body/flags` dialect must not read a trailing path segment
     * as a PCRE modifier. Capturing unsupported modifier letters made every
     * rule whose last segment was one of them (`/etc/e`, `/var/L`, …) an
     * invalid PCRE, i.e. a rule that silently rejected EVERY memory write.
     */
    public function test_m6_trailing_path_segment_is_not_read_as_a_modifier(): void
    {
        foreach (['/path/to/e', '/path/to/E', '/path/to/a', '/path/to/L', '/path/to/b'] as $pattern) {
            $policy = new MemoryPolicy(rejectWritePatterns: [$pattern]);
            // The subject must contain the WHOLE literal (leading slash
            // included) — the rule falls back to the literal form, so the
            // path is matched verbatim.
            $decision = $policy->allowsWrite('the file is ' . $pattern);
            self::assertFalse($decision->allowed, "{$pattern} must match its literal path");
            self::assertStringNotContainsString(
                'fail-closed',
                (string) $decision->reason,
                "{$pattern} must not be mistaken for an invalid modifier",
            );
        }
    }

    /**
     * M-6: the valid PCRE modifiers keep their case, so `S` is not `s` and
     * `X` is not `x`.
     */
    public function test_m6_valid_pcre_modifiers_keep_their_case(): void
    {
        $dotall = new MemoryPolicy(rejectWritePatterns: ['/x.y/s']);
        self::assertFalse($dotall->allowsWrite("x\ny")->allowed, 's (dotall) matches a newline');

        $nonAtomic = new MemoryPolicy(rejectWritePatterns: ['/x.y/S']);
        self::assertTrue($nonAtomic->allowsWrite("x\ny")->allowed, 'S (non-atomic) must not behave like s (dotall)');

        $extended = new MemoryPolicy(rejectWritePatterns: ['/(a  b)/x']);
        self::assertFalse($extended->allowsWrite('ab')->allowed, 'x (extended) ignores whitespace');

        $extendedMore = new MemoryPolicy(rejectWritePatterns: ['/(a  b)/X']);
        self::assertTrue($extendedMore->allowsWrite('ab')->allowed, 'X (extended-more) must not behave like x (extended)');
    }

    /**
     * M-6: a genuinely broken regex body still fails closed.
     */
    public function test_m6_invalid_regex_body_still_fails_closed(): void
    {
        $policy = new MemoryPolicy(rejectWritePatterns: ['secret)']);
        $decision = $policy->allowsWrite('totally unrelated content');
        self::assertFalse($decision->allowed);
        self::assertStringContainsString('fail-closed', (string) $decision->reason);
    }

    /**
     * T16: the PHP sandbox checker must behave like the Python original.
     *
     * The original parses Python source with CPython's real `ast`, so it
     * sees f-string interpolation slots, decodes `\xNN` escapes and resolves
     * subscript aliases. The PHP port used a hand-written subset parser that
     * did none of that — it was LOOSER than the original, which turned
     * `f"{__import__('os').system('id')}"` into arbitrary command execution.
     *
     * The expectations below are the Python original's own verdicts.
     */
    public function test_t16_checker_matches_the_python_original_verdicts(): void
    {
        $checker = new \LightAgent\BuiltinTools\PythonSourceChecker();

        // [code, python original allowed]
        $cases = [
            // f-string interpolation slots
            ['f"{eval(\'6*7\')}"', false],
            ['print(f"{__import__(\'os\').system(\'id\')}")', false],
            ['rf"{eval(\'6*7\')}"', false],
            ['f"{f\'{eval(chr(54)+chr(42)+chr(55))}\'}"', false],
            ['f"{x + 1}"', true],
            ['f"{value=}"', true],
            ['f"{value:>10}"', true],
            ['f"{ {\'a\': 1} }"', true],
            // escape decoding
            ['e = getattr(__builtins__, "ev\x61l"); print(e("6*7"))', false],
            ['getattr(__builtins__, "ev\x61l")', false],
            ['getattr(__builtins__, "eval")', false],
            // subscript aliases
            ['d = vars(__builtins__); x = d["eval"]; print(x("6*7"))', false],
            ['x = d[\'eval\']; x(\'6*7\')', false],
            ['x = d[\'eval\']', true],
            ['x = d[\'input\']', true],
            ['x = d[\'input\']; print(x)', false],
            // untouched behaviour
            ['d = {\'eval\': 1}' . "\n" . 'print(d[\'eval\'])', true],
            ['config[\'run\']', true],
            ['value = config[\'run\']', true],
            ['import os', false],
            ['eval("1+1")', false],
            ['open("/etc/passwd")', false],
        ];

        foreach ($cases as [$code, $pythonAllowed]) {
            [$allowed] = $checker->check($code);
            self::assertSame(
                $pythonAllowed,
                $allowed,
                'the PHP checker must reach the same verdict as the Python original for: ' . $code,
            );
        }
    }

    // ------------------------------------------------------------------
    // B3 · JSON helpers, timestamp precision, review-store safety
    // ------------------------------------------------------------------

    /**
     * B3/T5b: a digest over a value JSON cannot encode must not collapse
     * into one shared literal. Two different unencodable payloads would then
     * verify against the same token.
     */
    public function test_b3_canonical_json_refuses_unencodable_values(): void
    {
        $this->expectException(\RuntimeException::class);
        \LightAgent\Security\SecurityUtils::canonicalJson(['amount' => INF]);
    }

    public function test_b3_canonical_digest_never_collides_on_unencodable_values(): void
    {
        $produced = 0;
        $rejected = 0;
        foreach ([['x' => INF], ['x' => NAN], ['x' => -INF]] as $value) {
            try {
                \LightAgent\Security\SecurityUtils::canonicalDigest($value);
                $produced++;
            } catch (\RuntimeException) {
                $rejected++;
            }
        }
        self::assertSame(0, $produced, 'an unencodable payload must never produce a digest');
        self::assertSame(3, $rejected, 'every unencodable payload must be rejected');
    }

    /**
     * B3: `json_decode(json_encode($x), true) ?? []` is NOT a guard. Under
     * strict_types the `false` that json_encode() returns for an
     * unrepresentable value reaches json_decode() and raises a TypeError
     * before `??` can ever help.
     */
    public function test_b3_json_helper_degrades_instead_of_raising_a_type_error(): void
    {
        $broken = "\xB1\x31"; // invalid UTF-8
        self::assertSame([], \LightAgent\Support\Json::decode($broken));
        self::assertSame(['fallback' => true], \LightAgent\Support\Json::decode($broken, ['fallback' => true]));
        self::assertIsString(\LightAgent\Support\Json::encode($broken));
        self::assertNotSame('', \LightAgent\Support\Json::encode($broken));
    }

    /**
     * B3/T8: DateTimeInterface::ATOM renders whole seconds only, so a lease
     * or an expiry could land on a window of zero seconds and expire the
     * instant it was created.
     */
    public function test_b3_timestamp_keeps_millisecond_precision(): void
    {
        $now = \LightAgent\Support\Timestamp::utcNow();
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}\+00:00$/',
            $now,
            'the timestamp must carry a millisecond component',
        );

        $before = microtime(true);
        $expiry = \LightAgent\Support\Timestamp::inSeconds(0.25);
        $parsed = (float) \LightAgent\Support\Timestamp::parse($expiry)->format('U.u');
        self::assertGreaterThan(
            0.15,
            $parsed - $before,
            'a sub-second lease must not collapse onto a whole second',
        );
        self::assertLessThan(0.60, $parsed - $before, 'the lease must not drift');
    }

    /**
     * B3/T15: a failed encode must never replace the approval store with a
     * 0-byte file — every pending approval would be lost silently.
     */
    public function test_b3_review_store_keeps_the_original_file_when_the_write_fails(): void
    {
        $path = $this->tmpDir . '/review.json';
        $store = new \LightAgent\Review\JsonReviewStore($path);
        $store->createRequest(new \LightAgent\Review\ApprovalRequest(
            action: \LightAgent\Review\ApprovalRequest::ACTION_PENDING,
            requestId: 'req-1',
            actionName: 'deploy',
        ));
        $original = (string) file_get_contents($path);
        self::assertNotSame('', $original, 'the store must have been written once');

        // INF cannot be represented in JSON, so the payload cannot be encoded.
        try {
            $store->createRequest(new \LightAgent\Review\ApprovalRequest(
                action: \LightAgent\Review\ApprovalRequest::ACTION_PENDING,
                requestId: 'req-2',
                actionName: 'deploy',
                arguments: ['amount' => INF],
            ));
            self::fail('an unencodable payload must be reported');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('cannot be encoded', $e->getMessage());
        }

        self::assertFileExists($path, 'the store must survive a failed write');
        self::assertSame($original, (string) file_get_contents($path), 'the store must be untouched');
    }

    // ------------------------------------------------------------------
    // B4 · streaming finalisation, guardrails and token accounting
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $options
     */
    private function makeAuditAgent(array $options = []): AuditTestableAgent
    {
        return new AuditTestableAgent(array_merge([
            'model' => 'gpt-4o-mini',
            'api_key' => 'test-key',
            'base_url' => 'http://127.0.0.1:9/v1',
            'auto_discover_skills' => false,
        ], $options));
    }

    /**
     * B4/T12: the provider sends the usage frame AFTER the chunk that carries
     * finish_reason. Returning on finish_reason dropped that frame, so the
     * token budget was never charged for a streaming run.
     */
    public function test_b4_streaming_token_budget_is_charged_from_the_usage_frame(): void
    {
        $agent = $this->makeAuditAgent();
        $backend = new AuditLateUsageStream();
        $agent->useBackend($backend);

        $chunks = [];
        foreach ($agent->run('hi', ['stream' => true]) as $chunk) {
            $chunks[] = $chunk;
        }

        self::assertSame('Hello world', implode('', array_filter($chunks, 'is_string')));
        self::assertSame(
            18,
            $agent->runtime->budget->usage->tokens,
            'the trailing usage frame must be charged against the token budget',
        );
        self::assertSame(
            ['include_usage' => true],
            $backend->calls[0]['stream_options'] ?? null,
            'the provider must be asked for the usage frame',
        );
    }

    public function test_b4_stream_options_can_be_disabled(): void
    {
        $agent = $this->makeAuditAgent(['stream_include_usage' => false]);
        $backend = new AuditLateUsageStream();
        $agent->useBackend($backend);
        foreach ($agent->run('hi', ['stream' => true]) as $chunk) {
            // drain
        }
        self::assertArrayNotHasKey(
            'stream_options',
            $backend->calls[0],
            'stream_include_usage=false must keep the field out of the request',
        );
    }

    /**
     * B4: the output guardrails must run on a streaming answer as well.
     */
    public function test_b4_streaming_output_is_redacted(): void
    {
        // A secret that arrives inside ONE chunk is redacted before it
        // reaches the consumer.
        $agent = $this->makeAuditAgent([
            'output_guardrails' => [
                \LightAgent\Guardrails\GuardrailFactories::outputRedaction(['sk-[A-Za-z0-9]+']),
            ],
        ]);
        $agent->useBackend(new AuditSecretStream());
        $chunks = [];
        foreach ($agent->run('hi', ['stream' => true]) as $chunk) {
            $chunks[] = $chunk;
        }
        $streamed = implode('', array_filter($chunks, 'is_string'));
        self::assertStringNotContainsString('sk-abcdef123456', $streamed, 'a one-chunk secret must never leave');
        self::assertStringContainsString('[redacted]', $streamed);

        // A secret split across two chunks can only be caught on the complete
        // text, so the final answer must be redacted.
        $agent2 = $this->makeAuditAgent([
            'output_guardrails' => [
                \LightAgent\Guardrails\GuardrailFactories::outputRedaction(['sk-[A-Za-z0-9]+']),
            ],
            'hooks' => [
                static function (\LightAgent\Hooks\HookContext $ctx): ?\LightAgent\Hooks\HookDecision {
                    if ($ctx->phase !== 'after_run') {
                        return null;
                    }
                    AuditRunCapture::$content = (string) ($ctx->payload['content'] ?? '');
                    AuditRunCapture::$success = (bool) ($ctx->payload['success'] ?? false);
                    return null;
                },
            ],
        ]);
        $agent2->useBackend(new AuditSplitSecretStream());
        foreach ($agent2->run('hi', ['stream' => true]) as $chunk) {
            // drain
        }
        self::assertSame('here you go: [redacted]', AuditRunCapture::$content);
        self::assertTrue(AuditRunCapture::$success);
    }

    /**
     * B4: the `after_model_response` hook must fire exactly once per run,
     * not once per streamed chunk.
     */
    public function test_b4_after_model_response_hook_runs_once_per_stream(): void
    {
        $calls = 0;
        $agent = $this->makeAuditAgent([
            'hooks' => [
                static function (\LightAgent\Hooks\HookContext $ctx) use (&$calls): ?\LightAgent\Hooks\HookDecision {
                    if ($ctx->phase === 'after_model_response') {
                        $calls++;
                    }
                    return null;
                },
            ],
        ]);
        $agent->useBackend(new AuditLateUsageStream());
        foreach ($agent->run('hi', ['stream' => true]) as $chunk) {
            // drain
        }
        self::assertSame(1, $calls, 'the hook must run once on the final text, not per chunk');
    }

    /**
     * B4 (PORTING-DIFF): a blocked `after_model_response` hook must be
     * reported as a failure. The Python original only looks for the
     * "[LA-GUARDRAIL]" prefix, so it reports the hook envelope as a
     * successful answer.
     */
    public function test_b4_blocked_after_model_response_hook_is_not_reported_as_success(): void
    {
        AuditRunCapture::$content = null;
        AuditRunCapture::$success = null;
        $agent = $this->makeAuditAgent([
            'hooks' => [
                static function (\LightAgent\Hooks\HookContext $ctx): ?\LightAgent\Hooks\HookDecision {
                    if ($ctx->phase === 'after_model_response') {
                        return \LightAgent\Hooks\HookDecision::block('not allowed');
                    }
                    if ($ctx->phase === 'after_run') {
                        AuditRunCapture::$content = (string) ($ctx->payload['content'] ?? '');
                        AuditRunCapture::$success = (bool) ($ctx->payload['success'] ?? true);
                    }
                    return null;
                },
            ],
        ]);
        $agent->useBackend(new AuditStaticCompletions('the answer'));

        $result = $agent->run('hi');

        self::assertIsString($result);
        self::assertStringStartsWith('[LA-HOOK]', $result, 'the hook envelope must be returned');
        self::assertFalse(AuditRunCapture::$success, 'a blocked hook must not be reported as a successful run');
    }

    /**
     * B4/T12: the non-streaming tool loop must be bounded by
     * max_tool_iterations as well. The Python original bounds only the
     * streaming loop there (PORTING-DIFF, stricter on purpose).
     */
    public function test_b4_non_stream_tool_loop_is_bounded_by_max_tool_iterations(): void
    {
        $agent = $this->makeAuditAgent();
        $backend = new AuditToolLoopCompletions();
        $agent->useBackend($backend);

        $ping = new ToolFunction(
            callable: static fn (mixed ...$args): string => 'pong',
            toolInfo: [
                'tool_name' => 'ping',
                'tool_description' => 'Ping the service.',
                'tool_params' => [],
            ],
        );

        $result = $agent->run('go', ['max_tool_iterations' => 2, 'tools' => [$ping]]);

        self::assertIsString($result);
        self::assertStringContainsString('Max tool iterations(2) reached.', $result);
        self::assertSame(2, $backend->calls, 'the model must not be called beyond the limit');
    }

    // ------------------------------------------------------------------
    // T5b · a JSON encoding failure must never collapse into a shared value
    // ------------------------------------------------------------------

    /**
     * T5b: `(string) json_encode($x)` turns an encoding failure into `''`.
     * A tool result that becomes `''` is **silent data loss** — the model
     * simply sees an empty answer. `Json::encodeOrError()` makes the failure
     * visible instead.
     */
    public function test_b3_encode_or_error_reports_an_encoding_failure(): void
    {
        self::assertSame('{"a":1}', \LightAgent\Support\Json::encodeOrError(['a' => 1]));
        self::assertStringStartsWith(
            '[LA-JSON]',
            \LightAgent\Support\Json::encodeOrError(['a' => INF]),
            'an unencodable value must be visible, not an empty string',
        );
        // Invalid UTF-8 is substituted instead of failing at all.
        self::assertStringContainsString('a', \LightAgent\Support\Json::encodeOrError(['a' => "\xB1\x31"]));
    }

    public function test_b3_tool_dispatcher_never_returns_an_empty_result_on_failure(): void
    {
        $registry = new \LightAgent\Tools\ToolRegistry();
        $registry->registerTool(new ToolFunction(
            callable: static fn (): array => ['value' => INF],
            toolInfo: [
                'tool_name' => 'unencodable',
                'tool_description' => 'Returns a value that JSON cannot encode.',
                'tool_params' => [],
            ],
        ));
        $dispatcher = new \LightAgent\Tools\ToolDispatcher($registry);

        $result = (string) $dispatcher->dispatch('unencodable', []);
        self::assertNotSame('', $result, 'an encoding failure must not become an empty tool result');
        self::assertStringContainsString('[LA-JSON]', $result);
    }

    /**
     * T5b: the DAG canonical form is used for digests. Returning '' for an
     * unencodable value would give every such state the SAME digest.
     */
    public function test_b3_dag_canonical_json_refuses_unencodable_values(): void
    {
        $this->expectException(\RuntimeException::class);
        \LightAgent\Dag\DAGConstants::canonicalJson(['x' => INF]);
    }

    /**
     * T5b/T15: a failed flow-store write must not replace the stored run
     * with a 0-byte file.
     */
    public function test_b3_flow_store_keeps_the_original_run_when_the_write_fails(): void
    {
        $directory = $this->tmpDir . '/flows';
        $store = new \LightAgent\Flow\JsonLightFlowStore($directory);
        $store->saveRun('run-1', ['state' => 'ok']);
        $path = $directory . DIRECTORY_SEPARATOR . 'run-1.json';
        self::assertFileExists($path);
        $original = (string) file_get_contents($path);

        try {
            $store->saveRun('run-1', ['state' => INF]);
            self::fail('an unencodable record must be reported');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('cannot be encoded', $e->getMessage());
        }

        self::assertFileExists($path, 'the stored run must survive a failed write');
        self::assertSame($original, (string) file_get_contents($path), 'the stored run must be untouched');
    }

    // ------------------------------------------------------------------
    // T1 · the dispatcher must hold the registry, not a snapshot of it
    // ------------------------------------------------------------------

    /**
     * T1: PHP arrays are value types, so `new ToolDispatcher($registry->functionMappings, ...)`
     * captured the registry's contents AT CONSTRUCTION TIME. The built-in
     * `safe_expression` is registered after the dispatcher was built, so the
     * dispatcher never saw it — and any tool registered straight on the public
     * registry stayed invisible.
     */
    public function test_t1_dispatcher_sees_tools_registered_after_construction(): void
    {
        $agent = new \LightAgent\Core\LightAgent([
            'model' => 'gpt-4o-mini',
            'api_key' => 'test-key',
            'base_url' => 'http://127.0.0.1:9/v1',
            'auto_discover_skills' => false,
        ]);

        self::assertContains(
            'safe_expression',
            array_keys($agent->toolDispatcher->functionMappings()),
            'the dispatcher must see the built-in tool registered after it was built',
        );

        // Registered straight on the public registry — no rebuild in sight.
        $agent->toolRegistry->registerTool(new ToolFunction(
            callable: static fn (): string => 'direct',
            toolInfo: [
                'tool_name' => 'direct_tool',
                'tool_description' => 'Registered straight on the registry.',
                'tool_params' => [],
            ],
        ));

        self::assertSame(
            'direct',
            (string) $agent->toolDispatcher->dispatch('direct_tool', []),
            'a tool registered on the registry must be dispatchable right away',
        );
    }

    /**
     * T1: the legacy array constructor still works, but it is a SNAPSHOT by
     * definition — the registry reference is the only way to see later
     * registrations.
     */
    public function test_t1_legacy_dispatcher_constructor_still_works(): void
    {
        $registry = new \LightAgent\Tools\ToolRegistry();
        $registry->registerTool(new ToolFunction(
            callable: static fn (): string => 'snap',
            toolInfo: [
                'tool_name' => 'snap_tool',
                'tool_description' => 'Registered before the dispatcher.',
                'tool_params' => [],
            ],
        ));

        $legacy = new \LightAgent\Tools\ToolDispatcher(
            $registry->functionMappings,
            $registry->functionInfo,
        );
        self::assertSame('snap', (string) $legacy->dispatch('snap_tool', []));

        // The live view of the registry is what the framework uses.
        $live = new \LightAgent\Tools\ToolDispatcher($registry);
        self::assertContains('snap_tool', array_keys($live->functionMappings()));
    }

    // ------------------------------------------------------------------
    // T2 · the sandbox block must compare the callable, not the name
    // ------------------------------------------------------------------

    /**
     * T2: the Python original blocks the legacy Python execution tools by
     * comparing the FUNCTION OBJECT (`inspect.unwrap(tool_call) in (...)`).
     * The PHP port compared the tool NAME only, so the same executor
     * registered under another name was NOT blocked and executed Python on
     * the host.
     */
    public function test_t2_renamed_python_executor_is_still_blocked(): void
    {
        $executor = \LightAgent\BuiltinTools\PythonExecutorTool::createExecuteCode();
        $renamed = new ToolFunction(
            callable: $executor->callable,
            toolInfo: [
                'tool_name' => 'run_py',
                'tool_description' => 'The same executor under another name.',
                'tool_params' => [],
            ],
        );

        $agent = new \LightAgent\Core\LightAgent([
            'model' => 'gpt-4o-mini',
            'api_key' => 'test-key',
            'base_url' => 'http://127.0.0.1:9/v1',
            'auto_discover_skills' => false,
        ]);
        $agent->registerTool($renamed);

        $result = (string) $agent->toolDispatcher->dispatch('run_py', ['code' => 'print(1)']);
        self::assertStringContainsString(
            'LA-SANDBOX',
            $result,
            'a renamed Python executor must stay blocked — the block compares the callable, not the name',
        );
        self::assertStringNotContainsString('1', trim(str_replace('LA-SANDBOX', '', $result)));

        // The agent-level gate applies the same rule.
        $gateAgent = new class (['model' => 'm', 'api_key' => 'k', 'base_url' => 'http://127.0.0.1:9/v1']) extends \LightAgent\Core\LightAgent {
            public function gate(string $toolName, array $arguments): array
            {
                return $this->prepareToolCall($toolName, $arguments);
            }
        };
        $gateAgent->registerTool($renamed);
        [$args, $error] = $gateAgent->gate('run_py', []);
        self::assertNotNull($error, 'the agent-level sandbox gate must reject the renamed executor');
        self::assertStringContainsString('LA-SANDBOX', (string) $error);
        self::assertSame([], $args);
    }

    /**
     * T2: the identity of a callable is stable across instances and tells
     * the Python executor apart from an ordinary user closure.
     */
    public function test_t2_callable_identity_is_stable_and_distinguishing(): void
    {
        $first = \LightAgent\BuiltinTools\PythonExecutorTool::createExecuteCode();
        $second = \LightAgent\BuiltinTools\PythonExecutorTool::createExecuteCode();
        self::assertSame(
            \LightAgent\Tools\ToolRegistry::callableIdentity($first->callable),
            \LightAgent\Tools\ToolRegistry::callableIdentity($second->callable),
            'two instances of the same factory must share one identity',
        );
        self::assertTrue(\LightAgent\Tools\ToolRegistry::isUnsafePythonCallable($first->callable));
        self::assertTrue(\LightAgent\Tools\ToolRegistry::isUnsafePythonCallable($second->callable));

        $safe = \LightAgent\BuiltinTools\SafeExpressionTool::create();
        self::assertFalse(
            \LightAgent\Tools\ToolRegistry::isUnsafePythonCallable($safe->callable),
            'the safe expression tool must not be mistaken for a Python executor',
        );

        $userClosure = static fn (): string => 'hi';
        self::assertFalse(\LightAgent\Tools\ToolRegistry::isUnsafePythonCallable($userClosure));
    }

    // ------------------------------------------------------------------
    // T4 · loading semantics (namespaced function files)
    // ------------------------------------------------------------------

    /**
     * T4: `src/Skills/create_skill_tools.php` declares a namespaced
     * FUNCTION, which PSR-4 cannot load. Without an explicit require the
     * `auto_discover_skills => true` path dies with
     * "Call to undefined function LightAgent\Skills\create_skill_tools()".
     */
    public function test_t4_skill_tools_function_is_loaded(): void
    {
        self::assertTrue(
            function_exists('LightAgent\Skills\create_skill_tools'),
            'the namespaced function file must be loaded explicitly',
        );

        $skillDir = $this->tmpDir . '/skills/demo';
        mkdir($skillDir, 0777, true);
        file_put_contents(
            $skillDir . '/SKILL.md',
            "---\nname: demo\ndescription: A demo skill.\n---\n\nDo the demo.\n",
        );

        $agent = new \LightAgent\Core\LightAgent([
            'model' => 'gpt-4o-mini',
            'api_key' => 'test-key',
            'base_url' => 'http://127.0.0.1:9/v1',
            'auto_discover_skills' => true,
            'skills_directories' => [$this->tmpDir . '/skills'],
        ]);

        $names = array_keys($agent->toolRegistry->functionMappings ?? []);
        foreach (['list_skills', 'activate_skill', 'execute_skill_script', 'read_skill_reference'] as $tool) {
            self::assertContains($tool, $names, "the skill tool {$tool} must be registered");
        }
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /** @var resource|null */
    private $serverProcess;
    private ?int $serverPort = null;
}

/**
 * Captures the payload of the `after_run` hook so a test can inspect the
 * final content and the success flag of a run.
 */
final class AuditRunCapture
{
    public static ?string $content = null;
    public static ?bool $success = null;
}

/**
 * Agent wired to an injectable completions backend.
 */
final class AuditTestableAgent extends \LightAgent\Core\LightAgent
{
    public function useBackend(mixed $backend): void
    {
        $this->client = new AuditFakeClient($backend);
    }
}

final class AuditFakeClient
{
    public function __construct(private readonly mixed $backend)
    {
    }

    public function chat(): AuditFakeCompletions
    {
        return new AuditFakeCompletions($this->backend);
    }
}

final class AuditFakeCompletions
{
    public function __construct(private readonly mixed $backend)
    {
    }

    public function create(array $params): mixed
    {
        return $this->backend->create($params);
    }
}

/**
 * Streaming backend that sends the usage frame in a SEPARATE chunk, after
 * the chunk that carries finish_reason — the shape real providers use.
 */
final class AuditLateUsageStream
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function create(array $params): iterable
    {
        $this->calls[] = $params;
        yield new \LightAgent\Llm\ChatCompletionChunk(choices: [[
            'index' => 0,
            'delta' => ['content' => 'Hello '],
            'finish_reason' => null,
        ]]);
        yield new \LightAgent\Llm\ChatCompletionChunk(choices: [[
            'index' => 0,
            'delta' => ['content' => 'world'],
            'finish_reason' => null,
        ]]);
        yield new \LightAgent\Llm\ChatCompletionChunk(choices: [[
            'index' => 0,
            'delta' => [],
            'finish_reason' => 'stop',
        ]]);
        yield new \LightAgent\Llm\ChatCompletionChunk(choices: [], usage: [
            'prompt_tokens' => 11,
            'completion_tokens' => 7,
            'total_tokens' => 18,
        ]);
    }
}

/**
 * Streaming backend that carries a whole secret inside one chunk.
 */
final class AuditSecretStream
{
    public function create(array $params): iterable
    {
        yield new \LightAgent\Llm\ChatCompletionChunk(choices: [[
            'index' => 0,
            'delta' => ['content' => 'here you go: sk-abcdef123456'],
            'finish_reason' => null,
        ]]);
        yield new \LightAgent\Llm\ChatCompletionChunk(choices: [[
            'index' => 0,
            'delta' => [],
            'finish_reason' => 'stop',
        ]]);
    }
}

/**
 * Streaming backend that splits a secret across two chunks.
 */
final class AuditSplitSecretStream
{
    public function create(array $params): iterable
    {
        yield new \LightAgent\Llm\ChatCompletionChunk(choices: [[
            'index' => 0,
            'delta' => ['content' => 'here you go: sk-'],
            'finish_reason' => null,
        ]]);
        yield new \LightAgent\Llm\ChatCompletionChunk(choices: [[
            'index' => 0,
            'delta' => ['content' => 'abcdef123456'],
            'finish_reason' => null,
        ]]);
        yield new \LightAgent\Llm\ChatCompletionChunk(choices: [[
            'index' => 0,
            'delta' => [],
            'finish_reason' => 'stop',
        ]]);
    }
}

/**
 * Non-streaming backend returning a plain completion.
 */
final class AuditStaticCompletions
{
    public function __construct(private readonly string $content = 'plain answer')
    {
    }

    public function create(array $params): \LightAgent\Llm\ChatCompletion
    {
        return new \LightAgent\Llm\ChatCompletion(
            choices: [[
                'message' => new \LightAgent\Llm\ChatMessage(content: $this->content),
                'finish_reason' => 'stop',
            ]],
            usage: ['prompt_tokens' => 3, 'completion_tokens' => 4, 'total_tokens' => 9],
        );
    }
}

/**
 * Non-streaming backend that always asks for the same tool.
 */
final class AuditToolLoopCompletions
{
    public int $calls = 0;

    public function create(array $params): \LightAgent\Llm\ChatCompletion
    {
        $this->calls++;
        return new \LightAgent\Llm\ChatCompletion(
            choices: [[
                'message' => new \LightAgent\Llm\ChatMessage(
                    content: '',
                    toolCalls: [[
                        'id' => 'call_' . $this->calls,
                        'type' => 'function',
                        'function' => ['name' => 'ping', 'arguments' => '{}'],
                    ]],
                ),
                'finish_reason' => 'tool_calls',
            ]],
            usage: ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        );
    }
}
