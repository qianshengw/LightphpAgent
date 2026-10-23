<?php

declare(strict_types=1);

namespace LightAgent\Core;

use LightAgent\Support\Json;
use LightAgent\Async\AsyncRuntime;
use LightAgent\Async\CancellationToken;
use LightAgent\Errors\ErrorTaxonomy;
use LightAgent\Guardrails\GuardrailManager;
use LightAgent\Hooks\HookAction;
use LightAgent\Hooks\HookContext;
use LightAgent\Hooks\HookManager;
use LightAgent\Llm\ChatCompletion;
use LightAgent\Llm\ChatCompletionChunk;
use LightAgent\Llm\ChatMessage;
use LightAgent\Llm\OpenAiClient;
use LightAgent\Logging\LoggerManager;
use LightAgent\Protocol\MemoryCandidate;
use LightAgent\Protocol\MemoryPolicy;
use LightAgent\Protocol\MemoryPromotionDecision;
use LightAgent\Protocol\MemoryScope;
use LightAgent\Result\RunResult;
use LightAgent\Result\StreamEvent;
use LightAgent\Runtime\AgentRuntime;
use LightAgent\Runtime\BudgetExceeded;
use LightAgent\Session\ContextProjector;
use LightAgent\Skills\SkillManager;
use LightAgent\Tools\ToolDispatcher;
use LightAgent\Tools\ToolFunction;
use LightAgent\Tools\ToolRegistry;
use LightAgent\Tracing\TraceRecorder;
use LightAgent\Tracing\TraceUtils;
use LightAgent\Version;

/**
 * LightAgent core (port of core.py LightAgent).
 *
 * Non-streaming and streaming run loops, memory pipeline, guardrails,
 * hooks, tracing, handoff intent detection, and ToT are ported faithfully;
 * PHP-side simplifications are marked PORTING-DIFF.
 */
class LightAgent
{
    public const VERSION = Version::VERSION;

    public ToolRegistry $toolRegistry;
    public GuardrailManager $guardrails;
    public HookManager $hooks;
    public ToolDispatcher $toolDispatcher;
    public AgentRuntime $runtime;
    public MemoryPolicy $memoryPolicy;
    public TraceRecorder $traceRecorder;
    public \LightAgent\Capabilities\CapabilityRegistry $capabilityRegistry;
    public ?\LightAgent\Session\ContextCompactor $contextCompactor = null;

    public string $traceid = '';
    /** @var array<string, mixed> */
    public array $chatParams = [];
    public mixed $client = null;
    public mixed $totClient = null;
    public ?LoggerManager $logger = null;

    public string $name;
    public string $instructions;
    public ?string $role;
    public string $model;
    public ?string $apiKeyValue;
    public string $baseUrl;
    public ?string $provider;
    public bool $treeOfThought;
    public ?string $totModel;
    public bool $filterTools;
    public bool $selfLearning;
    public bool $debug;
    /**
     * Ask the provider for a usage frame at the end of a streaming response
     * (`stream_options.include_usage`). Without it the provider sends no
     * usage at all, so the token budget can never be charged for a streaming
     * run (T12).
     *
     * PORTING-DIFF: the Python original never sends this field, therefore its
     * streaming runs are never charged against the token budget. The PHP port
     * requests the usage frame so the budget accounting works; set
     * `stream_include_usage => false` for gateways that reject the field
     * (the client also retries once without it, see OpenAiClient).
     */
    public bool $streamIncludeUsage;

    /**
     * How many times a FAILED model request is retried before the run
     * gives up.
     *
     * `max_retry` bounds the TOOL loop; it never bounded the model request
     * itself, so a single transient 429/500 ended the whole run even though
     * the README promised "模型调用失败由 max_retry 重试". The two limits are
     * separate concerns and now have separate knobs.
     */
    public int $modelMaxRetries;

    /**
     * Base delay (seconds) for the exponential backoff between model
     * request retries. The n-th retry waits `backoff * 2^(n-1)`.
     */
    public float $modelRetryBackoff;

    protected mixed $memory;
    protected string $memoryNamespace;
    protected bool $autoDiscoverSkills;
    public ?SkillManager $skillManager = null;

    // Run state (mirrors Python instance attrs reset per run)
    protected string $currentRunId = '';
    protected string $currentUserId = 'default_user';
    /** @var array<string, mixed> */
    protected array $currentRunMetadata = [];
    protected ?CancellationToken $currentCancellationToken = null;
    protected ?string $parentTraceId = null;
    protected ?string $runGroupId = null;
    /** @var list<array<string, mixed>> */
    protected array $currentToolCalls = [];
    protected ?array $currentUsage = null;
    /** @var array<string, int> */
    protected array $usageTotals = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
    protected string $currentReasoningContent = '';
    protected float $runStartedAt = 0.0;
    protected int $modelRequestCount = 0;
    protected int $retryCount = 0;
    protected ?\LightAgent\Tracing\TraceEvent $pendingModelTraceEvent = null;
    protected float $pendingModelStartedAt = 0.0;
    protected int $memoryWriteCount = 0;
    /** @var array<string, bool> */
    protected array $memoryWriteFingerprints = [];
    /** @var list<MemoryCandidate> */
    protected array $memoryPromotionCandidates = [];
    protected ?string $budgetError = null;
    protected ?string $currentSessionId = null;
    protected ?string $currentTurnId = null;
    protected bool $sessionTurnFinished = true;
    protected ?string $sessionStoreError = null;
    protected ?\LightAgent\Tracing\TraceEvent $lastModelTraceEvent = null;
    protected ?string $runQuery = null;
    /**
     * Outcome of the current run, as decided by finishRun().
     *
     * The structured result used to infer failure from the CONTENT: an
     * error was only reported when the text started with the `[LA-` prefix.
     * `max_tool_iterations`, `max_retry` exhaustion and a human-approval
     * block all produced plain text, so they were handed back to the caller
     * as a run with `error === null` — indistinguishable from success.
     */
    protected int $consecutiveToolBlocks = 0;
    /**
     * How many consecutive BLOCKED tool calls are tolerated before the
     * tool loop is stopped.
     *
     * A blocked tool call is fed back to the model so it can adapt. But a
     * model that keeps asking for the same blocked tool (an approval that
     * never comes, a guardrail that always refuses) would otherwise burn
     * up to `max_tool_iterations` model round trips — and the user's
     * tokens — without ever making progress.
     */
    protected int $maxConsecutiveToolBlocks = 3;

    /**
     * Maximum number of tool EXECUTIONS in one run, or null for unlimited.
     *
     * `max_tool_iterations` bounds the ROUNDS of the tool loop. A model may
     * request several PARALLEL tool calls in a single round, so the number of
     * executions is rounds × calls-per-round and is not bounded by the
     * iteration cap alone. This option closes that gap; when it is null the
     * only remaining bound is the token budget.
     */
    public ?int $maxToolCalls;

    protected int $toolCallCount = 0;

    protected bool $lastRunSuccess = true;
    protected ?string $lastRunError = null;
    protected ?string $lastRunStage = null;
    /**
     * The narrowed permission ceiling of the CURRENT run.
     *
     * `SubagentManager` registers a child with a narrowed `PermissionSet`
     * and hands it over as a run option. Without a gate here the narrowing
     * was pure metadata — the child could still call every tool its own
     * context allowed.
     */
    protected ?\LightAgent\Capabilities\PermissionSet $currentPermissions = null;

    /**
     * @param array<string, mixed> $options keyword options matching the Python constructor:
     *   name, instructions, role, model, api_key, base_url, provider,
     *   memory, memory_policy, memory_namespace, tree_of_thought, tot_model,
     *   tot_api_key, tot_base_url, filter_tools, self_learning, tools,
     *   skills_directories, auto_discover_skills, input_guardrails,
     *   tool_guardrails, output_guardrails, hooks, session_store,
     *   budget_limits, debug, log_level, log_file
     */
    public function __construct(array $options = [])
    {
        $model = $options['model'] ?? 'gpt-4o-mini';
        $this->toolRegistry = new ToolRegistry();
        $this->guardrails = new GuardrailManager(
            inputGuardrails: $options['input_guardrails'] ?? [],
            toolGuardrails: $options['tool_guardrails'] ?? [],
            outputGuardrails: $options['output_guardrails'] ?? [],
        );
        $this->hooks = new HookManager($options['hooks'] ?? []);

        $provider = $options['provider'] ?? null;
        if ($provider !== null && $provider !== 'litellm') {
            throw new \InvalidArgumentException("provider must be None or 'litellm'");
        }
        if (!is_string($model) || $model === '') {
            $model = 'gpt-4o-mini';
        }
        $apiKey = $options['api_key'] ?? getenv('OPENAI_API_KEY') ?: null;
        if ($apiKey === null || $apiKey === false || $apiKey === '') {
            throw new \InvalidArgumentException(
                'The api_key client option must be set either by passing api_key to the client or by setting the OPENAI_API_KEY environment variable',
            );
        }
        $baseUrl = $options['base_url'] ?? (getenv('OPENAI_BASE_URL') ?: null) ?? 'https://api.openai.com/v1';

        $name = $options['name'] ?? null;
        if (!is_string($name) || $name === '') {
            $name = 'LightAgent' . random_int(10000000, 99999999);
        }
        $this->name = $name;
        $this->instructions = $options['instructions'] ?? 'You are a helpful agent.';
        $this->role = $options['role'] ?? null;
        $this->model = $model;
        $this->apiKeyValue = (string) $apiKey;
        $this->baseUrl = (string) $baseUrl;
        $this->provider = $provider;
        $this->memory = $options['memory'] ?? null;
        $memoryNamespace = $options['memory_namespace'] ?? null;
        $this->memoryNamespace = $memoryNamespace ?? '';
        $this->memoryPolicy = $options['memory_policy'] ?? new MemoryPolicy(namespace: $memoryNamespace);
        $this->treeOfThought = (bool) ($options['tree_of_thought'] ?? false);
        $this->totModel = $options['tot_model'] ?? null;
        $this->filterTools = (bool) ($options['filter_tools'] ?? true);
        $this->selfLearning = (bool) ($options['self_learning'] ?? false);
        $this->debug = (bool) ($options['debug'] ?? false);
        $this->streamIncludeUsage = (bool) ($options['stream_include_usage'] ?? true);
        $this->modelMaxRetries = max(0, (int) ($options['model_max_retries'] ?? 2));
        $this->modelRetryBackoff = max(0.0, (float) ($options['model_retry_backoff'] ?? 0.5));
        $maxToolCalls = $options['max_tool_calls'] ?? null;
        $this->maxToolCalls = $maxToolCalls === null ? null : max(1, (int) $maxToolCalls);
        $this->autoDiscoverSkills = (bool) ($options['auto_discover_skills'] ?? false);

        $this->client = new OpenAiClient(
            apiKey: $this->apiKeyValue,
            baseUrl: $this->baseUrl,
            caBundle: self::optionalString($options['ca_bundle'] ?? null),
            proxy: self::optionalString($options['proxy'] ?? null),
            extraHeaders: self::optionalHeaders($options['extra_headers'] ?? null),
        );
        if ($this->treeOfThought) {
            $totApiKey = $options['tot_api_key'] ?? $this->apiKeyValue;
            $totBaseUrl = $options['tot_base_url'] ?? $this->baseUrl;
            // The ToT client inherits the main client's transport settings
            // unless it is given its own (enterprise deployments usually want
            // the same CA bundle / proxy / gateway headers on both).
            $this->totClient = new OpenAiClient(
                apiKey: (string) $totApiKey,
                baseUrl: (string) $totBaseUrl,
                caBundle: self::optionalString($options['tot_ca_bundle'] ?? $options['ca_bundle'] ?? null),
                proxy: self::optionalString($options['tot_proxy'] ?? $options['proxy'] ?? null),
                extraHeaders: self::optionalHeaders($options['tot_extra_headers'] ?? $options['extra_headers'] ?? null),
            );
            if ($this->totModel === null) {
                $this->totModel = 'deepseek-r1';
            }
        }

        // The dispatcher holds the REGISTRY, not a copy of its arrays (T1):
        // a tool registered after this point — the built-in safe_expression
        // below, or any tool added later — is immediately dispatchable.
        $this->toolDispatcher = new ToolDispatcher($this->toolRegistry);
        $this->runtime = new AgentRuntime(
            sessionStore: $options['session_store'] ?? null,
            budget: new \LightAgent\Runtime\BudgetManager($options['budget_limits'] ?? null),
        );
        $this->traceRecorder = new TraceRecorder(enabled: false);

        // Register the built-in safe-expression tool.
        //
        // It is registered BY DEFAULT (so the documented "safe calculator"
        // works out of the box), but it is then sent to the provider on
        // EVERY request — which costs tokens on every single call and
        // dilutes the model's tool choice. A production deployment that
        // does not want that can switch it off:
        //
        //     new LightAgent([..., 'builtin_tools' => false])
        //
        // The legacy Python executors are NOT registered here: they have no
        // isolated execution route and are always refused by the dispatch
        // layer, so exposing them would be misleading.
        if ((bool) ($options['builtin_tools'] ?? true)) {
            $this->toolRegistry->registerTool(\LightAgent\BuiltinTools\SafeExpressionTool::create());
        }
        if ($this->debug) {
            $logDir = 'logs';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0777, true);
            }
            $logFile = $options['log_file'] ?? ($logDir . DIRECTORY_SEPARATOR . "{$this->name}.log");
            $this->logger = new LoggerManager(
                name: $this->name,
                debug: $this->debug,
                logLevel: (string) ($options['log_level'] ?? 'INFO'),
                logFile: $logFile,
            );
        }

        // Skill manager (auto-discovery wired below)
        $skillsDirectories = $options['skills_directories'] ?? ['skills'];
        $this->skillManager = new SkillManager($skillsDirectories, $this->logger);
        $autoDiscoverSkills = (bool) ($options['auto_discover_skills'] ?? false);
        if ($autoDiscoverSkills) {
            $discovered = $this->skillManager->discoverSkills();
            if ($this->debug && $discovered !== []) {
                $this->log('INFO', 'skills_discovered', [
                    'count' => count($discovered),
                    'skills' => array_map(static fn (Skill $s): string => $s->name, $discovered),
                ]);
            }
            if ($this->skillManager->skills !== []) {
                $skillTools = \LightAgent\Skills\create_skill_tools($this->skillManager);
                foreach ($skillTools as $toolFunc) {
                    $this->toolRegistry->registerTool($toolFunc);
                }
                if ($this->debug) {
                    $this->log('INFO', 'skill_tools_loaded', ['count' => count($skillTools)]);
                }
            }
        }

        // Capability registry + context compactor (core.py constructor tail)
        $registry = $options['capability_registry'] ?? null;
        $policyEngine = $options['policy_engine'] ?? null;
        if ($registry instanceof \LightAgent\Capabilities\CapabilityRegistry) {
            $this->capabilityRegistry = $registry;
            if ($policyEngine instanceof \LightAgent\Capabilities\PolicyEngine) {
                $this->capabilityRegistry->policyEngine = $policyEngine;
            }
        } else {
            $this->capabilityRegistry = new \LightAgent\Capabilities\CapabilityRegistry(
                policyEngine: $policyEngine instanceof \LightAgent\Capabilities\PolicyEngine ? $policyEngine : null,
            );
        }
        $contextBudget = $options['context_budget'] ?? null;
        if ($contextBudget instanceof \LightAgent\Session\ContextBudget) {
            $this->contextBudget = $contextBudget;
        }
        $contextCompactor = $options['context_compactor'] ?? null;
        $this->contextCompactor = $contextCompactor instanceof \LightAgent\Session\ContextCompactor
            ? $contextCompactor
            : new \LightAgent\Session\ContextCompactor();
        $this->registerBuiltinProviders();
    }

    public ?\LightAgent\Session\ContextBudget $contextBudget = null;

    /**
     * Normalise an optional string option (empty / non-string -> null).
     *
     * @param mixed $value
     */
    private static function optionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Normalise an optional header map.
     *
     * Accepts either the associative form (['api-key' => 'secret']) or the
     * raw-header form (['api-key: secret']); both are folded into a single
     * associative map so the client always emits well-formed header lines.
     *
     * @param mixed $value
     * @return array<string, string>
     */
    private static function optionalHeaders(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $headers = [];
        foreach ($value as $name => $headerValue) {
            if (is_string($headerValue) && is_int($name)) {
                // Raw header line: "Name: value"
                $pos = strpos($headerValue, ':');
                if ($pos === false) {
                    continue;
                }
                $name = trim(substr($headerValue, 0, $pos));
                $headerValue = trim(substr($headerValue, $pos + 1));
            }
            if (!is_string($name) || trim($name) === '' || !is_string($headerValue)) {
                continue;
            }
            $headers[trim($name)] = trim($headerValue);
        }
        return $headers;
    }

    /**
     * Register the built-in "tools" and (when memory is set) "memory" providers
     * (core.py _register_builtin_providers).
     */
    protected function registerBuiltinProviders(): void
    {
        $existing = [];
        foreach ($this->capabilityRegistry->list() as $item) {
            $existing[$item['name']] = true;
        }
        if (!isset($existing['tools'])) {
            $this->capabilityRegistry->register(
                new \LightAgent\Capabilities\ToolProviderAdapter($this->toolRegistry),
                \LightAgent\Capabilities\CapabilityScope::RUNTIME,
            );
        }
        if ($this->memory !== null && !isset($existing['memory'])) {
            $this->capabilityRegistry->register(
                new \LightAgent\Capabilities\MemoryProviderAdapter($this->memory),
                \LightAgent\Capabilities\CapabilityScope::RUNTIME,
            );
        }
    }

    // ------------------------------------------------------------------
    // Public helpers
    // ------------------------------------------------------------------

    public function log(string $level, string $action, mixed $data): void
    {
        if (!$this->debug || $this->logger === null) {
            return;
        }
        $this->logger->log($level, $action, $data);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getHistory(): array
    {
        return Json::decode($this->chatParams['messages'] ?? []) ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTools(): array
    {
        return $this->toolRegistry->getTools();
    }

    /**
     * @return list<array<string, string>>
     */
    public function validateTools(): array
    {
        return $this->toolRegistry->validateTools();
    }

    public function registerTool(ToolFunction $tool): void
    {
        $this->toolRegistry->registerTool($tool);
    }

    // ------------------------------------------------------------------
    // Session management convenience methods (core.py get_session family)
    // ------------------------------------------------------------------

    /**
     * Return a detached copy of the current or requested Session.
     */
    public function getSession(?string $sessionId = null): ?\LightAgent\Session\Session
    {
        $resolved = $sessionId ?? $this->currentSessionId;
        return $resolved !== null ? $this->runtime->sessionStore->get($resolved) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function exportSession(?string $sessionId = null): array
    {
        $session = $this->getSession($sessionId);
        if ($session === null) {
            throw new \RuntimeException($sessionId ?? 'current session');
        }
        return $session->toDict();
    }

    /**
     * @return array<string, mixed>
     */
    public function replaySession(?string $sessionId = null): array
    {
        $session = $this->getSession($sessionId);
        if ($session === null) {
            throw new \RuntimeException($sessionId ?? 'current session');
        }
        return $session->replay()->toDict();
    }

    /**
     * @param array<string, mixed>|null $metadata
     * @return array<string, mixed>
     */
    public function checkpointSession(?string $label = null, ?array $metadata = null): array
    {
        return $this->runtime->checkpoint($label, $metadata)->toDict();
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function forkSession(?int $throughSequence = null, ?array $metadata = null): \LightAgent\Session\Session
    {
        return $this->runtime->fork($throughSequence, $metadata);
    }

    /**
     * Compact a session's context and persist the compaction event.
     *
     * @return array<string, mixed>
     */
    public function compactSession(int $maxMessages = 20, ?string $sessionId = null): array
    {
        $session = $this->getSession($sessionId);
        if ($session === null) {
            throw new \RuntimeException($sessionId ?? 'current session');
        }
        $result = (new \LightAgent\Session\ContextCompactor(
            summarizer: $this->contextCompactor?->summarizer,
            maxInlineToolChars: $this->contextCompactor?->maxInlineToolChars ?? 12000,
        ))->compact((new \LightAgent\Session\ContextProjector())->messages($session), $maxMessages);
        $runtimeSession = $this->runtime->session;
        $target = $runtimeSession !== null && $runtimeSession->sessionId === $session->sessionId
            ? $runtimeSession
            : $session;
        $target->append('context.compacted', [
            'messages' => $result->messages,
            'removed_count' => $result->removedCount,
            'summary' => $result->summary,
            'spilled' => $result->spilled,
        ]);
        $this->runtime->sessionStore->save($target);
        return [
            'messages' => $result->messages,
            'removed_count' => $result->removedCount,
            'summary' => $result->summary,
            'spilled' => $result->spilled,
        ];
    }

    // ------------------------------------------------------------------
    // Tool loading convenience methods (core.py get_tool/load_tools)
    // ------------------------------------------------------------------

    /**
     * Return a loaded tool function by name.
     */
    public function getTool(string $toolName): ToolFunction
    {
        $tool = $this->toolRegistry->functionMappings[$toolName] ?? null;
        if ($tool === null) {
            throw new \InvalidArgumentException("Tool `{$toolName}` is not loaded.");
        }
        return $tool;
    }

    /**
     * Load/register tools into the registry (core.py load_tools).
     *
     * @param list<ToolFunction> $tools
     */
    public function loadTools(array $tools): void
    {
        foreach ($tools as $tool) {
            if ($tool instanceof ToolFunction) {
                if ($this->toolRegistry->registerTool($tool)) {
                    $name = $tool->toolInfo['tool_name'] ?? 'unknown';
                    $this->log('DEBUG', 'register_tool', ['tool' => $name, 'status' => 'success']);
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Trace export convenience methods (core.py summarize_trace family)
    // ------------------------------------------------------------------

    /**
     * Return aggregate metrics for the most recent trace.
     *
     * @param array<string, float>|null $pricing
     */
    public function summarizeTrace(?array $pricing = null): \LightAgent\Tracing\TraceSummary
    {
        return \LightAgent\Tracing\TraceUtils::summarize(
            $this->exportTrace(),
            usage: $this->currentUsage,
            pricing: $pricing,
        );
    }

    /**
     * Send the most recent trace to a callable or exporter object.
     *
     * @param array<string, float>|null $pricing
     * @param array<string, mixed>|null $metadata
     */
    public function exportTraceTo(callable|object $exporter, ?array $pricing = null, ?array $metadata = null): mixed
    {
        return \LightAgent\Tracing\TraceUtils::export(
            $this->exportTrace(),
            $exporter,
            usage: $this->currentUsage,
            pricing: $pricing,
            metadata: $metadata,
        );
    }

    // ------------------------------------------------------------------
    // Capability invocation (core.py invoke_capability)
    // ------------------------------------------------------------------

    /**
     * Invoke one registered capability through Policy and audit.
     *
     * @param array<string, mixed>|null $arguments
     */
    public function invokeCapability(string $capability, ?array $arguments = null): mixed
    {
        return $this->capabilityRegistry->invoke(
            $capability,
            $arguments,
            context: $this->runtime->context,
        );
    }

    // ------------------------------------------------------------------
    // MCP setup (core.py setup_mcp)
    // ------------------------------------------------------------------

    public ?\LightAgent\Mcp\MCPClientManager $mcpClient = null;
    /** @var array<string, mixed>|null */
    public ?array $mcpSetting = null;

    /**
     * Initialize the MCP module and register MCP tools into the registry
     * (core.py setup_mcp). Requires a running event loop context
     * (AsyncRuntime::run) because stdio transports spawn fibers.
     *
     * @param array<string, mixed>|null $mcpSetting mcpServers config
     */
    public function setupMcp(?array $mcpSetting = null): bool
    {
        if ($mcpSetting !== null) {
            $this->mcpSetting = $mcpSetting;
        }
        if ($this->mcpSetting !== null && $this->mcpClient === null) {
            $this->mcpClient = new \LightAgent\Mcp\MCPClientManager($this->mcpSetting, $this->toolRegistry);
            $registered = \LightAgent\Async\AsyncRuntime::run(
                fn (): bool => $this->mcpClient->registerMcpTool(),
            );
            // The log used to claim success unconditionally, so a failed
            // registration looked like a successful one in the logs.
            $this->log(
                $registered ? 'INFO' : 'ERROR',
                'setup_mcp',
                $registered
                    ? 'MCP 模块初始化成功'
                    : 'MCP 模块初始化失败：未注册任何 MCP 工具，请检查服务器配置与诊断信息',
            );
            return $registered;
        }
        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listMemoryCandidates(bool $includeData = true): array
    {
        return array_map(
            static fn (MemoryCandidate $c): array => $c->toDict(includeData: $includeData),
            $this->memoryPromotionCandidates,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function exportTrace(): array
    {
        return $this->traceRecorder->toList();
    }

    /**
     * Promote, reject, rewrite, or keep a pending memory candidate.
     */
    public function promoteMemoryCandidate(string $candidateId, mixed $decision = null): bool
    {
        $candidate = $this->findMemoryCandidate($candidateId);
        if ($candidate === null) {
            throw new \InvalidArgumentException("Memory candidate `{$candidateId}` was not found.");
        }
        if ($candidate->status === 'promoted') {
            $this->recordTrace('memory_promotion_idempotent', array_merge(
                $this->memoryCandidateTrace($candidate),
                ['manual' => true],
            ));
            return true;
        }

        if ($decision === null) {
            $promotionDecision = MemoryPromotionDecision::approve($candidate->data);
        } elseif ($decision instanceof MemoryPromotionDecision) {
            $promotionDecision = $decision;
        } else {
            $promotionDecision = $this->coercePromotionDecisionPublic($decision, $candidate->data);
        }

        $context = $this->memoryPromotionContext($candidate);
        return $this->applyMemoryPromotionDecision($candidate, $promotionDecision, $context, manual: true);
    }

    // ------------------------------------------------------------------
    // run()
    // ------------------------------------------------------------------

    /**
     * Run the agent. Returns string | RunResult | Generator (streaming).
     *
     * @param array<string, mixed> $options run keyword options:
     *   tools, light_swarm, stream, max_retry, max_tool_iterations, max_tool_calls,
     *   user_id, history, metadata, use_skills, result_format, trace,
     *   parent_trace_id, run_group_id, approval_id, session_id, cancellation_token,
     *   idempotency_key
     * @return string|RunResult|\Generator|list<StreamEvent>
     */
    public function run(string $query, array $options = []): mixed
    {
        $stream = (bool) ($options['stream'] ?? false);
        $resultFormat = (string) ($options['result_format'] ?? 'str');
        $trace = (bool) ($options['trace'] ?? false);
        $maxRetry = (int) ($options['max_retry'] ?? 10);
        $maxToolIterations = $options['max_tool_iterations'] ?? null;
        // `max_tool_calls` is a RUN option, exactly like
        // `max_tool_iterations`. It used to be readable ONLY as a constructor
        // option, so passing it to run() was SILENTLY IGNORED — and since a
        // model may request many parallel calls in one round, that left the
        // execution count unbounded (observed live: 10 calls x 2 rounds = 20
        // executions with `max_tool_calls => 3` passed to run()).
        // The constructor value stays the DEFAULT; the run option wins.
        $maxToolCalls = $options['max_tool_calls'] ?? null;

        if (!in_array($resultFormat, ['str', 'object', 'dict', 'event'], true)) {
            throw new \InvalidArgumentException('result_format must be one of: str, object, dict, event');
        }
        if (!$stream && $resultFormat === 'event') {
            throw new \InvalidArgumentException("result_format='event' requires stream=True");
        }
        if ($stream && in_array($resultFormat, ['object', 'dict'], true)) {
            throw new \InvalidArgumentException("stream=True supports result_format='str' or result_format='event'");
        }
        if ($maxToolIterations !== null && (int) $maxToolIterations < 1) {
            throw new \InvalidArgumentException('max_tool_iterations must be at least 1');
        }
        if ($maxToolCalls !== null && (int) $maxToolCalls < 1) {
            throw new \InvalidArgumentException('max_tool_calls must be at least 1');
        }
        $effectiveMaxToolIterations = $maxToolIterations !== null ? (int) $maxToolIterations : $maxRetry;
        $effectiveMaxToolCalls = $maxToolCalls !== null ? (int) $maxToolCalls : $this->maxToolCalls;

        // Per-run state reset (core.py lines 532-566)
        $traceid = bin2hex(random_bytes(16));
        $this->traceid = $traceid;
        $this->currentRunId = bin2hex(random_bytes(16));
        $userId = (string) ($options['user_id'] ?? 'default_user');
        $this->currentUserId = $userId;
        $metadata = $options['metadata'] ?? [];
        $this->currentRunMetadata = is_array($metadata) ? $metadata : [];
        $approvalId = $options['approval_id'] ?? null;
        if ($approvalId !== null) {
            $this->currentRunMetadata['approval_id'] = (string) $approvalId;
        }
        $this->currentCancellationToken = $options['cancellation_token'] ?? null;
        $this->parentTraceId = $options['parent_trace_id'] ?? null;
        $this->runGroupId = $options['run_group_id'] ?? null;
        $this->traceRecorder = new TraceRecorder(
            enabled: $trace,
            traceId: $traceid,
            parentTraceId: $this->parentTraceId,
            runGroupId: $this->runGroupId,
        );
        $this->currentToolCalls = [];
        $this->currentUsage = null;
        $this->usageTotals = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
        $this->currentReasoningContent = '';
        $this->runStartedAt = microtime(true);
        $this->modelRequestCount = 0;
        $this->retryCount = 0;
        $this->pendingModelTraceEvent = null;
        $this->pendingModelStartedAt = 0.0;
        $this->memoryWriteCount = 0;
        $this->memoryWriteFingerprints = [];
        $this->memoryPromotionCandidates = [];
        $this->budgetError = null;
        $this->runQuery = $query;
        // The run outcome is per-run state: a previous failure must never
        // leak into the next run's structured result.
        $this->lastRunSuccess = true;
        $this->lastRunError = null;
        $this->lastRunStage = null;
        $this->consecutiveToolBlocks = 0;
        $this->toolCallCount = 0;
        // A subagent is registered with a narrowed permission set; the
        // narrowing becomes effective here, at the only place where a tool
        // actually runs.
        $this->currentPermissions = ($options['permissions'] ?? null) instanceof \LightAgent\Capabilities\PermissionSet
            ? $options['permissions']
            : null;

        $projectedHistory = $this->beginSessionRun(
            sessionId: $options['session_id'] ?? null,
            query: $query,
            userId: $userId,
            metadata: $metadata,
            stream: $stream,
        );
        if ($this->debug && $this->logger !== null) {
            $this->logger->setTraceid($traceid);
        }
        $this->log('INFO', 'run_start', ['query' => $query, 'user_id' => $userId, 'stream' => $stream]);
        $this->recordTrace('run_start', [
            'query' => $query,
            'user_id' => $userId,
            'stream' => $stream,
            'result_format' => $resultFormat,
            'run_id' => $this->currentRunId,
            'idempotency_key' => $options['idempotency_key'] ?? null,
        ]);

        $beforeRun = $this->runHooks('before_run', [
            'query' => $query,
            'tools' => $options['tools'] ?? null,
            'stream' => $stream,
            'result_format' => $resultFormat,
            'metadata' => is_array($metadata) ? $metadata : [],
        ]);
        if ($beforeRun->action === HookAction::BLOCK) {
            $errorMsg = $this->formatHookError('before_run', $beforeRun->reason, self::hookErrorCode($beforeRun->metadata));
            $this->finishRun(success: false, error: $errorMsg, stage: 'before_run');
            if ($stream) {
                $streamResult = self::errorStream($errorMsg);
                return $resultFormat === 'event' ? $this->streamAsEvents($streamResult, $traceid) : $streamResult;
            }
            return $this->formatRunResult($errorMsg, $resultFormat, $traceid, $errorMsg);
        }
        $query = (string) ($beforeRun->payload['query'] ?? $query);

        $inputDecision = $this->guardrails->checkInput($query, [
            'agent_name' => $this->name,
            'user_id' => $userId,
            'trace_id' => $traceid,
        ]);
        if (!$inputDecision->allowed) {
            $errorMsg = $this->formatGuardrailError('input', $inputDecision->reason);
            $this->recordPolicyAdapterEvent('before_run', 'input_guardrails', 'block', reason: $inputDecision->reason);
            $this->recordTrace('guardrail_block', ['stage' => 'input', 'reason' => $inputDecision->reason]);
            $this->finishRun(success: false, error: $errorMsg, stage: 'input_guardrail');
            if ($stream) {
                $streamResult = self::errorStream($errorMsg);
                return $resultFormat === 'event' ? $this->streamAsEvents($streamResult, $traceid) : $streamResult;
            }
            return $this->formatRunResult($errorMsg, $resultFormat, $traceid, $errorMsg);
        }
        if ($inputDecision->value !== null) {
            if ($inputDecision->value !== $query) {
                $this->recordPolicyAdapterEvent('before_run', 'input_guardrails', 'replace');
            }
            $query = (string) $inputDecision->value;
        }

        // History init (PORTING-DIFF: session projection only when session_id provided)
        $history = $options['history'] ?? null;
        if ($history === null && ($options['session_id'] ?? null) !== null) {
            $history = $projectedHistory;
        }
        $history = $history ?? [];

        $runtimeTools = [];
        $toolsOpt = $options['tools'] ?? null;
        if ($toolsOpt) {
            $runtimeTools = $this->processRuntimeTools($toolsOpt);
        }

        // Handoff (light_swarm)
        $lightSwarm = $options['light_swarm'] ?? null;
        if ($lightSwarm instanceof LightSwarm) {
            $handoffRunOptions = [
                'user_id' => $userId,
                'metadata' => $metadata,
                'trace' => $trace,
                'result_format' => $resultFormat,
                'max_retry' => $maxRetry,
                'max_tool_iterations' => $maxToolIterations,
                // Preserve the handoff chain across run() boundaries (C3).
                '_handoff_path' => $options['_handoff_path'] ?? [],
                'max_handoff_depth' => $options['max_handoff_depth'] ?? 3,
            ];
            $result = $this->handleTaskTransfer($query, $lightSwarm, $stream, $handoffRunOptions);
            if ($result !== null) {
                return $result;
            }
        }

        // System prompt
        $now = new \DateTimeImmutable('now');
        $systemPrompt = "##代理名称：{$this->name}\n"
            . "##代理指令：{$this->instructions}\n"
            . '##身份：' . ($this->role ?? '') . "\n"
            . "请一步一步思考来完成用户的要求。尽可能完成用户的回答，如果有补充信息，请参考补充信息来调用工具，直到获取所有满足用户的提问所需的答案。\n"
            . '今日的日期: ' . $now->format('Y-m-d') . ' 当前时间: ' . $now->format('H:i:s');

        // Memory context
        $query = $this->addMemoryContext($query, $userId);

        // ToT / runtime tools
        $activeTools = [];
        if ($this->treeOfThought) {
            [$totResponse, $activeTools] = $this->runThought($query, $runtimeTools);
            $systemPrompt .= "\n##以下是问题的补充说明\n{$totResponse}";
            $this->log('DEBUG', 'tree_of_thought', ['response' => $totResponse, 'active_tools' => $activeTools]);
        } elseif ($runtimeTools !== []) {
            $activeTools = $runtimeTools;
            $this->log('DEBUG', 'use_runtime_tools', ['runtime_tools' => $runtimeTools]);
        }

        // Build chat params
        $this->chatParams = [
            'model' => $this->model,
            'messages' => array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                $history,
                [['role' => 'user', 'content' => $query]],
            ),
            'stream' => $stream,
        ];
        if ($stream && $this->streamIncludeUsage) {
            // Ask the provider to append a usage frame to the stream. The
            // frame carries the token counts of the whole response, and it
            // is the only place a streaming run can learn what it cost
            // (T12: the token budget was never charged on streams).
            //
            // When the endpoint has already rejected the field once, the
            // client remembers that (`streamOptionsUnsupported`). Sending
            // it again would pay a wasted round trip AND a provider-side
            // 400 on every single streamed turn.
            $streamOptionsUnsupported = $this->client instanceof OpenAiClient
                && $this->client->streamOptionsUnsupported;
            if (!$streamOptionsUnsupported) {
                $this->chatParams['stream_options'] = ['include_usage' => true];
            }
        }

        $finalTools = [];
        if ($activeTools !== []) {
            $finalTools = $activeTools;
        } elseif ($runtimeTools !== []) {
            $finalTools = $runtimeTools;
        } else {
            $finalTools = $this->toolRegistry->getTools();
        }
        if ($finalTools !== []) {
            $this->chatParams['tools'] = $finalTools;
            $this->chatParams['tool_choice'] = 'auto';
            $this->log('DEBUG', 'final_tools_selected', ['tools' => array_map(
                static fn (array $t): string => (string) ($t['function']['name'] ?? json_encode($t)),
                $finalTools,
            )]);
        }

        $this->log('DEBUG', 'first_request_params', ['params' => $this->chatParams]);
        $hookError = $this->prepareModelRequest();
        if ($hookError !== null) {
            $this->finishRun(success: false, error: $hookError, stage: 'before_model_request');
            if ($stream) {
                $streamResult = self::errorStream($hookError);
                return $resultFormat === 'event' ? $this->streamAsEvents($streamResult, $traceid) : $streamResult;
            }
            return $this->formatRunResult($hookError, $resultFormat, $traceid, $hookError);
        }

        try {
            $response = $this->requestModel($this->chatParams);
        } catch (\Throwable $e) {
            $this->completeModelRequest(error: $e);
            $errorMsg = ErrorTaxonomy::formatException($e, 'create chat completion');
            $this->log('ERROR', 'model_request_failed', ['error' => $errorMsg]);
            $this->recordTrace('error', ['stage' => 'model_request', 'error' => $errorMsg]);
            $this->finishRun(success: false, error: $errorMsg, stage: 'model_request');
            if ($stream) {
                $streamResult = self::errorStream($errorMsg);
                return $resultFormat === 'event' ? $this->streamAsEvents($streamResult, $traceid) : $streamResult;
            }
            return $this->formatRunResult($errorMsg, $resultFormat, $traceid, $errorMsg);
        }
        $this->completeModelRequest(response: $response);

        // Core run logic
        if ($stream) {
            $streamResult = $this->runStreamLogic($response, $maxRetry, $effectiveMaxToolIterations, $resultFormat, $traceid, $effectiveMaxToolCalls);
            // Honour result_format='event' on the success path as well. The
            // error paths already wrapped their streams in StreamEvent; doing
            // it here keeps the contract consistent for every outcome.
            return $resultFormat === 'event' ? $this->streamAsEvents($streamResult, $traceid) : $streamResult;
        }

        $result = $this->runNonStreamLogic($response, $maxRetry, $effectiveMaxToolIterations, $effectiveMaxToolCalls);
        return $this->formatRunResult($result, $resultFormat, $traceid);
    }

    /**
     * Run without blocking the caller's event loop
     * (core.py arun: asyncio.to_thread(self.run, ...) -> PHP inline, identical
     * result semantics; see AsyncRuntime::toThread docblock for the
     * single-threaded difference).
     *
     * @param array<string, mixed> $options
     * @return string|RunResult|array<string, mixed>
     */
    public function arun(string $query, array $options = []): mixed
    {
        return AsyncRuntime::toThread(fn (): mixed => $this->run($query, $options));
    }

    /**
     * Expose the streaming generator as a cancellable async iterator
     * (core.py astream). Returns a Generator of stream chunks.
     *
     * @param array<string, mixed> $options
     * @return \Generator<string|array<string, mixed>>
     */
    public function astream(string $query, array $options = []): \Generator
    {
        $options['stream'] = true;
        $streamResult = $this->run($query, $options);
        if ($streamResult instanceof \Generator) {
            yield from $streamResult;
        }
    }

    // ------------------------------------------------------------------
    // Non-streaming logic (core.py _run_non_stream_logic)
    // ------------------------------------------------------------------

    protected function runNonStreamLogic(
        ChatCompletion $response,
        int $maxRetry,
        int $maxToolIterations,
        ?int $maxToolCalls = null,
    ): ?string {
        $toolIterations = 0;
        for ($attempt = 0; $attempt < $maxRetry; $attempt++) {
            $choice = $response->choices[0] ?? null;
            $message = $choice['message'] ?? null;
            // Empty provider response (N15): a degraded upstream must not be
            // recorded as a successful run with empty content.
            if ($choice === null || $message === null) {
                $errorMsg = ErrorTaxonomy::formatCode('LA-EMPTY-RESPONSE', 'provider returned no choices/message');
                $this->log('ERROR', 'empty_model_response', ['error' => $errorMsg]);
                $this->recordTrace('error', ['stage' => 'empty_response', 'error' => $errorMsg]);
                $this->finishRun(success: false, error: $errorMsg, stage: 'empty_response');
                return $errorMsg;
            }
            if ($message !== null && $message->toolCalls !== null && $message->toolCalls !== []) {
                /** @var list<string> $toolResponses */
                $toolResponses = [];
                $output = '';
                $functionCallName = '';
                $toolCalls = $message->toolCalls;

                // Append assistant message to history
                $this->chatParams['messages'][] = $this->buildAssistantToolCallMessage(
                    $message->content ?? '',
                    $message->reasoningContent,
                    $toolCalls,
                );

                foreach ($toolCalls as $toolCall) {
                    $fn = $toolCall['function'] ?? [];
                    $toolName = (string) ($fn['name'] ?? '');
                    $arguments = (string) ($fn['arguments'] ?? '');
                    $toolCallId = (string) ($toolCall['id'] ?? '');

                    // Total-execution cap. `max_tool_iterations` bounds the
                    // ROUNDS; a model may request several parallel calls in
                    // one round, so the number of executions needs its own
                    // bound when the caller wants one.
                    if ($maxToolCalls !== null && $this->toolCallCount >= $maxToolCalls) {
                        $errorMsg = ErrorTaxonomy::formatCode(
                            'LA-TOOL',
                            "max tool calls ({$maxToolCalls}) reached",
                        );
                        $this->recordTrace('error', [
                            'stage' => 'max_tool_calls',
                            'error' => $errorMsg,
                            'max_tool_calls' => $maxToolCalls,
                        ]);
                        $this->finishRun(success: false, error: $errorMsg, stage: 'max_tool_calls');
                        return $errorMsg;
                    }
                    $this->toolCallCount++;

                    $traceToolCall = [
                        'id' => $toolCall['id'] ?? null,
                        'name' => $toolName,
                        'arguments' => $arguments,
                    ];
                    $this->currentToolCalls[] = $traceToolCall;
                    $this->recordTrace('tool_call', $traceToolCall);
                    $this->log('DEBUG', 'non_stream function_call', ['function_call' => $arguments]);

                    // Parse args
                    $parsed = json_decode($arguments, true);
                    if (!is_array($parsed) && $arguments !== '') {
                        // Try the lenient parser used in streaming path
                        try {
                            $parsed = $this->parseToolArguments($arguments);
                        } catch (\JsonException) {
                            $parsed = null;
                        }
                    }
                    if (!is_array($parsed)) {
                        $singleToolResponse = ErrorTaxonomy::formatCode('LA-JSON', "invalid JSON; arguments: {$arguments}");
                        $this->recordTrace('error', ['stage' => 'tool_arguments', 'tool' => $toolName, 'error' => $singleToolResponse]);
                        $this->notifyError(stage: 'tool_arguments', error: $singleToolResponse, tool: $toolName);
                        $this->chatParams['messages'][] = [
                            'role' => 'tool',
                            'tool_call_id' => $toolCallId,
                            'content' => $singleToolResponse,
                        ];
                        $toolResponses[] = $singleToolResponse;
                        continue;
                    }

                    [$parsed, $guardrailError] = $this->prepareToolCall($toolName, $parsed);
                    if ($guardrailError !== null) {
                        $blockStage = self::toolBlockStage($guardrailError);
                        $this->recordTrace('error', ['stage' => $blockStage, 'tool' => $toolName, 'error' => $guardrailError]);
                        $this->notifyError(stage: $blockStage, error: $guardrailError, tool: $toolName);
                        $this->chatParams['messages'][] = [
                            'role' => 'tool',
                            'tool_call_id' => $toolCallId,
                            'content' => $guardrailError,
                        ];
                        $toolResponses[] = $guardrailError;
                        $blockLoopError = $this->registerToolBlock($toolName);
                        if ($blockLoopError !== null) {
                            $this->finishRun(success: false, error: $blockLoopError, stage: 'tool_blocked_loop');
                            return $blockLoopError;
                        }
                        continue;
                    }

                    $toolStartedAt = microtime(true);
                    $toolResponse = $this->toolDispatcher->dispatch($toolName, $parsed);
                    $this->registerToolSuccess();

                    $combinedResponse = '';
                    $singleToolResponse = '';

                    if ($toolResponse instanceof \Generator) {
                        foreach ($toolResponse as $chunk) {
                            $combinedResponse .= is_string($chunk) ? $chunk : Json::encodeOrError($chunk, JSON_UNESCAPED_UNICODE);
                        }
                        // Parse JSON if possible (Chinese-safe)
                        $decoded = json_decode($combinedResponse, true);
                        if (is_array($decoded)) {
                            $combinedResponse = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                        }
                        $singleToolResponse = $combinedResponse;
                    } else {
                        $combinedResponse = $toolResponse;
                        if (is_string($combinedResponse)) {
                            $decoded = json_decode($combinedResponse, true);
                            if (is_array($decoded)) {
                                $combinedResponse = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                            }
                        }
                        $singleToolResponse = (string) $combinedResponse;
                    }

                    $this->log('INFO', 'non_stream single_tool_response', ['single_tool_response' => $singleToolResponse]);
                    $singleToolResponse = (string) $this->applyAfterToolResult($toolName, $singleToolResponse);
                    $toolResultTrace = [
                        'id' => $toolCallId,
                        'name' => $toolName,
                        'output' => $singleToolResponse,
                        'latency_ms' => round((microtime(true) - $toolStartedAt) * 1000, 3),
                    ];
                    // Surface failures explicitly (M28): an [LA-… prefixed
                    // output IS the error payload for this tool call.
                    if (str_starts_with($singleToolResponse, '[LA-')) {
                        $toolResultTrace['error'] = $singleToolResponse;
                    }
                    $this->recordTrace('tool_result', $toolResultTrace);

                    $this->chatParams['messages'][] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCallId,
                        'content' => (string) $singleToolResponse,
                    ];
                    $toolResponses[] = $singleToolResponse;
                    $functionCallName = $toolName;
                }

                $this->log('DEBUG', 'non_stream tool_responses', ['tool_responses' => $toolResponses]);

                if ($functionCallName === 'finish') {
                    $this->finishRun(success: true, stage: 'finish_tool', extra: ['finish_tool' => true]);
                    return null;
                }

                // The streaming path has always bounded the tool loop with
                // max_tool_iterations; the non-streaming path did not, so the
                // only upper bound was max_retry (T12). Count the rounds and
                // stop at the same limit. The default
                // ($effectiveMaxToolIterations === $maxRetry) keeps the
                // previous behaviour exactly.
                $toolIterations++;
                if ($toolIterations >= $maxToolIterations) {
                    $errorMsg = "Max tool iterations({$maxToolIterations}) reached.";
                    $this->log('ERROR', 'max_tool_iterations_reached', ['message' => $errorMsg]);
                    $this->recordTrace('error', [
                        'stage' => 'max_tool_iterations',
                        'error' => $errorMsg,
                        'max_tool_iterations' => $maxToolIterations,
                    ]);
                    $this->finishRun(
                        success: false,
                        error: 'max_tool_iterations_reached',
                        stage: 'max_tool_iterations',
                        extra: [
                            'message' => $errorMsg,
                            'max_tool_iterations' => $maxToolIterations,
                        ],
                    );
                    return $errorMsg;
                }

                $hookError = $this->prepareModelRequest();
                if ($hookError !== null) {
                    $this->finishRun(success: false, error: $hookError, stage: 'before_model_request');
                    return $hookError;
                }

                try {
                    $response = $this->requestModel($this->chatParams);
                } catch (\Throwable $e) {
                    $this->completeModelRequest(error: $e);
                    $errorMsg = ErrorTaxonomy::formatException($e, 'continue chat completion');
                    $this->log('ERROR', 'model_request_failed', ['error' => $errorMsg]);
                    $this->recordTrace('error', ['stage' => 'model_request', 'error' => $errorMsg]);
                    $this->finishRun(success: false, error: $errorMsg, stage: 'model_request');
                    return $errorMsg;
                }
                $this->completeModelRequest(response: $response);
                continue;
            }

            // Final reply
            $reply = $message->content ?? '';
            // Keep the provider's reasoning trace OUT of the answer but
            // available to the caller as a separate field.
            $this->currentReasoningContent = (string) ($message->reasoningContent ?? '');
            $reply = $this->applyOutputGuardrails($reply);
            $this->log('INFO', 'non_stream final_reply', ['reply' => $reply]);
            $this->recordTrace('model_response', ['content' => $reply]);
            $blockedStage = self::blockedOutputStage($reply);
            if ($blockedStage !== null) {
                $this->recordTrace('error', ['stage' => $blockedStage, 'error' => $reply]);
                $this->finishRun(success: false, content: $reply, error: $reply, stage: $blockedStage);
                return $reply;
            }
            // An empty answer is a degraded provider, not a successful run.
            // The streaming path already treated it as LA-EMPTY-RESPONSE;
            // the non-streaming path used to record it as a SUCCESS with an
            // empty body, which silently lost the answer.
            if (trim($reply) === '') {
                $errorMsg = ErrorTaxonomy::formatCode(
                    'LA-EMPTY-RESPONSE',
                    'provider returned a response with no content',
                );
                $this->log('ERROR', 'empty_model_response', ['error' => $errorMsg]);
                $this->recordTrace('error', ['stage' => 'empty_response', 'error' => $errorMsg]);
                $this->finishRun(success: false, content: $reply, error: $errorMsg, stage: 'empty_response');
                return $errorMsg;
            }
            $this->finishRun(success: true, content: $reply);
            return $reply;
        }

        $this->log('ERROR', 'max_retry_reached', ['message' => 'Failed to generate a valid response.']);
        $this->recordTrace('error', ['stage' => 'max_retry', 'error' => 'Failed to generate a valid response.']);
        $this->finishRun(success: false, error: 'max_retry_reached', stage: 'max_retry');
        return 'Failed to generate a valid response.';
    }

    // ------------------------------------------------------------------
    // Streaming logic (core.py _run_stream_logic)
    // ------------------------------------------------------------------

    /**
     * @return \Generator<string|array<string, mixed>>
     */
    protected function runStreamLogic(
        iterable $response,
        int $maxRetry,
        int $maxToolIterations,
        string $resultFormat = 'str',
        string $traceid = '',
        ?int $maxToolCalls = null,
    ): \Generator {
        $lastStreamError = null;
        for ($attempt = 0; $attempt < $maxRetry; $attempt++) {
            try {
                $toolIterations = 0;
                // Backstop: one iteration of this loop is one model round.
                // The tool branch already bounds the rounds with
                // `maxToolIterations`, but that check lives INSIDE the
                // branch — if the branch is ever skipped, this counter is
                // what guarantees the loop terminates.
                $streamRounds = 0;
                while (true) {
                    $streamRounds++;
                    if ($streamRounds > $maxToolIterations + 1) {
                        $errorMsg = ErrorTaxonomy::formatCode(
                            'LA-TOOL',
                            "stream exceeded {$maxToolIterations} model rounds without completing",
                        );
                        $this->log('ERROR', 'stream_round_limit', ['error' => $errorMsg]);
                        $this->recordTrace('error', ['stage' => 'stream_round_limit', 'error' => $errorMsg]);
                        $this->finishRun(success: false, error: $errorMsg, stage: 'stream_round_limit');
                        yield $errorMsg;
                        return;
                    }
                    $output = '';
                    $outputReasoningContent = '';
                    $contentOnly = '';
                    $assembler = new \LightAgent\Llm\ToolCallAssembler();
                    /** @var list<string> $toolResponses */
                    $toolResponses = [];
                    $finishCalled = false;
                    // True once the tool branch has run and issued the next
                    // model request. The tool branch leaves the chunk loop
                    // with `break`, so the code AFTER the loop must be able
                    // to tell "the provider finished this round and we are
                    // starting a new one" apart from "the provider closed
                    // the stream in the middle of a tool call".
                    $toolRoundCompleted = false;
                    // Set when the provider already signalled finish_reason
                    // but has not sent the usage frame yet. The finalisation
                    // is deferred until the frame arrives or the stream ends
                    // (T12), so the token accounting is not lost.
                    $pendingFinalize = false;

                    foreach ($response as $chunk) {
                        if (!$chunk instanceof ChatCompletionChunk) {
                            continue;
                        }
                        $choice0 = $chunk->choices[0] ?? null;
                        $delta = $choice0['delta'] ?? [];

                        $reasoningContent = '';
                        if (isset($delta['reasoning_content']) && $delta['reasoning_content'] !== null) {
                            $reasoningContent = (string) $delta['reasoning_content'];
                        }
                        if ($reasoningContent !== '') {
                            $output .= $reasoningContent;
                            $outputReasoningContent .= $reasoningContent;
                        }

                        $content = '';
                        if (isset($delta['content']) && $delta['content'] !== null) {
                            $content = (string) $delta['content'];
                        }
                        if ($content !== '') {
                            $output .= $content;
                            $contentOnly .= $content;
                            // Surface the assistant text to the caller as it
                            // arrives. Without this the generator only ever
                            // emitted tool-call/tool-result events, so the
                            // final answer never reached a streaming consumer.
                            // The chunk is redacted on the way out so a secret
                            // that arrives inside ONE chunk never reaches the
                            // consumer; the full text is checked again at the
                            // end, which also catches secrets split across
                            // chunk boundaries.
                            yield $this->redactStreamChunk($content);
                        }

                        if ($choice0 !== null) {
                            $assembler->consumeChunk($choice0);
                        }

                        $finishReason = $choice0['finish_reason'] ?? null;
                        $usageOnly = ($chunk->choices === [] || $choice0 === null) && $chunk->usage !== null;

                        if ($finishReason !== null || $usageOnly) {
                            if ($chunk->usage !== null) {
                                $this->accumulateUsage($chunk->usage);
                                // The streaming path never reaches
                                // completeModelRequest()'s accounting (it is
                                // handed a Generator, not a ChatCompletion),
                                // so the token budget was never charged on
                                // streams (T12). Charge it here.
                                $streamTokens = (int) ($chunk->usage['total_tokens'] ?? 0);
                                if ($streamTokens > 0) {
                                    try {
                                        $this->runtime->budget->consume(tokens: $streamTokens);
                                    } catch (BudgetExceeded $exceeded) {
                                        $this->budgetError = ErrorTaxonomy::formatCode('LA-BUDGET', $exceeded->getMessage());
                                    }
                                }
                                if ($this->lastModelTraceEvent !== null) {
                                    $this->lastModelTraceEvent->data['usage'] = TraceUtils::normalizeUsage($chunk->usage);
                                }
                                $this->log('INFO', 'token_usage', ['usage' => $chunk->usage]);
                            }

                            if (!$assembler->hasNamedToolCalls()) {
                                // Empty stream (N15): no deltas and no tool
                                // calls before [DONE] means the upstream
                                // degraded — do not record success.
                                if (trim($output) === '') {
                                    $errorMsg = ErrorTaxonomy::formatCode('LA-EMPTY-RESPONSE', 'provider stream contained no content or tool calls');
                                    $this->log('ERROR', 'empty_model_response', ['error' => $errorMsg]);
                                    $this->recordTrace('error', ['stage' => 'empty_response', 'error' => $errorMsg]);
                                    $this->finishRun(success: false, error: $errorMsg, stage: 'empty_response');
                                    yield $errorMsg;
                                    return;
                                }
                                if ($chunk->usage !== null) {
                                    // The usage frame arrived together with the
                                    // last content chunk: finalise now.
                                    yield from $this->finalizeStreamOutput($contentOnly, $output, $outputReasoningContent);
                                    return;
                                }
                                // The provider sends the usage frame as a
                                // SEPARATE chunk, AFTER the one that carries
                                // finish_reason. Returning here would drop
                                // that frame, so the token budget would never
                                // be charged for the stream (T12). Keep
                                // consuming the stream until the usage frame
                                // arrives or the provider closes the
                                // connection; the finalisation then happens
                                // on the path that ends the chunk loop.
                                $pendingFinalize = true;
                            }
                        }

                        if (in_array($finishReason, ['tool_calls', 'stop'], true) && $assembler->hasNamedToolCalls()) {
                            $toolCalls = $assembler->getToolCalls();
                            $this->log('DEBUG', 'stream tool_calls', ['tool_calls' => $toolCalls]);
                            // Resolve one stable tool_call_id per call so the
                            // assistant message, the tool traces and the tool
                            // results all reference the same id.
                            $streamToolCallIds = [];
                            foreach ($toolCalls as $tcIndex => $tc) {
                                $streamToolCallIds[$tcIndex] = $tc['id'] !== ''
                                    ? $tc['id']
                                    : 'call_' . substr(bin2hex(random_bytes(8)), 0, 12);
                            }
                            foreach ($toolCalls as $toolCall) {
                                $toolName = $toolCall['name'];
                                if ($toolName === '') {
                                    continue;
                                }
                                // Total-execution cap (see the non-streaming
                                // path for the rationale).
                                if ($maxToolCalls !== null && $this->toolCallCount >= $maxToolCalls) {
                                    $errorMsg = ErrorTaxonomy::formatCode(
                                        'LA-TOOL',
                                        "max tool calls ({$maxToolCalls}) reached",
                                    );
                                    $this->recordTrace('error', [
                                        'stage' => 'max_tool_calls',
                                        'error' => $errorMsg,
                                        'max_tool_calls' => $maxToolCalls,
                                    ]);
                                    $this->finishRun(success: false, error: $errorMsg, stage: 'max_tool_calls');
                                    yield $errorMsg;
                                    return;
                                }
                                $this->toolCallCount++;
                                $arguments = $toolCall['arguments'];
                                $toolInfo = $this->toolRegistry->functionInfo[$toolName] ?? [];
                                $toolTitle = (string) ($toolInfo['tool_title'] ?? '');
                                $toolCallId = $streamToolCallIds[$assembler->indexForToolCall($toolCall)] ?? ($toolCall['id'] ?? '');

                                $toolCallInfo = [
                                    'id' => $toolCallId,
                                    'name' => $toolName,
                                    'title' => $toolTitle,
                                    'arguments' => $arguments,
                                ];
                                $this->currentToolCalls[] = $toolCallInfo;
                                $this->recordTrace('tool_call', $toolCallInfo);
                                $this->log('INFO', 'stream function_call', ['tool_call_start' => $toolCallInfo]);
                                yield $toolCallInfo;

                                try {
                                    $parsed = $this->parseToolArguments($arguments);
                                    [$parsed, $guardrailError] = $this->prepareToolCall($toolName, $parsed);
                                    if ($guardrailError !== null) {
                                        $blockStage = self::toolBlockStage($guardrailError);
                                        $this->recordTrace('error', [
                                            'stage' => $blockStage,
                                            'tool' => $toolName,
                                            'error' => $guardrailError,
                                        ]);
                                        $this->notifyError(stage: $blockStage, error: $guardrailError, tool: $toolName);
                                        $toolResponses[] = $guardrailError;
                                        yield ['name' => $toolName, 'title' => $toolTitle, 'error' => $guardrailError];
                                        $blockLoopError = $this->registerToolBlock($toolName);
                                        if ($blockLoopError !== null) {
                                            $this->finishRun(success: false, error: $blockLoopError, stage: 'tool_blocked_loop');
                                            yield $blockLoopError;
                                            return;
                                        }
                                        continue;
                                    }

                                    $toolStartedAt = microtime(true);
                                    $toolResponse = $this->toolDispatcher->dispatch($toolName, $parsed);
                                    $this->registerToolSuccess();

                                    $combinedResponse = '';
                                    $singleToolResponse = '';

                                    if ($toolResponse instanceof \Generator) {
                                        foreach ($toolResponse as $chunkItem) {
                                            $chunkText = is_string($chunkItem) ? $chunkItem : Json::encodeOrError($chunkItem, JSON_UNESCAPED_UNICODE);
                                            $toolOutput = [
                                                'id' => $toolCallId,
                                                'name' => $toolName,
                                                'title' => $toolTitle,
                                                'output' => $chunkText,
                                                'latency_ms' => round((microtime(true) - $toolStartedAt) * 1000, 3),
                                            ];
                                            $this->log('DEBUG', 'stream tool_output', ['tool_output' => $toolOutput]);
                                            $this->recordTrace('tool_result', $toolOutput);
                                            yield $toolOutput;
                                            $combinedResponse .= $toolName === 'finish' ? $chunkText : $chunkText;
                                        }
                                        $singleToolResponse = $combinedResponse;
                                    } else {
                                        $combinedResponse = (string) $toolResponse;
                                        $singleToolResponse = (string) $this->applyAfterToolResult($toolName, $combinedResponse);
                                        $toolOutput = [
                                            'id' => $toolCallId,
                                            'name' => $toolName,
                                            'title' => $toolTitle,
                                            'output' => $singleToolResponse,
                                            'latency_ms' => round((microtime(true) - $toolStartedAt) * 1000, 3),
                                        ];
                                        if (str_starts_with($singleToolResponse, '[LA-')) {
                                            $toolOutput['error'] = $singleToolResponse;
                                        }
                                        $this->recordTrace('tool_result', $toolOutput);
                                        yield $toolOutput;
                                    }

                                    $this->log('INFO', 'stream single_tool_response', ['single_tool_response' => $singleToolResponse]);
                                    $toolResponses[] = $singleToolResponse;

                                    if ($toolName === 'finish') {
                                        $finishCalled = true;
                                        $this->log('INFO', 'finish_tool_called', ['response' => $combinedResponse]);
                                        $this->finishRun(success: true, content: $combinedResponse, stage: 'finish_tool', extra: ['finish_tool' => true]);
                                    }
                                } catch (\JsonException $e) {
                                    $errorMsg = ErrorTaxonomy::formatCode('LA-JSON', "{$e->getMessage()}; arguments: {$arguments}");
                                    $this->log('ERROR', 'json_decode_error', ['tool' => $toolName, 'title' => $toolTitle, 'error' => $errorMsg]);
                                    $this->recordTrace('error', ['stage' => 'tool_arguments', 'tool' => $toolName, 'error' => $errorMsg]);
                                    $this->notifyError(stage: 'tool_arguments', error: $errorMsg, tool: $toolName);
                                    $toolResponses[] = $errorMsg;
                                    yield ['name' => $toolName, 'title' => $toolTitle, 'error' => $errorMsg];
                                } catch (\Throwable $e) {
                                    // The message goes BACK TO THE MODEL as the
                                    // tool result, so it must not carry the
                                    // PHP stack trace: that leaks absolute
                                    // server paths into the prompt (and burns
                                    // tokens). The full trace belongs in the
                                    // server-side trace/log, not in the
                                    // model-facing string.
                                    $errorMsg = ErrorTaxonomy::formatException($e, 'execute stream tool', 'LA-TOOL');
                                    $this->log('ERROR', 'tool_execution_error', ['tool' => $toolName, 'title' => $toolTitle, 'error' => $errorMsg, 'trace' => $e->getTraceAsString()]);
                                    $this->recordTrace('error', ['stage' => 'tool_execution', 'tool' => $toolName, 'error' => $errorMsg]);
                                    $this->notifyError(stage: 'tool_execution', error: $errorMsg, tool: $toolName);
                                    $toolResponses[] = $errorMsg;
                                    yield ['name' => $toolName, 'title' => $toolTitle, 'error' => $errorMsg];
                                }
                            }

                            if ($finishCalled) {
                                return;
                            }

                            $toolIterations++;
                            if ($toolIterations >= $maxToolIterations) {
                                $errorMsg = "Max tool iterations({$maxToolIterations}) reached.";
                                $this->log('ERROR', 'max_tool_iterations_reached', ['message' => $errorMsg]);
                                $this->recordTrace('error', ['stage' => 'max_tool_iterations', 'error' => $errorMsg]);
                                $this->finishRun(
                                    success: false,
                                    error: 'max_tool_iterations_reached',
                                    stage: 'max_tool_iterations',
                                    extra: ['message' => $errorMsg, 'max_tool_iterations' => $maxToolIterations],
                                );
                                yield $errorMsg;
                                return;
                            }

                            // Prepare next request. The assistant message and
                            // the tool results are built from the SAME filtered
                            // list so ids and responses stay aligned by
                            // execution order (H1: never mix assembler indexes
                            // with compacted arrays).
                            $namedToolCalls = [];
                            foreach ($assembler->getToolCalls() as $tcIndex => $tc) {
                                if ($tc['name'] === '') {
                                    continue;
                                }
                                $namedToolCalls[] = [
                                    'id' => $streamToolCallIds[$tcIndex] ?? ($tc['id'] !== '' ? $tc['id'] : 'call_' . substr(bin2hex(random_bytes(4)), 0, 8)),
                                    'type' => 'function',
                                    'function' => ['name' => $tc['name'], 'arguments' => $tc['arguments']],
                                ];
                            }
                            $assistantMessage = $this->buildAssistantToolCallMessage(
                                '',
                                $outputReasoningContent,
                                $namedToolCalls,
                            );
                            $this->chatParams['messages'][] = $assistantMessage;

                            // $toolResponses was appended in the same filtered
                            // execution order as $namedToolCalls.
                            foreach ($namedToolCalls as $k => $tc) {
                                $this->chatParams['messages'][] = [
                                    'role' => 'tool',
                                    'tool_call_id' => $tc['id'],
                                    'content' => (string) ($toolResponses[$k] ?? ''),
                                ];
                            }

                            $this->log('DEBUG', 'stream next_request_params', ['params' => $this->chatParams]);
                            $hookError = $this->prepareModelRequest();
                            if ($hookError !== null) {
                                $this->finishRun(success: false, error: $hookError, stage: 'before_model_request');
                                yield $hookError;
                                return;
                            }
                            try {
                                $response = $this->requestModel($this->chatParams);
                            } catch (\Throwable $e) {
                                $this->completeModelRequest(error: $e);
                                $errorMsg = ErrorTaxonomy::formatException($e, 'continue streaming chat completion');
                                $this->log('ERROR', 'model_request_failed', ['error' => $errorMsg]);
                                $this->recordTrace('error', ['stage' => 'model_request', 'error' => $errorMsg]);
                                $this->finishRun(success: false, error: $errorMsg, stage: 'model_request');
                                yield $errorMsg;
                                return;
                            }
                            $this->completeModelRequest(response: $response);
                            $toolRoundCompleted = true;
                            break; // restart chunk loop with new response
                        }
                    }
                    // The tool branch above leaves the chunk loop with
                    // `break` after it has issued the NEXT model request.
                    // Reaching this point with that flag set means "start
                    // the new round", NOT "the provider closed the stream".
                    if ($toolRoundCompleted) {
                        continue;
                    }
                    if (!$assembler->hasNamedToolCalls()) {
                        // Empty stream (N15): no deltas at all is a degraded
                        // upstream, not a successful empty answer.
                        if (trim($output) === '') {
                            $errorMsg = ErrorTaxonomy::formatCode('LA-EMPTY-RESPONSE', 'provider stream contained no content or tool calls');
                            $this->log('ERROR', 'empty_model_response', ['error' => $errorMsg]);
                            $this->recordTrace('error', ['stage' => 'empty_response', 'error' => $errorMsg]);
                            $this->finishRun(success: false, error: $errorMsg, stage: 'empty_response');
                            yield $errorMsg;
                            return;
                        }
                        // Same finalisation as the finish_reason path: the
                        // `after_model_response` hook fires, the output
                        // guardrails run, and the reasoning trace is kept OUT
                        // of the model_response record.
                        //
                        // This path is reached in two cases: the provider
                        // closed the stream without a usage frame (the
                        // deferred finalisation of T12) or it closed the
                        // stream without any finish_reason at all.
                        //
                        // PORTING-DIFF: the Python original records the raw
                        // output on this path and never runs the guardrails,
                        // so a secret could leave through a truncated
                        // stream.
                        if ($pendingFinalize) {
                            // The provider never sent the usage frame, so
                            // this stream cannot be charged against the
                            // token budget. Make that visible.
                            $this->log('WARNING', 'stream_usage_missing', [
                                'reason' => 'provider closed the stream without a usage frame',
                            ]);
                        }
                        yield from $this->finalizeStreamOutput($contentOnly, $output, $outputReasoningContent);
                        return;
                    }
                    // The provider closed the stream while the assembler still
                    // holds NAMED tool calls, and the tool branch above never
                    // ran — because the stream carried no `finish_reason` of
                    // 'tool_calls'/'stop' (some gateways close the stream
                    // right after a tool call, others use 'length' or
                    // 'content_filter').
                    //
                    // Before this branch existed the loop simply started
                    // over: the generator was already exhausted, so the next
                    // `foreach` produced nothing at all — no I/O, no yield,
                    // no timeout. That is a 100% CPU SPIN that never
                    // returns, and because the iteration cap lives inside
                    // the tool branch it also made `max_tool_iterations`
                    // meaningless on this path.
                    $errorMsg = ErrorTaxonomy::formatCode(
                        'LA-TOOL',
                        'provider closed the stream with unterminated tool calls',
                    );
                    $this->log('ERROR', 'stream_incomplete_tool_calls', ['error' => $errorMsg]);
                    $this->recordTrace('error', ['stage' => 'stream_incomplete', 'error' => $errorMsg]);
                    $this->finishRun(success: false, error: $errorMsg, stage: 'stream_incomplete');
                    yield $errorMsg;
                    return;
                }
            } catch (\Throwable $e) {
                // Keep the last transport/provider error: the stream is lazy,
                // so a TLS / connection / DNS failure surfaces here rather than
                // at the initial request. Reporting a generic "failed to
                // stream" string hides the real cause from operators (H17).
                $lastStreamError = $e;
                $this->retryCount++;
                $this->log('WARNING', 'retry', ['error' => $e->getMessage()]);
                $this->recordTrace('error', ['stage' => 'stream_retry', 'error' => $e->getMessage()]);
                $this->notifyError(stage: 'stream_retry', error: $e->getMessage());
                continue;
            }
        }

        $streamErrorMsg = $lastStreamError !== null
            ? ErrorTaxonomy::formatException($lastStreamError, 'stream chat completion')
            : 'Failed to stream a valid response.';
        $this->log('ERROR', 'max_retry_reached', ['message' => $streamErrorMsg]);
        $this->recordTrace('error', ['stage' => 'max_retry', 'error' => $streamErrorMsg]);
        $this->finishRun(success: false, error: 'max_retry_reached', stage: 'max_retry');
        yield $streamErrorMsg;
    }

    /**
     * Shared finalisation for the streaming path (T12).
     *
     * Runs the `after_model_response` hook, applies the output guardrails,
     * records the model_response trace and closes the run. The reasoning
     * content is kept OUT of the model_response record (it is internal
     * chain-of-thought and must not be persisted with the answer).
     *
     * The same helper serves both exits of the chunk loop: the normal
     * finish_reason path and the deferred one (the provider closed the
     * stream before the usage frame arrived).
     *
     * @return \Generator<string, string> yields the block envelope when the
     *         output was rejected and returns the final text either way
     */
    protected function finalizeStreamOutput(string $contentOnly, string $output, string $reasoningContent): \Generator
    {
        $this->log('INFO', 'stream_response', ['output' => $output]);
        // The reasoning trace is exposed to the caller as a separate field
        // and is NOT persisted with the answer.
        $this->currentReasoningContent = $reasoningContent;
        $finalText = $this->applyOutputGuardrails($contentOnly !== '' ? $contentOnly : $output);
        $blockedStage = self::blockedOutputStage($finalText);
        if ($blockedStage !== null) {
            $this->recordTrace('error', ['stage' => $blockedStage, 'error' => $finalText]);
            $this->finishRun(success: false, content: $finalText, error: $finalText, stage: $blockedStage);
            yield $finalText;
            return $finalText;
        }
        $this->recordTrace('model_response', [
            'content' => $finalText,
            // The reasoning trace is INTERNAL chain-of-thought and must not
            // be persisted with the answer — the docblock of this very
            // method says so, but the field was written into the event
            // anyway, so the chain of thought landed in the trace export
            // and in the session log.
        ]);
        $this->finishRun(success: true, content: $finalText);
        return $finalText;
    }

    /**
     * Detect a final answer that is actually a rejection envelope.
     *
     * PORTING-DIFF: the Python original only looks for the "[LA-GUARDRAIL]"
     * prefix, so a blocked `after_model_response` hook is reported there as
     * a successful run whose answer is the error envelope. The PHP port
     * treats both envelopes as failures (stricter).
     *
     * @return string|null the failing stage, or null when the text is a normal answer
     */
    protected static function blockedOutputStage(string $text): ?string
    {
        if (str_starts_with($text, '[LA-GUARDRAIL]')) {
            return 'output_guardrail';
        }
        if (str_starts_with($text, '[LA-HOOK]')) {
            return 'after_model_response';
        }
        if (str_starts_with($text, '[LA-APPROVAL]')) {
            return 'approval';
        }
        return null;
    }

    /**
     * The trace stage for a blocked tool call.
     *
     * The stage used to be hard-coded to `tool_guardrail`, so an approval
     * block or a hook block was recorded as a guardrail block — an audit
     * that could not tell a policy decision from a safety filter.
     */
    protected static function toolBlockStage(string $errorMessage): string
    {
        foreach ([
            '[LA-APPROVAL]' => 'tool_approval',
            '[LA-GUARDRAIL]' => 'tool_guardrail',
            '[LA-HOOK]' => 'tool_hook',
            '[LA-SANDBOX]' => 'tool_sandbox',
            '[LA-BUDGET]' => 'budget',
            '[LA-TOOL]' => 'tool_execution',
            '[LA-JSON]' => 'tool_arguments',
        ] as $prefix => $stage) {
            if (str_starts_with($errorMessage, $prefix)) {
                return $stage;
            }
        }
        return 'tool_blocked';
    }

    // ------------------------------------------------------------------
    // Shared run helpers (trace/session/hooks/model request lifecycle)
    // ------------------------------------------------------------------

    /**
     * @param iterable<string|array<string, mixed>> $streamResult
     * @return \Generator<StreamEvent>
     */
    protected function streamAsEvents(iterable $streamResult, string $traceId): \Generator
    {
        foreach ($streamResult as $chunk) {
            if (is_array($chunk)) {
                if (array_key_exists('error', $chunk)) {
                    yield new StreamEvent(type: 'error', data: $chunk, traceId: $traceId);
                } elseif (array_key_exists('output', $chunk)) {
                    yield new StreamEvent(type: 'tool_result', data: $chunk, traceId: $traceId);
                } elseif (array_key_exists('name', $chunk) && array_key_exists('arguments', $chunk)) {
                    yield new StreamEvent(type: 'tool_call', data: $chunk, traceId: $traceId);
                } else {
                    yield new StreamEvent(type: 'event', data: $chunk, traceId: $traceId);
                }
            } elseif (is_string($chunk) && str_starts_with($chunk, '[LA-')) {
                yield new StreamEvent(type: 'error', data: $chunk, traceId: $traceId);
            } else {
                yield new StreamEvent(type: 'content', data: $chunk, traceId: $traceId);
            }
        }
    }

    /**
     * @return \Generator<string>
     */
    protected static function errorStream(string $message): \Generator
    {
        yield $message;
    }

    /**
     * @param array<string, mixed>|null $metadata
     * @return list<array<string, mixed>>
     */
    protected function beginSessionRun(
        ?string $sessionId,
        string $query,
        string $userId,
        ?array $metadata,
        bool $stream,
    ): array {
        $this->runtime->openSession($sessionId, metadata: $metadata ?? [], agentId: $this->name, userId: $userId);
        $current = $this->runtime->session;
        $projected = $current !== null ? (new ContextProjector())->messages($current) : [];
        $this->currentSessionId = $current->sessionId ?? null;
        $this->currentTurnId = bin2hex(random_bytes(16));
        $this->sessionTurnFinished = false;
        $this->sessionStoreError = null;
        $this->runtime->context->turnId = $this->currentTurnId;
        $this->runtime->context->runId = $this->currentRunId;
        $this->runtime->context->metadata = $metadata ?? [];
        $this->recordSessionEvent('turn.started', [
            'query' => $query,
            'stream' => $stream,
            'trace_id' => $this->traceid,
        ]);
        $this->recordSessionEvent('message.received', ['role' => 'user', 'content' => $query]);
        return $projected;
    }

    /**
     * @param array<string, mixed>|null $data
     */
    protected function recordSessionEvent(string $eventType, ?array $data = null): mixed
    {
        if ($this->runtime->session === null) {
            return null;
        }
        try {
            $session = $this->runtime->session;
            $event = $session->append(
                $eventType,
                $data ?? [],
                turnId: $this->currentTurnId,
                runId: $this->currentRunId,
                agentId: $this->name,
            );
            $this->runtime->sessionStore->save($session);
            return $event;
        } catch (\Throwable $error) {
            $this->sessionStoreError = $error::class . ': ' . $error->getMessage();
            $this->log('ERROR', 'session_store_error', ['error' => $this->sessionStoreError]);
            return null;
        }
    }

    /**
     * Record a trace event and return the EVENT OBJECT.
     *
     * The event object — not a `toDict()` snapshot — is returned on purpose:
     * the caller finishes a `model_request` event later (latency, usage)
     * and has to write into the very event the recorder holds. Returning a
     * copy made every such write a no-op, so `latency_ms` and `usage` were
     * lost and `TraceSummary::modelLatencyMs` was always 0.0.
     *
     * @param array<string, mixed>|null $data
     */
    protected function recordTrace(string $eventType, ?array $data = null): ?\LightAgent\Tracing\TraceEvent
    {
        $traceData = $data ?? [];
        $sessionTypeMap = [
            'run_start' => 'run.started',
            'model_request' => 'model.requested',
            'model_response' => 'assistant.completed',
            'tool_call' => 'tool.requested',
            'tool_result' => 'tool.completed',
            'guardrail_block' => 'policy.blocked',
            'hook_block' => 'policy.blocked',
            'error' => 'error.recorded',
            'handoff' => 'handoff.requested',
        ];
        $sessionType = $sessionTypeMap[$eventType]
            ?? ($eventType === 'run_end' ? (($traceData['success'] ?? false) ? 'run.completed' : 'run.failed') : 'trace.recorded');

        $sessionData = array_merge($traceData, [
            'trace_type' => $eventType,
            'trace_data' => $traceData,
            'trace_id' => $this->traceid ?: null,
            'parent_trace_id' => $this->parentTraceId,
            'run_group_id' => $this->runGroupId,
        ]);
        if ($eventType === 'model_request') {
            $sessionData['messages'] = Json::decode($this->chatParams['messages'] ?? []);
        }
        $this->recordSessionEvent($sessionType, $sessionData);
        return $this->traceRecorder->record($eventType, $traceData);
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    protected function runHooks(string $phase, ?array $payload = null, ?string $stepName = null, ?string $flowId = null): \LightAgent\Hooks\HookDecision
    {
        $context = new HookContext(
            phase: $phase,
            payload: $payload ?? [],
            traceId: $this->traceid ?: null,
            parentTraceId: $this->parentTraceId,
            runId: $this->currentRunId ?: null,
            runGroupId: $this->runGroupId,
            userId: $this->currentUserId,
            agentName: $this->name,
            flowId: $flowId,
            stepName: $stepName,
            metadata: Json::decode($this->currentRunMetadata) ?? [],
        );
        $decision = $this->hooks->run($context);
        foreach (($decision->metadata['hook_events'] ?? []) as $event) {
            $this->recordTrace('hook_decision', $event);
        }
        foreach (($decision->metadata['review_events'] ?? []) as $event) {
            $eventType = (string) ($event['type'] ?? 'approval_decision');
            unset($event['type']);
            $this->recordTrace($eventType, $event);
        }
        if ($decision->action === HookAction::BLOCK) {
            $this->recordTrace('hook_block', ['phase' => $phase, 'reason' => $decision->reason]);
        }
        return $decision;
    }

    protected function recordPolicyAdapterEvent(string $phase, string $adapter, string $action, ?string $reason = null, ?array $metadata = null): void
    {
        $event = [
            'phase' => $phase,
            'hook' => $adapter,
            'action' => $action,
            'adapter' => true,
        ];
        if ($reason !== null) {
            $event['reason'] = $reason;
        }
        if ($metadata !== null) {
            $event = array_merge($event, $metadata);
        }
        $this->recordTrace('hook_decision', $event);
    }

    protected function finishRun(bool $success, mixed $content = null, ?string $error = null, ?string $stage = null, ?array $extra = null): \LightAgent\Hooks\HookDecision
    {
        $startedAt = $this->runStartedAt;
        $durationSeconds = $startedAt > 0 ? microtime(true) - $startedAt : 0.0;
        if ($this->budgetError !== null) {
            $success = false;
            $error = $error ?? $this->budgetError;
            $stage = $stage ?? 'budget';
        }
        try {
            if ($durationSeconds > 0) {
                $this->runtime->budget->consume(seconds: $durationSeconds);
            }
        } catch (BudgetExceeded $exceeded) {
            $success = false;
            $error = $error ?? ErrorTaxonomy::formatCode('LA-BUDGET', $exceeded->getMessage());
            $stage = $stage ?? 'budget';
        }
        $payload = [
            'success' => $success,
            'content' => $content,
            'error' => $error,
            'stage' => $stage,
        ];
        if ($extra !== null) {
            $payload = array_merge($payload, $extra);
        }
        if (!$success) {
            $this->runHooks('on_error', $payload);
        }
        $decision = $this->runHooks('after_run', $payload);
        if ($decision->payload !== null) {
            if (array_key_exists('content', $decision->payload)) {
                $content = $decision->payload['content'];
            }
            if (array_key_exists('error', $decision->payload)) {
                $error = $decision->payload['error'];
            }
        }
        // The run outcome is authoritative: record it BEFORE the trace event
        // so the structured result and the trace cannot disagree.
        $this->lastRunSuccess = $success;
        $this->lastRunError = $error;
        $this->lastRunStage = $stage;
        $runEnd = ['success' => $success];
        if ($error !== null) {
            $runEnd['error'] = $error;
        }
        if ($stage !== null) {
            $runEnd['stage'] = $stage;
        }
        if ($extra !== null) {
            $runEnd = array_merge($runEnd, $extra);
        }
        if ($startedAt > 0) {
            $runEnd['duration_ms'] = round((microtime(true) - $startedAt) * 1000, 3);
        }
        $runEnd['model_request_count'] = $this->modelRequestCount;
        $runEnd['tool_call_count'] = count($this->currentToolCalls);
        $runEnd['retry_count'] = $this->retryCount;
        $usage = TraceUtils::normalizeUsage($this->currentUsage);
        if ($usage !== []) {
            $runEnd['usage'] = $usage;
        }
        $this->recordTrace('run_end', $runEnd);
        if (!$this->sessionTurnFinished) {
            $this->recordSessionEvent($success ? 'turn.completed' : 'turn.failed', array_merge($runEnd, ['content' => $content]));
            $this->sessionTurnFinished = true;
        }
        return $decision;
    }

    protected function notifyError(string $stage, string $error, mixed ...$payload): \LightAgent\Hooks\HookDecision
    {
        $data = ['stage' => $stage, 'error' => $error];
        foreach ($payload as $k => $v) {
            $data[$k] = $v;
        }
        return $this->runHooks('on_error', $data);
    }

    /**
     * Format a hook block as an error envelope.
     *
     * A hook may nominate a more specific error code through
     * `$decision->metadata['error_code']` — the human-approval hook uses
     * it to report LA-APPROVAL instead of the generic LA-HOOK, so a caller
     * can tell "blocked because a human has not approved this yet" apart
     * from "blocked by an ordinary guard hook".
     */
    protected static function formatHookError(
        string $phase,
        ?string $reason = null,
        ?string $errorCode = null,
    ): string {
        $details = ['phase' => $phase];
        if ($reason !== null && $reason !== '') {
            $details['reason'] = $reason;
        }
        return ErrorTaxonomy::formatCode($errorCode ?? 'LA-HOOK', $details);
    }

    /**
     * Register a blocked tool call.
     *
     * @return string|null an error message when the loop must stop, null otherwise
     */
    protected function registerToolBlock(string $toolName): ?string
    {
        $this->consecutiveToolBlocks++;
        if ($this->consecutiveToolBlocks <= $this->maxConsecutiveToolBlocks) {
            return null;
        }
        return ErrorTaxonomy::formatCode(
            'LA-HOOK',
            "tool `{$toolName}` was blocked {$this->consecutiveToolBlocks} times in a row; stopping the tool loop",
        );
    }

    /**
     * Record a successful tool execution — the block streak is over.
     */
    protected function registerToolSuccess(): void
    {
        $this->consecutiveToolBlocks = 0;
    }

    /**
     * The error code a hook decision nominated, if any.
     *
     * @param array<string, mixed> $metadata
     */
    protected static function hookErrorCode(array $metadata): ?string
    {
        $code = $metadata['error_code'] ?? null;
        if (!is_string($code) || $code === '') {
            return null;
        }
        return $code;
    }

    protected static function formatGuardrailError(string $stage, ?string $reason = null): string
    {
        $details = ['stage' => $stage];
        if ($reason !== null && $reason !== '') {
            $details['reason'] = $reason;
        }
        return ErrorTaxonomy::formatCode('LA-GUARDRAIL', $details);
    }

    protected function currentCancellationError(string $stage): ?string
    {
        $token = $this->currentCancellationToken;
        if ($token === null || !$token->isCancelled()) {
            return null;
        }
        return ErrorTaxonomy::formatCode('LA-CANCELLED', [
            'stage' => $stage,
            'reason' => $token->getReason() ?? 'cancellation requested',
            'token_id' => $token->tokenId,
        ]);
    }

    protected function prepareModelRequest(): ?string
    {
        $cancellationError = $this->currentCancellationError('before_model_request');
        if ($cancellationError !== null) {
            return $cancellationError;
        }
        $this->compactModelContext();
        $decision = $this->runHooks('before_model_request', [
            'params' => Json::decode($this->chatParams),
        ]);
        if ($decision->action === HookAction::BLOCK) {
            return $this->formatHookError('before_model_request', $decision->reason, self::hookErrorCode($decision->metadata));
        }
        if ($decision->payload !== null) {
            $params = $decision->payload['params'] ?? $decision->payload;
            if (is_array($params)) {
                $this->chatParams = $params;
            }
        }
        try {
            $this->runtime->budget->consume(modelCalls: 1);
        } catch (BudgetExceeded $error) {
            return ErrorTaxonomy::formatCode('LA-BUDGET', $error->getMessage());
        }
        $this->modelRequestCount++;
        $requestData = $this->buildModelRequestTrace($this->chatParams);
        $requestData['request_index'] = $this->modelRequestCount;
        $event = $this->recordTrace('model_request', $requestData);
        $this->pendingModelTraceEvent = $event;
        $this->pendingModelStartedAt = microtime(true);
        return null;
    }

    protected function compactModelContext(): void
    {
        // Python core.py _compact_model_context: trim chat history to the
        // configured ContextBudget before each model request.
        $messages = $this->chatParams['messages'] ?? [];
        if ($this->contextBudget === null || $this->contextBudget->fits($messages)) {
            return;
        }
        try {
            $compacted = $this->contextCompactor->compactToBudget(
                is_array($messages) ? $messages : [],
                $this->contextBudget,
            );
        } catch (\RuntimeException $e) {
            // The compactor now refuses to return a result that does not
            // fit the budget. Sending the over-budget request anyway would
            // be rejected by the provider; keeping the original context
            // makes the failure visible in the session log instead.
            $this->recordSessionEvent('context.compaction_failed', [
                'error' => $e->getMessage(),
                'estimated_tokens' => $this->contextBudget->estimate($messages),
            ]);
            $this->log('WARNING', 'context_compaction_failed', ['error' => $e->getMessage()]);
            return;
        }
        $this->chatParams['messages'] = $compacted->messages;
        $this->recordSessionEvent('context.compacted', [
            'removed_count' => $compacted->removedCount,
            'summary' => $compacted->summary,
            'spilled' => $compacted->spilled,
            'estimated_tokens' => $this->contextBudget->estimate($compacted->messages),
        ]);
    }

    protected function completeModelRequest(mixed $response = null, ?\Throwable $error = null): void
    {
        $event = $this->pendingModelTraceEvent;
        $startedAt = $this->pendingModelStartedAt;
        $latencyMs = null;
        if ($startedAt > 0) {
            $latencyMs = round((microtime(true) - $startedAt) * 1000, 3);
        }

        $usageRaw = null;
        if ($response instanceof ChatCompletion) {
            $usageRaw = $response->usage;
        } elseif ($response instanceof ChatCompletionChunk) {
            $usageRaw = $response->usage;
        }
        $usage = TraceUtils::normalizeUsage($usageRaw);
        if ($usage !== []) {
            $this->accumulateUsage($usage);
        }
        if ($event !== null) {
            if ($latencyMs !== null) {
                $event->data['latency_ms'] = $latencyMs;
            }
            $event->data['success'] = $error === null;
            if ($usage !== []) {
                $event->data['usage'] = $usage;
            }
            if ($error !== null) {
                $event->data['error_type'] = $error::class;
            }
        }
        $this->recordSessionEvent($error !== null ? 'model.failed' : 'model.completed', [
            'request_index' => $this->modelRequestCount,
            'latency_ms' => $latencyMs,
            'usage' => $usage ?: null,
            'error_type' => $error !== null ? $error::class : null,
        ]);
        if ($usage !== []) {
            try {
                $this->runtime->budget->consume(tokens: (int) ($usage['total_tokens'] ?? 0));
            } catch (BudgetExceeded $exceeded) {
                $this->budgetError = ErrorTaxonomy::formatCode('LA-BUDGET', $exceeded->getMessage());
            }
        }
        $this->lastModelTraceEvent = $event;
        $this->pendingModelTraceEvent = null;
        $this->pendingModelStartedAt = 0.0;
    }

    /**
     * @param array<string, mixed> $usage
     */
    protected function accumulateUsage(array $usage): void
    {
        $normalized = TraceUtils::normalizeUsage($usage);
        if ($normalized === []) {
            return;
        }
        foreach (array_keys($this->usageTotals) as $key) {
            $this->usageTotals[$key] += $normalized[$key] ?? 0;
        }
        $this->currentUsage = $this->usageTotals;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function buildModelRequestTrace(array $params): array
    {
        $tools = [];
        foreach ($params['tools'] ?? [] as $tool) {
            $tools[] = is_array($tool) ? (string) (($tool['function']['name'] ?? null) ?? json_encode($tool)) : (string) $tool;
        }
        return [
            'model' => $params['model'] ?? null,
            'stream' => (bool) ($params['stream'] ?? false),
            'message_count' => count($params['messages'] ?? []),
            'tools' => $tools,
        ];
    }

    // ------------------------------------------------------------------
    // Guardrails / tool preparation
    // ------------------------------------------------------------------

    protected function applyOutputGuardrails(string $output): string
    {
        return $this->applyOutputGuardrailRedaction($this->runAfterModelResponseHook($output));
    }

    /**
     * Run the `after_model_response` hook and apply its content rewrite.
     *
     * Split out from applyOutputGuardrails() so the streaming path can fire
     * the hook exactly once (on the final text) while still redacting every
     * chunk as it goes by.
     */
    protected function runAfterModelResponseHook(string $output): string
    {
        $hookDecision = $this->runHooks('after_model_response', ['content' => $output]);
        if ($hookDecision->action === HookAction::BLOCK) {
            return $this->formatHookError('after_model_response', $hookDecision->reason, self::hookErrorCode($hookDecision->metadata));
        }
        if ($hookDecision->payload !== null && array_key_exists('content', $hookDecision->payload)) {
            $output = (string) $hookDecision->payload['content'];
        }
        return $output;
    }

    /**
     * Apply the output guardrails (redaction) to a piece of text.
     *
     * Split out from applyOutputGuardrails() so the streaming path can redact
     * each chunk as it is emitted (the final text is still checked in full at
     * the end, which catches secrets split across chunk boundaries).
     */
    protected function applyOutputGuardrailRedaction(string $output): string
    {
        $decision = $this->guardrails->checkOutput($output, [
            'agent_name' => $this->name,
            'trace_id' => $this->traceid,
        ]);
        if (!$decision->allowed) {
            $errorMsg = $this->formatGuardrailError('output', $decision->reason);
            $this->recordPolicyAdapterEvent('after_model_response', 'output_guardrails', 'block', reason: $decision->reason);
            $this->recordTrace('guardrail_block', ['stage' => 'output', 'reason' => $decision->reason]);
            return $errorMsg;
        }
        if ($decision->value !== null) {
            if ((string) $decision->value !== $output) {
                $this->recordPolicyAdapterEvent('after_model_response', 'output_guardrails', 'replace');
            }
            return (string) $decision->value;
        }
        return $output;
    }

    /**
     * Redact a single streamed chunk without recording guardrail events.
     *
     * The events are recorded once, on the final text, by
     * applyOutputGuardrails(). This helper only makes sure that a secret
     * contained in a single chunk never reaches the consumer.
     */
    protected function redactStreamChunk(string $chunk): string
    {
        $decision = $this->guardrails->checkOutput($chunk, [
            'agent_name' => $this->name,
            'trace_id' => $this->traceid,
        ]);
        if (!$decision->allowed) {
            return $this->formatGuardrailError('output', $decision->reason);
        }
        return $decision->value !== null ? (string) $decision->value : $chunk;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    protected function prepareToolCall(string $toolName, array $arguments): array
    {
        $cancellationError = $this->currentCancellationError('before_tool_call');
        if ($cancellationError !== null) {
            return [$arguments, $cancellationError];
        }
        // Python evaluates CapabilitySpec + PolicyEngine here; the unified
        // capability policy is Phase 6 scope. Budget + guardrails + hooks
        // are enforced now with the same ordering.
        // Block the legacy Python execution entry points BEFORE anything else
        // runs. The name check is a heuristic, the identity check is the
        // authoritative one (T2): the Python original compares the function
        // object, so a renamed `execute_python_code` stays blocked there.
        $registeredTool = $this->toolRegistry->functionMappings[$toolName] ?? null;
        // Permission gate. When the run carries a narrowed permission set
        // (a subagent), the tool must be permitted for THIS capability.
        // The capability names the registry uses are `tool.<name>`.
        if ($this->currentPermissions !== null
            && !$this->currentPermissions->allows(
                "tool.{$toolName}",
                \LightAgent\Capabilities\CapabilityRisk::SENSITIVE,
            )
        ) {
            $reason = "tool `{$toolName}` is not permitted for this agent";
            $this->recordSessionEvent('policy.decision', [
                'provider' => 'tools',
                'capability' => "tool.{$toolName}",
                'allowed' => false,
                'reason' => $reason,
            ]);
            $this->recordTrace('policy_block', [
                'stage' => 'tool_permission',
                'tool' => $toolName,
                'reason' => $reason,
            ]);
            return [$arguments, ErrorTaxonomy::formatCode('LA-POLICY', $reason)];
        }
        $requiresSandbox = ToolRegistry::isUnsafePythonTool($toolName)
            || ($registeredTool !== null && ToolRegistry::isUnsafePythonCallable($registeredTool->callable));
        if ($requiresSandbox) {
            $reason = ToolRegistry::unsafePythonToolReason();
            $this->recordSessionEvent('policy.decision', [
                'provider' => 'tools',
                'capability' => "tool.{$toolName}",
                'allowed' => false,
                'requires_sandbox' => true,
                'reason' => $reason,
            ]);
            return [$arguments, ErrorTaxonomy::formatCode('LA-SANDBOX', $reason)];
        }
        try {
            $this->runtime->budget->consume(toolCalls: 1);
        } catch (BudgetExceeded $error) {
            return [$arguments, ErrorTaxonomy::formatCode('LA-BUDGET', $error->getMessage())];
        }
        [$arguments, $guardrailError] = $this->applyToolGuardrails($toolName, $arguments);
        if ($guardrailError !== null) {
            return [$arguments, $guardrailError];
        }

        $decision = $this->runHooks('before_tool_call', [
            'tool_name' => $toolName,
            'arguments' => $arguments,
        ]);
        if ($decision->action === HookAction::BLOCK) {
            return [$arguments, $this->formatHookError('before_tool_call', $decision->reason, self::hookErrorCode($decision->metadata))];
        }
        if ($decision->payload !== null) {
            $updatedArguments = $decision->payload['arguments'] ?? $arguments;
            if (is_array($updatedArguments)) {
                return [$updatedArguments, null];
            }
        }
        return [$arguments, null];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    protected function applyToolGuardrails(string $toolName, array $arguments): array
    {
        $decision = $this->guardrails->checkTool($toolName, $arguments, [
            'agent_name' => $this->name,
            'trace_id' => $this->traceid,
        ]);
        if ($decision->allowed) {
            if (is_array($decision->value)) {
                $updatedArguments = $decision->value['arguments'] ?? $arguments;
                if (is_array($updatedArguments) && $updatedArguments !== $arguments) {
                    $this->recordPolicyAdapterEvent('before_tool_call', 'tool_guardrails', 'replace', metadata: ['tool' => $toolName]);
                    return [$updatedArguments, null];
                }
            }
            return [$arguments, null];
        }
        $errorMsg = $this->formatGuardrailError('tool', $decision->reason);
        $this->recordPolicyAdapterEvent('before_tool_call', 'tool_guardrails', 'block', reason: $decision->reason, metadata: ['tool' => $toolName]);
        $this->recordTrace('guardrail_block', [
            'stage' => 'tool',
            'tool' => $toolName,
            'reason' => $decision->reason,
        ]);
        return [$arguments, $errorMsg];
    }

    protected function applyAfterToolResult(string $toolName, mixed $output): mixed
    {
        $decision = $this->runHooks('after_tool_result', [
            'tool_name' => $toolName,
            'output' => $output,
        ]);
        if ($decision->action === HookAction::BLOCK) {
            return $this->formatHookError('after_tool_result', $decision->reason, self::hookErrorCode($decision->metadata));
        }
        if ($decision->payload !== null && array_key_exists('output', $decision->payload)) {
            return $decision->payload['output'];
        }
        return $output;
    }

    /**
     * @param list<string|ToolFunction> $tools
     * @return list<array<string, mixed>>
     */
    protected function processRuntimeTools(array $tools): array
    {
        $tempRegistry = new ToolRegistry();
        foreach ($tools as $tool) {
            if (is_string($tool)) {
                // Runtime tool registration by name is resolved from the
                // main registry (PORTING-DIFF: no dynamic file loading).
                $existing = $this->toolRegistry->functionMappings[$tool] ?? null;
                if ($existing !== null) {
                    $tempRegistry->registerTool($existing);
                } else {
                    $this->log('ERROR', 'load_runtime_tool', ['tool' => $tool, 'error' => "Tool `{$tool}` is not registered."]);
                }
            } elseif ($tool instanceof ToolFunction) {
                if ($tempRegistry->registerTool($tool)) {
                    $this->toolRegistry->registerTool($tool);
                }
            }
        }
        $runtimeTools = $tempRegistry->getTools();
        $this->log('DEBUG', 'runtime_tools_processed', ['count' => count($runtimeTools)]);
        return $runtimeTools;
    }

    /**
     * Build the assistant message that carries a tool call back to the provider.
     *
     * Two provider-compatibility rules are enforced here:
     *
     * 1. `reasoning_content` is a DeepSeek-style EXTENSION — it is NOT part
     *    of the OpenAI Chat Completions message schema. Sending it (even as
     *    an empty string, which the `?? ''` default used to produce) makes a
     *    strict OpenAI-compatible endpoint answer HTTP 400 "Unrecognized
     *    request argument supplied: messages[N]['reasoning_content']". The
     *    damage is severe: the tool has ALREADY run at that point, but the
     *    second round of the conversation is rejected, so the model never
     *    sees the tool result and the user gets nothing. The field is
     *    therefore only sent when the provider actually returned reasoning
     *    content.
     *
     * 2. The provider's raw `tool_calls` entries are normalised to exactly
     *    `{id, type, function:{name, arguments}}`. Some gateways add extra
     *    keys (LongCat sends `"index": null`), which a strict endpoint
     *    rejects with the same class of 400.
     *
     * @param list<array<string, mixed>> $toolCalls
     * @return array<string, mixed>
     */
    protected function buildAssistantToolCallMessage(
        ?string $content,
        ?string $reasoningContent,
        array $toolCalls,
    ): array {
        $message = [
            'role' => 'assistant',
            // Providers require the content key present (may be empty) on a
            // tool_calls message — the Python original documents the same.
            'content' => $content ?? '',
        ];
        if ($reasoningContent !== null && $reasoningContent !== '') {
            $message['reasoning_content'] = $reasoningContent;
        }

        $normalised = [];
        foreach ($toolCalls as $call) {
            if (!is_array($call)) {
                continue;
            }
            $function = is_array($call['function'] ?? null) ? $call['function'] : [];
            $arguments = $function['arguments'] ?? '';
            if (is_array($arguments)) {
                // Some gateways serialise the arguments as a JSON object
                // instead of a string. The OpenAI schema wants a string.
                $arguments = Json::encodeOrError($arguments, JSON_UNESCAPED_UNICODE);
            }
            $normalised[] = [
                'id' => (string) ($call['id'] ?? ''),
                'type' => (string) ($call['type'] ?? 'function'),
                'function' => [
                    'name' => (string) ($function['name'] ?? ''),
                    'arguments' => (string) $arguments,
                ],
            ];
        }
        $message['tool_calls'] = $normalised;
        return $message;
    }

    /**
     * Perform a model request, retrying TRANSIENT failures with an
     * exponential backoff.
     *
     * Only failures that can plausibly succeed on a second attempt are
     * retried: a 429, a 5xx, a timeout or a transport-level error. A 400
     * (bad request), 401/403 (auth) or 404 (unknown model) is deterministic
     * — retrying it only wastes the user's quota and delays the real error.
     *
     * @param array<string, mixed> $params
     */
    protected function requestModel(array $params): mixed
    {
        $attempt = 0;
        while (true) {
            try {
                return $this->client->chat()->create($params);
            } catch (\Throwable $e) {
                $attempt++;
                if ($attempt > $this->modelMaxRetries || !self::isRetryableModelError($e)) {
                    throw $e;
                }
                $delay = $this->modelRetryBackoff * (2 ** ($attempt - 1));
                $this->log('WARNING', 'model_request_retry', [
                    'attempt' => $attempt,
                    'delay_seconds' => $delay,
                    'error' => $e->getMessage(),
                ]);
                $this->recordTrace('error', [
                    'stage' => 'model_request_retry',
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
                $this->sleepSeconds($delay);
            }
        }
    }

    /**
     * True when the failure is worth another attempt.
     */
    protected static function isRetryableModelError(\Throwable $e): bool
    {
        $status = null;
        if ($e instanceof \LightAgent\Llm\LlmException) {
            $status = $e->statusCode;
        }
        if ($status !== null) {
            return in_array($status, [408, 409, 425, 429, 500, 502, 503, 504], true);
        }
        // Transport-level failures (DNS, refused connection, TLS, timeout)
        // have no status code. They are exactly the kind of failure a retry
        // can fix.
        $text = strtolower($e->getMessage());
        foreach ([
            'curl error',
            'timed out',
            'timeout',
            'connection reset',
            'connection refused',
            'could not resolve host',
            'temporarily unavailable',
        ] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Sleep without stalling sibling fibers.
     *
     * Inside a fiber the event loop is used, so other coroutines keep
     * running. Outside one there is no loop to yield to, so the sleep is a
     * plain blocking wait.
     */
    protected function sleepSeconds(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        if (\Fiber::getCurrent() !== null) {
            try {
                AsyncRuntime::sleep($seconds);
                return;
            } catch (\Throwable) {
                // No usable event loop — fall through to the blocking wait.
            }
        }
        usleep((int) round($seconds * 1_000_000));
    }

    /**
     * @param array<string, mixed>|null $extra
     */
    protected function formatRunResult(mixed $content, string $resultFormat, string $traceId, ?string $error = null): string|RunResult|array
    {
        $text = $content === null ? '' : (string) $content;
        // The explicit argument wins; otherwise fall back to the outcome
        // recorded by finishRun(). The `[LA-` prefix is only a last-resort
        // heuristic for failures that never reached finishRun().
        $detectedError = $error ?? $this->lastRunError;
        $success = $this->lastRunSuccess;
        if ($detectedError === null && str_starts_with($text, '[LA-')) {
            $detectedError = $text;
            $success = false;
        }
        if ($detectedError !== null) {
            $success = false;
        }

        if ($resultFormat === 'str') {
            return $text;
        }

        $result = new RunResult(
            content: $text,
            reasoningContent: $this->currentReasoningContent !== '' ? $this->currentReasoningContent : null,
            toolCalls: Json::decode($this->currentToolCalls) ?? [],
            usage: $this->currentUsage !== null ? Json::decode($this->currentUsage) : null,
            traceId: $traceId,
            trace: $this->exportTrace(),
            error: $detectedError,
            success: $success,
            stage: $this->lastRunStage,
        );
        if ($resultFormat === 'dict') {
            return [
                'content' => $result->content,
                'reasoning_content' => $result->reasoningContent,
                'tool_calls' => $result->toolCalls,
                'usage' => $result->usage,
                'trace_id' => $result->traceId,
                'trace' => $result->trace,
                'error' => $result->error,
                'success' => $result->success,
                'stage' => $result->stage,
            ];
        }
        return $result;
    }

    // ------------------------------------------------------------------
    // Memory pipeline (core.py memory section)
    // ------------------------------------------------------------------

    protected function addMemoryContext(string $query, string $userId): string
    {
        if ($this->memory === null) {
            return $query;
        }

        $context = '';
        $memoryUserId = $this->memoryPolicy->scopedUserId($userId);
        $relatedMemories = $this->retrieveMemoryWithHooks(
            query: $query,
            memoryUserId: $memoryUserId,
            originalUserId: $userId,
            source: 'user',
            scope: 'user',
        );
        if (is_array($relatedMemories) && !empty($relatedMemories['results'])) {
            $memories = array_map(
                static fn (array $m): string => (string) ($m['memory'] ?? ''),
                array_filter($relatedMemories['results'], 'is_array'),
            );
            $context .= "\n##用户偏好\n用户之前提到了:\n" . implode("\n", $memories);
        }
        // PORTING-DIFF (R13): the Python original stores the raw user query
        // with source=user/scope=user — the same partition that backs the
        // "##用户偏好" prompt block, so the preference block fills with past
        // QUESTIONS. PHP keeps the write (dialogue history stays available)
        // but moves it to a dedicated utterance scope so preference recall
        // only surfaces real preferences.
        $this->storeMemoryWithPolicy(
            data: $query,
            memoryUserId: $memoryUserId,
            originalUserId: $userId,
            source: 'user',
            scope: 'utterance',
        );

        if ($this->selfLearning) {
            $agentUserId = $this->memoryPolicy->scopedUserId($this->name);
            $agentMemories = $this->retrieveMemoryWithHooks(
                query: $query,
                memoryUserId: $agentUserId,
                originalUserId: $this->name,
                source: 'reflection',
                scope: 'agent',
            );
            if (is_array($agentMemories) && !empty($agentMemories['results'])) {
                $memories = array_map(
                    static fn (array $m): string => (string) ($m['memory'] ?? ''),
                    array_filter($agentMemories['results'], 'is_array'),
                );
                $context .= "\n##问题相关补充信息:\n" . implode("\n", $memories);
            }
            $this->storeMemoryWithPolicy(
                data: $query,
                memoryUserId: $agentUserId,
                originalUserId: $this->name,
                source: 'reflection',
                scope: 'agent',
            );
        }

        return $context !== '' ? "{$context}\n##用户提问：\n{$query}" : $query;
    }

    protected function retrieveMemoryWithHooks(
        string $query,
        string $memoryUserId,
        string $originalUserId,
        string $source,
        string $scope,
    ): mixed {
        $before = $this->runHooks('before_memory_retrieve', [
            'query' => $query,
            'memory_user_id' => $memoryUserId,
            'original_user_id' => $originalUserId,
            'source' => $source,
            'scope' => $scope,
        ]);
        if ($before->action === HookAction::BLOCK) {
            $this->recordTrace('memory_retrieve_block', [
                'reason' => $before->reason,
                'source' => $source,
                'scope' => $scope,
                'user_id' => $originalUserId,
            ]);
            return ['results' => []];
        }
        if ($before->payload !== null) {
            $query = (string) ($before->payload['query'] ?? $query);
            $memoryUserId = (string) ($before->payload['memory_user_id'] ?? $memoryUserId);
        }

        $memories = $this->memory->retrieve($query, $memoryUserId);
        $after = $this->runHooks('after_memory_retrieve', [
            'query' => $query,
            'memory_user_id' => $memoryUserId,
            'original_user_id' => $originalUserId,
            'source' => $source,
            'scope' => $scope,
            'memories' => $memories,
        ]);
        if ($after->action === HookAction::BLOCK) {
            $this->recordTrace('memory_retrieve_block', [
                'reason' => $after->reason,
                'source' => $source,
                'scope' => $scope,
                'user_id' => $originalUserId,
            ]);
            return ['results' => []];
        }
        if ($after->payload !== null && array_key_exists('memories', $after->payload)) {
            $memories = $after->payload['memories'];
        }

        $memories = $this->filterMemoryResults($memories, $memoryUserId, $originalUserId);
        $this->log('DEBUG', 'memory_retrieve', ['user_id' => $memoryUserId, 'source' => $source, 'scope' => $scope]);
        return $memories;
    }

    protected function filterMemoryResults(mixed $memories, string $scopedUserId, string $originalUserId): mixed
    {
        if (!is_array($memories) || !array_key_exists('results', $memories)) {
            return $memories;
        }
        $results = $memories['results'] ?? [];
        if (!is_array($results)) {
            $results = [];
        }
        $filteredResults = [];
        foreach ($results as $item) {
            if ($this->memoryPolicy->allowsResult($item, $scopedUserId, $originalUserId)) {
                $filteredResults[] = $item;
            }
        }
        $this->recordTrace('memory_retrieve_filter', [
            'user_id' => $originalUserId,
            'memory_user_id' => $scopedUserId,
            'total_count' => count($results),
            'allowed_count' => count($filteredResults),
            'blocked_count' => count($results) - count($filteredResults),
        ]);
        $filtered = $memories;
        $filtered['results'] = $filteredResults;
        return $filtered;
    }

    protected function storeMemoryWithPolicy(
        string $data,
        string $memoryUserId,
        string $originalUserId,
        string $source,
        string $scope,
    ): bool {
        $context = [
            'agent_name' => $this->name,
            'trace_id' => $this->traceid,
            'parent_trace_id' => $this->parentTraceId,
            'run_id' => $this->currentRunId,
            'run_group_id' => $this->runGroupId,
            'user_id' => $originalUserId,
            'memory_user_id' => $memoryUserId,
            'source' => $source,
            'scope' => $scope,
        ];
        $hookDecision = $this->runHooks('before_memory_write', [
            'data' => $data,
            'memory_user_id' => $memoryUserId,
            'original_user_id' => $originalUserId,
            'source' => $source,
            'scope' => $scope,
        ]);
        if ($hookDecision->action === HookAction::BLOCK) {
            $this->recordTrace('memory_write_block', [
                'reason' => $hookDecision->reason,
                'source' => $source,
                'scope' => $scope,
                'user_id' => $originalUserId,
            ]);
            return false;
        }
        if ($hookDecision->payload !== null) {
            $data = (string) ($hookDecision->payload['data'] ?? $data);
        }
        $decision = $this->memoryPolicy->allowsWrite(
            $data,
            $context,
            writeCount: $this->memoryWriteCount,
            recentFingerprints: $this->memoryWriteFingerprints,
        );
        if (!$decision->allowed) {
            $this->recordPolicyAdapterEvent(
                'before_memory_write',
                'memory_write_admission',
                'block',
                reason: $decision->reason,
                metadata: ['source' => $source, 'scope' => $scope],
            );
            $this->recordTrace('memory_write_block', [
                'reason' => $decision->reason,
                'source' => $source,
                'scope' => $scope,
                'user_id' => $originalUserId,
            ]);
            return false;
        }

        $storedData = $decision->value ?? $data;
        if ($decision->value !== null && $decision->value !== $data) {
            $this->recordPolicyAdapterEvent(
                'before_memory_write',
                'memory_write_admission',
                'replace',
                metadata: ['source' => $source, 'scope' => $scope],
            );
        }
        $metadata = (new MemoryScope(
            source: $source,
            scope: $scope,
            agentName: $this->name,
            traceId: $this->traceid,
            parentTraceId: $this->parentTraceId,
            metadata: [
                'user_id' => $memoryUserId,
                'original_user_id' => $originalUserId,
                'run_id' => $this->currentRunId,
                'run_group_id' => $this->runGroupId,
            ],
        ))->toMetadata();
        if ($this->requiresMemoryPromotion($source, $scope)) {
            return $this->handleMemoryPromotionCandidate(
                data: $storedData,
                memoryUserId: $memoryUserId,
                originalUserId: $originalUserId,
                source: $source,
                scope: $scope,
                metadata: $metadata,
                context: $context,
            );
        }

        $this->storeMemoryRecord($storedData, $memoryUserId, $metadata);
        $this->runHooks('after_memory_write', [
            'data' => $storedData,
            'metadata' => $metadata,
            'memory_user_id' => $memoryUserId,
            'original_user_id' => $originalUserId,
            'source' => $source,
            'scope' => $scope,
        ]);
        $this->memoryWriteCount++;
        $this->memoryWriteFingerprints[$this->memoryPolicy::writeFingerprint($storedData, $context)] = true;
        $this->recordTrace('memory_write', [
            'source' => $source,
            'scope' => $scope,
            'user_id' => $originalUserId,
        ]);
        return true;
    }

    protected function requiresMemoryPromotion(string $source, string $scope): bool
    {
        if (!$this->memoryPolicy->requirePromotionForInternalMemory) {
            return false;
        }
        $internalSources = ['reflection', 'delegation', 'self_learning', 'agent', 'derived', 'swarm', 'trace', 'tool'];
        $internalScopes = ['agent', 'delegation', 'shared', 'internal', 'swarm', 'flow'];
        return in_array(strtolower($source), $internalSources, true)
            || in_array(strtolower($scope), $internalScopes, true);
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $context
     */
    protected function handleMemoryPromotionCandidate(
        string $data,
        string $memoryUserId,
        string $originalUserId,
        string $source,
        string $scope,
        array $metadata,
        array $context,
    ): bool {
        $candidate = new MemoryCandidate(
            data: $data,
            memoryUserId: $memoryUserId,
            originalUserId: $originalUserId,
            source: $source,
            scope: $scope,
            agentName: $this->name,
            traceId: $this->traceid,
            parentTraceId: $this->parentTraceId,
            runId: $this->currentRunId,
            runGroupId: $this->runGroupId,
            metadata: $metadata,
        );
        $this->upsertMemoryCandidate($candidate);
        $this->recordTrace('memory_promotion_required', $this->memoryCandidateTrace($candidate));
        $promotionContext = array_merge($context, ['candidate_id' => $candidate->id()]);

        $hookDecision = $this->runHooks('before_memory_promote', [
            'candidate' => $candidate->toDict(includeData: true),
            'data' => $candidate->data,
            'memory_user_id' => $candidate->memoryUserId,
            'original_user_id' => $candidate->originalUserId,
            'source' => $candidate->source,
            'scope' => $candidate->scope,
        ]);
        if ($hookDecision->action === HookAction::BLOCK) {
            $blocked = $candidate->withUpdates(['status' => 'blocked']);
            $this->upsertMemoryCandidate($blocked);
            $this->recordTrace('memory_promotion_blocked', array_merge(
                $this->memoryCandidateTrace($blocked),
                ['reason' => $hookDecision->reason],
            ));
            return false;
        }
        if ($hookDecision->payload !== null) {
            $candidate = $this->candidateFromHookPayload($candidate, $hookDecision->payload);
            $this->upsertMemoryCandidate($candidate);
            $payloadDecision = $hookDecision->payload['decision'] ?? null;
            if ($payloadDecision !== null) {
                $promotionDecision = $payloadDecision instanceof MemoryPromotionDecision
                    ? $payloadDecision
                    : $this->coercePromotionDecisionPublic($payloadDecision, $candidate->data);
                return $this->applyMemoryPromotionDecision($candidate, $promotionDecision, $promotionContext);
            }
        }

        $promotionDecision = $this->memoryPolicy->allowsPromotion($candidate, $promotionContext);
        return $this->applyMemoryPromotionDecision($candidate, $promotionDecision, $promotionContext);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function candidateFromHookPayload(MemoryCandidate $candidate, array $payload): MemoryCandidate
    {
        $data = (string) ($payload['data'] ?? $candidate->data);
        $metadata = $candidate->metadata;
        if (is_array($payload['metadata'] ?? null)) {
            $metadata = array_merge($metadata, $payload['metadata']);
        }
        if (is_array($payload['candidate'] ?? null)) {
            $candidatePayload = $payload['candidate'];
            $data = (string) ($candidatePayload['data'] ?? $data);
            if (is_array($candidatePayload['metadata'] ?? null)) {
                $metadata = array_merge($metadata, $candidatePayload['metadata']);
            }
        }
        return $candidate->withUpdates(['data' => $data, 'metadata' => $metadata]);
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function applyMemoryPromotionDecision(
        MemoryCandidate $candidate,
        MemoryPromotionDecision $decision,
        array $context,
        bool $manual = false,
    ): bool {
        if (!$decision->promotes()) {
            $status = $decision->action === 'reject' ? 'rejected' : 'kept';
            $updated = $candidate->withUpdates(['status' => $status, 'injectable' => false]);
            $this->upsertMemoryCandidate($updated);
            $eventType = $decision->action === 'reject' ? 'memory_promotion_rejected' : 'memory_promotion_blocked';
            $this->recordTrace($eventType, array_merge(
                $this->memoryCandidateTrace($updated),
                ['decision' => $decision->action, 'reason' => $decision->reason, 'manual' => $manual],
            ));
            return false;
        }

        $promotedData = $decision->value ?? $candidate->data;
        $promotedMetadata = $candidate->toMetadata();
        $promotedMetadata = array_merge($promotedMetadata, $decision->metadata);
        $promotedMetadata = array_merge($promotedMetadata, [
            'promotion_status' => 'promoted',
            'promotion_decision' => $decision->action,
            'injectable' => true,
            'promoted_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        ]);
        $promoted = $candidate->withUpdates([
            'data' => (string) $promotedData,
            'status' => 'promoted',
            'injectable' => true,
            'metadata' => $promotedMetadata,
        ]);
        $this->storeMemoryRecord((string) $promotedData, $candidate->memoryUserId, $promotedMetadata);
        $this->upsertMemoryCandidate($promoted);

        $eventType = $decision->action === 'rewrite' ? 'memory_promotion_rewritten' : 'memory_promotion_approved';
        $this->recordTrace($eventType, array_merge(
            $this->memoryCandidateTrace($promoted),
            ['decision' => $decision->action, 'reason' => $decision->reason, 'manual' => $manual],
        ));
        $this->runHooks('after_memory_promote', [
            'candidate' => $promoted->toDict(includeData: true),
            'data' => (string) $promotedData,
            'metadata' => $promotedMetadata,
            'memory_user_id' => $candidate->memoryUserId,
            'original_user_id' => $candidate->originalUserId,
            'source' => $candidate->source,
            'scope' => $candidate->scope,
        ]);
        $this->runHooks('after_memory_write', [
            'data' => (string) $promotedData,
            'metadata' => $promotedMetadata,
            'memory_user_id' => $candidate->memoryUserId,
            'original_user_id' => $candidate->originalUserId,
            'source' => $candidate->source,
            'scope' => $candidate->scope,
        ]);

        $this->memoryWriteCount++;
        $this->memoryWriteFingerprints[$this->memoryPolicy::writeFingerprint((string) $promotedData, $context)] = true;
        $this->recordTrace('memory_write', [
            'source' => $candidate->source,
            'scope' => $candidate->scope,
            'user_id' => $candidate->originalUserId,
            'candidate_id' => $candidate->id(),
            'promotion_status' => 'promoted',
        ]);
        return true;
    }

    protected function memoryPromotionContext(MemoryCandidate $candidate): array
    {
        return [
            'agent_name' => $candidate->agentName,
            'trace_id' => $candidate->traceId,
            'parent_trace_id' => $candidate->parentTraceId,
            'run_id' => $candidate->runId,
            'run_group_id' => $candidate->runGroupId,
            'user_id' => $candidate->originalUserId,
            'memory_user_id' => $candidate->memoryUserId,
            'source' => $candidate->source,
            'scope' => $candidate->scope,
            'candidate_id' => $candidate->id(),
        ];
    }

    protected function upsertMemoryCandidate(MemoryCandidate $candidate): void
    {
        foreach ($this->memoryPromotionCandidates as $index => $existing) {
            if ($existing->id() === $candidate->id()) {
                $this->memoryPromotionCandidates[$index] = $candidate;
                return;
            }
        }
        $this->memoryPromotionCandidates[] = $candidate;
    }

    protected function findMemoryCandidate(string $candidateId): ?MemoryCandidate
    {
        foreach ($this->memoryPromotionCandidates as $candidate) {
            if ($candidate->id() === $candidateId) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function memoryCandidateTrace(MemoryCandidate $candidate): array
    {
        return [
            'candidate_id' => $candidate->id(),
            'source' => $candidate->source,
            'scope' => $candidate->scope,
            'user_id' => $candidate->originalUserId,
            'memory_user_id' => $candidate->memoryUserId,
            'agent_name' => $candidate->agentName,
            'status' => $candidate->status,
            'injectable' => $candidate->injectable,
        ];
    }

    protected function storeMemoryRecord(string $data, string $memoryUserId, array $metadata): mixed
    {
        // PORTING-DIFF: Python introspects whether store() accepts metadata;
        // PHP memory adapters should accept (data, user_id, metadata).
        try {
            return $this->memory->store($data, $memoryUserId, $metadata);
        } catch (\ArgumentCountError) {
            return $this->memory->store($data, $memoryUserId);
        }
    }

    /**
     * Public coercion wrapper for promote_memory_candidate.
     */
    public function coercePromotionDecisionPublic(mixed $rawDecision, string $currentValue): MemoryPromotionDecision
    {
        if ($rawDecision instanceof MemoryPromotionDecision) {
            return $rawDecision;
        }
        if ($rawDecision === null) {
            return MemoryPromotionDecision::keep('Memory promotion callback did not approve this candidate.');
        }
        if ($rawDecision === true) {
            return MemoryPromotionDecision::approve($currentValue);
        }
        if ($rawDecision === false) {
            return MemoryPromotionDecision::reject('Memory promotion callback rejected this candidate.');
        }
        if (is_string($rawDecision)) {
            return MemoryPromotionDecision::reject($rawDecision);
        }
        if (is_array($rawDecision)) {
            $action = $rawDecision['action'] ?? null;
            if ($action !== null) {
                return new MemoryPromotionDecision(
                    action: (string) $action,
                    reason: isset($rawDecision['reason']) ? (string) $rawDecision['reason'] : null,
                    value: isset($rawDecision['value']) ? (string) $rawDecision['value'] : null,
                    metadata: is_array($rawDecision['metadata'] ?? null) ? $rawDecision['metadata'] : [],
                );
            }
            $allowed = $rawDecision['allowed'] ?? ($rawDecision['promote'] ?? null);
            if ($allowed === true) {
                $value = (string) ($rawDecision['value'] ?? $currentValue);
                if ($value !== $currentValue) {
                    return MemoryPromotionDecision::rewrite($value, reason: $rawDecision['reason'] ?? null, metadata: $rawDecision['metadata'] ?? null);
                }
                return MemoryPromotionDecision::approve($value, reason: $rawDecision['reason'] ?? null, metadata: $rawDecision['metadata'] ?? null);
            }
            if ($allowed === false) {
                return MemoryPromotionDecision::reject($rawDecision['reason'] ?? null, metadata: $rawDecision['metadata'] ?? null);
            }
            if (array_key_exists('value', $rawDecision)) {
                return MemoryPromotionDecision::rewrite((string) $rawDecision['value'], reason: $rawDecision['reason'] ?? null, metadata: $rawDecision['metadata'] ?? null);
            }
            return MemoryPromotionDecision::keep($rawDecision['reason'] ?? null, metadata: $rawDecision['metadata'] ?? null);
        }
        return MemoryPromotionDecision::keep('Memory promotion callback returned an unsupported decision.');
    }

    // ------------------------------------------------------------------
    // Tool argument parsing (core.py _parse_tool_arguments)
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function parseToolArguments(string $argumentsStr): array
    {
        // Method 1: direct JSON decode
        $direct = json_decode($argumentsStr, true);
        if (is_array($direct)) {
            return $direct;
        }

        // Method 2: extract JSON object via regex
        if (preg_match('/\{.*\}/s', $argumentsStr, $m) === 1) {
            $jsonStr = $m[0];
            $jsonStr = str_replace(['\"', '\\\\'], ['"', '\\'], $jsonStr);
            $candidate = json_decode($jsonStr, true);
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        // Method 3: manual key/value parsing (faithful port)
        $content = trim($argumentsStr);
        if (str_starts_with($content, '{') && str_ends_with($content, '}')) {
            $content = trim(substr($content, 1, -1));
        }
        $result = [];
        $currentKey = null;
        $currentValue = '';
        $inString = false;
        $escapeNext = false;
        $bracketDepth = 0;
        $len = mb_strlen($content);

        $i = 0;
        while ($i < $len) {
            $char = mb_substr($content, $i, 1);

            if ($escapeNext) {
                $currentValue .= $char;
                $escapeNext = false;
                $i++;
                continue;
            }
            if ($char === '\\') {
                $escapeNext = true;
                $currentValue .= $char;
                $i++;
                continue;
            }
            if ($char === '"') {
                $inString = !$inString;
                $currentValue .= $char;
                $i++;
                continue;
            }
            if (!$inString) {
                if ($char === '{') {
                    $bracketDepth++;
                    $currentValue .= $char;
                } elseif ($char === '}') {
                    $bracketDepth--;
                    $currentValue .= $char;
                } elseif ($char === ':' && $bracketDepth === 0 && $currentKey === null) {
                    $currentKey = trim(trim($currentValue), '"');
                    $currentValue = '';
                    $i++;
                    continue;
                } elseif ($char === ',' && $bracketDepth === 0 && $currentKey !== null) {
                    $valueStr = trim($currentValue);
                    if (str_starts_with($valueStr, '"') && str_ends_with($valueStr, '"')) {
                        $result[$currentKey] = str_replace('\"', '"', substr($valueStr, 1, -1));
                    } elseif ($valueStr === 'true') {
                        $result[$currentKey] = true;
                    } elseif ($valueStr === 'false') {
                        $result[$currentKey] = false;
                    } elseif ($valueStr === 'null') {
                        $result[$currentKey] = null;
                    } elseif (is_numeric($valueStr)) {
                        $result[$currentKey] = str_contains($valueStr, '.') ? (float) $valueStr : (int) $valueStr;
                    } else {
                        $result[$currentKey] = $valueStr;
                    }
                    $currentKey = null;
                    $currentValue = '';
                    $i++;
                    continue;
                }
            }
            $currentValue .= $char;
            $i++;
        }

        if ($currentKey !== null) {
            $valueStr = trim($currentValue);
            if (str_starts_with($valueStr, '"') && str_ends_with($valueStr, '"')) {
                $result[$currentKey] = str_replace('\"', '"', substr($valueStr, 1, -1));
            } else {
                $result[$currentKey] = $valueStr;
            }
        }

        if ($result !== []) {
            return $result;
        }

        throw new \JsonException("无法解析参数: {$argumentsStr}");
    }

    // ------------------------------------------------------------------
    // ToT (core.py run_thought)
    // ------------------------------------------------------------------

    /**
     * @param list<array<string, mixed>> $runtimeTools
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    public function runThought(string $query, array $runtimeTools = []): array
    {
        $totModel = $this->totModel ?? 'deepseek-r1';
        $tools = $runtimeTools !== []
            ? json_encode($runtimeTools, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            : $this->toolRegistry->getToolsStr();
        $now = new \DateTimeImmutable('now');
        $systemPrompt = "你是一个智能助手，请根据用户输入的问题，结合工具使用计划，生成一个思维树，并按照思维树依次调用工具步骤，最终生成一个最终回答。\n"
            . ' 今日的日期: ' . $now->format('Y-m-d') . ' 当前时间: ' . $now->format('H:i:s') . " \n 工具列表: {$tools}";

        try {
            $response = $this->totClient->chat()->create([
                'model' => $totModel,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $query],
                ],
                'stream' => false,
            ]);
            $thoughtResponse = $response->choices[0]['message']->content ?? '';

            $reflectionResponse = $this->totClient->chat()->create([
                'model' => $totModel,
                'messages' => [
                    ['role' => 'user', 'content' => "{$systemPrompt} /n 开始思考问题: {$query}"],
                    ['role' => 'assistant', 'content' => $thoughtResponse],
                    ['role' => 'user', 'content' => '请反思你的回答，请严格按照<工具列表>中的工具来规划，不可以创造其他新的工具。请输出新的任务规划，不要输出其他分析和回答。'],
                ],
                'stream' => false,
            ]);
            $refinedContent = $reflectionResponse->choices[0]['message']->content ?? '';

            $toolReflectionResponse = $this->totClient->chat()->create([
                'model' => $totModel,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => "问题分析请求：{$query}"],
                    ['role' => 'assistant', 'content' => $refinedContent],
                    ['role' => 'user', 'content' => "请严格按以下要求执行：\n1. 分析问题需求并规划需要使用的工具\n2. 仅输出包含工具名称的JSON格式结果\n3. 使用以下结构（示例）：\n{\"tools\": [{\"name\": \"工具名称1\"}, {\"name\": \"工具名称2\"}]}\n4. 不要包含任何解释性内容"],
                ],
                'response_format' => ['type' => 'json_object'],
                'stream' => false,
            ]);
            $toolReflectionResult = $toolReflectionResponse->choices[0]['message']->content ?? '';

            $currentTools = [];
            if ($this->filterTools) {
                $currentTools = $runtimeTools !== []
                    ? $runtimeTools
                    : $this->toolRegistry->filterTools($toolReflectionResult);
                $this->log('DEBUG', 'current_tools', ['get_tools' => $currentTools]);
            }

            return [$refinedContent, $currentTools];
        } catch (\Throwable $e) {
            $this->log('ERROR', 'run_thought_failure', ['error' => $e->getMessage()]);
            throw new \RuntimeException('思维链执行失败: ' . $e->getMessage(), 0, $e);
        }
    }

    // ------------------------------------------------------------------
    // Handoff (core.py _detect_intent / _handle_task_transfer)
    // ------------------------------------------------------------------

    /**
     * @return array{transfer_to: string}|null
     */
    public function detectIntent(string $query, ?LightSwarm $lightSwarm = null): ?array
    {
        if ($lightSwarm === null || $lightSwarm->agents === []) {
            return null;
        }
        $agentsInfo = [];
        foreach ($lightSwarm->agents as $agentName => $agent) {
            $agentsInfo[] = "代理名称: {$agentName}, 代理指令: {$agent->instructions}";
        }
        $agentsInfoStr = implode("\n", $agentsInfo);
        $prompt = "请分析以下用户输入的意图，如果需要转移任务，请返回目标代理的名称格式如下。\n"
            . "        transfer to agent_name\n"
            . "        以下是所有可用代理的信息：\n"
            . "            {$agentsInfoStr}\n"
            . "        用户输入: {$query}\n"
            . "        请返回目标代理的名称：\n        ";

        $response = $this->client->chat()->create([
            'model' => $this->model,
            'messages' => [['role' => 'system', 'content' => $prompt]],
        ]);
        $intent = $response->choices[0]['message']->content ?? '';
        $this->log('DEBUG', 'detect_intent', ['intent' => $intent]);

        // Longest-name-first matching (M36): when agent names overlap
        // (`a` vs `ab`), the intent "transfer to ab" must route to the
        // longer name, not be swallowed by its prefix sibling.
        $matched = null;
        foreach (array_keys($lightSwarm->agents) as $agentName) {
            if (str_contains($intent, "transfer to {$agentName}")
                && ($matched === null || strlen($agentName) > strlen($matched))) {
                $matched = $agentName;
            }
        }
        if ($matched !== null) {
            return ['transfer_to' => $matched];
        }
        return null;
    }

    /**
     * @param array<string, mixed> $runOptions
     */
    protected function handleTaskTransfer(string $query, LightSwarm $lightSwarm, bool $stream, array $runOptions): mixed
    {
        $intent = $this->detectIntent($query, $lightSwarm);
        if ($intent === null || !isset($intent['transfer_to'])) {
            return null;
        }
        $targetAgentName = $intent['transfer_to'];
        $this->log('INFO', 'detect_intent', ['intent' => $intent]);
        if ($targetAgentName === $this->name) {
            $this->log('INFO', 'self_transfer_detected', ['target_agent' => $targetAgentName]);
            return null;
        }

        // Bounded handoff chain (C3): track the visited agent path so ping
        // pong A->B->A->B cannot recurse without limit and exhaust memory.
        // Depth is capped too — a long but acyclic chain must also stop.
        $handoffPath = $runOptions['_handoff_path'] ?? [];
        $maxHandoffDepth = $runOptions['max_handoff_depth'] ?? 3;
        if (in_array($this->name, $handoffPath, true)) {
            $errorMsg = "Handoff cycle detected: [" . implode(' -> ', array_merge($handoffPath, [$this->name])) . "]";
            $this->recordTrace('handoff', ['source_agent' => $this->name, 'target_agent' => $targetAgentName, 'status' => 'failed', 'error' => $errorMsg]);
            $this->finishRun(success: false, error: $errorMsg, stage: 'handoff');
            return $stream ? self::errorStream($errorMsg) : $errorMsg;
        }
        if (count($handoffPath) >= $maxHandoffDepth) {
            $errorMsg = "Max handoff depth ({$maxHandoffDepth}) exceeded: [" . implode(' -> ', array_merge($handoffPath, [$targetAgentName])) . "]";
            $this->recordTrace('handoff', ['source_agent' => $this->name, 'target_agent' => $targetAgentName, 'status' => 'failed', 'error' => $errorMsg]);
            $this->finishRun(success: false, error: $errorMsg, stage: 'handoff');
            return $stream ? self::errorStream($errorMsg) : $errorMsg;
        }
        $handoffPath[] = $this->name;

        $targetAgent = $lightSwarm->agents[$targetAgentName] ?? null;
        $handoffData = [
            'source_agent' => $this->name,
            'target_agent' => $targetAgentName,
            'query' => $query,
            'stream' => $stream,
        ];
        if ($targetAgent === null) {
            $errorMsg = 'Failed to transfer task: invalid target agent';
            $this->recordTrace('handoff', array_merge($handoffData, ['status' => 'failed', 'error' => $errorMsg]));
            $this->finishRun(success: false, error: $errorMsg, stage: 'handoff', extra: [
                'source_agent' => $this->name,
                'target_agent' => $targetAgentName,
            ]);
            return $stream ? self::errorStream($errorMsg) : $errorMsg;
        }

        $decision = $this->runHooks('on_handoff', $handoffData);
        if ($decision->action === HookAction::BLOCK) {
            $errorMsg = $this->formatHookError('on_handoff', $decision->reason, self::hookErrorCode($decision->metadata));
            $this->recordTrace('handoff', array_merge($handoffData, ['status' => 'blocked', 'reason' => $decision->reason]));
            $this->finishRun(success: false, error: $errorMsg, stage: 'on_handoff', extra: [
                'source_agent' => $this->name,
                'target_agent' => $targetAgentName,
            ]);
            return $stream ? self::errorStream($errorMsg) : $errorMsg;
        }

        if ($decision->payload !== null) {
            $query = (string) ($decision->payload['query'] ?? $query);
            $editedTarget = (string) ($decision->payload['target_agent'] ?? $targetAgentName);
            if ($editedTarget !== $targetAgentName) {
                $targetAgentName = $editedTarget;
                $targetAgent = $lightSwarm->agents[$targetAgentName] ?? null;
                if ($targetAgent === null) {
                    $errorMsg = 'Failed to transfer task: invalid approved target agent';
                    $this->recordTrace('handoff', array_merge($handoffData, [
                        'target_agent' => $targetAgentName,
                        'status' => 'failed',
                        'error' => $errorMsg,
                    ]));
                    $this->finishRun(success: false, error: $errorMsg, stage: 'handoff', extra: [
                        'source_agent' => $this->name,
                        'target_agent' => $targetAgentName,
                    ]);
                    return $stream ? self::errorStream($errorMsg) : $errorMsg;
                }
            }
            $handoffData['target_agent'] = $targetAgentName;
            $handoffData['query'] = $query;
        }

        $this->recordTrace('handoff', array_merge($handoffData, ['status' => 'allowed']));
        try {
            if ($stream) {
                return $this->handleTaskTransferStream($targetAgent, $query, $lightSwarm, $runOptions);
            }
            $result = $this->handleTaskTransferNonStream($targetAgent, $query, $lightSwarm, $runOptions);
        } catch (\Throwable $exc) {
            $errorMsg = ErrorTaxonomy::formatException($exc, 'handoff to agent');
            $this->recordTrace('error', [
                'stage' => 'handoff',
                'target_agent' => $targetAgentName,
                'error' => $errorMsg,
            ]);
            $this->finishRun(success: false, error: $errorMsg, stage: 'handoff', extra: [
                'source_agent' => $this->name,
                'target_agent' => $targetAgentName,
            ]);
            throw $exc;
        }
        $this->finishRun(success: true, content: is_string($result) ? $result : null, stage: 'handoff', extra: [
            'source_agent' => $this->name,
            'target_agent' => $targetAgentName,
        ]);
        return $result;
    }

    /**
     * @param array<string, mixed> $runOptions
     * @return \Generator<string|array<string, mixed>>
     */
    protected function handleTaskTransferStream(LightAgent $targetAgent, string $context, LightSwarm $lightSwarm, array $runOptions): \Generator
    {
        $this->log('INFO', 'transfer_to_agent', ['from' => $this->name, 'to' => $targetAgent->name, 'context' => $context]);
        $targetRunOptions = $runOptions;
        $targetRunOptions['light_swarm'] = $lightSwarm;
        $targetRunOptions['stream'] = true;
        $targetRunOptions['parent_trace_id'] = $this->traceid ?: null;
        $targetRunOptions['run_group_id'] = $this->runGroupId ?? $this->currentRunId;
        $targetRunOptions['_handoff_path'] = array_merge($runOptions['_handoff_path'] ?? [], [$this->name]);

        $stream = $targetAgent->run($context, $targetRunOptions);
        if ($stream instanceof \Generator) {
            yield from $stream;
        }
        $this->finishRun(success: true, stage: 'handoff', extra: [
            'source_agent' => $this->name,
            'target_agent' => $targetAgent->name,
        ]);
    }

    /**
     * @param array<string, mixed> $runOptions
     */
    protected function handleTaskTransferNonStream(LightAgent $targetAgent, string $context, LightSwarm $lightSwarm, array $runOptions): string
    {
        $this->log('INFO', 'transfer_to_agent', ['from' => $this->name, 'to' => $targetAgent->name, 'context' => $context]);
        $targetRunOptions = $runOptions;
        $targetRunOptions['light_swarm'] = $lightSwarm;
        $targetRunOptions['stream'] = false;
        $targetRunOptions['parent_trace_id'] = $this->traceid ?: null;
        $targetRunOptions['run_group_id'] = $this->runGroupId ?? $this->currentRunId;
        $targetRunOptions['_handoff_path'] = array_merge($runOptions['_handoff_path'] ?? [], [$this->name]);

        $result = $targetAgent->run($context, $targetRunOptions);
        if ($result instanceof \Generator) {
            $joined = '';
            foreach ($result as $chunk) {
                $joined .= is_string($chunk) ? $chunk : Json::encodeOrError($chunk, JSON_UNESCAPED_UNICODE);
            }
            return $joined;
        }
        if (is_string($result)) {
            return $result;
        }
        if ($result instanceof RunResult) {
            return $result->content;
        }
        return Json::encodeOrError($result, JSON_UNESCAPED_UNICODE);
    }
}
