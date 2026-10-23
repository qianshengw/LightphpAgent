<?php

declare(strict_types=1);

/**
 * LightAgent PHP — top-level facade.
 *
 * Mirrors the export role of the Python package's __init__.py: after
 * requiring autoload.php, consuming code can reference the framework's
 * public API under the short, unqualified names listed in ALIASES without
 * importing each class separately. Aliases are lazy: the underlying classes
 * are only loaded when first referenced.
 *
 * Usage:
 *   require 'autoload.php';
 *   LightAgent::register();
 *   $agent = new \LightAgent();            // alias of LightAgent\Core\LightAgent
 *   $flow   = new \LightFlow();            // alias of LightAgent\Flow\LightFlow
 *   // ... or import explicit FQCNs as before — both styles work.
 */

namespace LightAgent;

final class LightAgent
{
    /** Short name => fully-qualified class name. */
    public const ALIASES = [
        // Core
        'LightAgent' => 'LightAgent\Core\LightAgent',
        'LightSwarm' => 'LightAgent\Core\LightSwarm',
        // Flow
        'LightFlow' => 'LightAgent\Flow\LightFlow',
        'LightFlowStep' => 'LightAgent\Flow\LightFlowStep',
        'LightFlowResult' => 'LightAgent\Flow\LightFlowResult',
        'LightFlowStepResult' => 'LightAgent\Flow\LightFlowStepResult',
        'JsonLightFlowStore' => 'LightAgent\Flow\JsonLightFlowStore',
        // DAG
        'LightDAG' => 'LightAgent\Dag\LightDAG',
        'DAGConfig' => 'LightAgent\Dag\DAGConfig',
        'DAGRun' => 'LightAgent\Dag\DAGRun',
        'DAGRunStatus' => 'LightAgent\Dag\DAGRunStatus',
        'DAGTaskStatus' => 'LightAgent\Dag\DAGTaskStatus',
        'TaskSpec' => 'LightAgent\Dag\TaskSpec',
        'TaskOutcome' => 'LightAgent\Dag\TaskOutcome',
        'TaskOutcomeKind' => 'LightAgent\Dag\TaskOutcomeKind',
        'ArtifactManifest' => 'LightAgent\Dag\ArtifactManifest',
        'VerificationReport' => 'LightAgent\Dag\VerificationReport',
        'VerificationVerdict' => 'LightAgent\Dag\VerificationVerdict',
        'DAGError' => 'LightAgent\Dag\DAGError',
        'LocalArtifactStore' => 'LightAgent\Dag\LocalArtifactStore',
        'CallableVerifier' => 'LightAgent\Dag\CallableVerifier',
        'DAGGraph' => 'LightAgent\Dag\DAGGraph',
        'LightDAGProvider' => 'LightAgent\Dag\LightDAGProvider',
        // Runtime
        'AgentRuntime' => 'LightAgent\Runtime\AgentRuntime',
        'AgentInbox' => 'LightAgent\Runtime\AgentInbox',
        'GoalManager' => 'LightAgent\Runtime\GoalManager',
        'BudgetManager' => 'LightAgent\Runtime\BudgetManager',
        'BudgetLimits' => 'LightAgent\Runtime\BudgetLimits',
        'JobManager' => 'LightAgent\Runtime\JobManager',
        'SubagentManager' => 'LightAgent\Runtime\SubagentManager',
        'ProgressTracker' => 'LightAgent\Runtime\ProgressTracker',
        // Errors
        'LightAgentError' => 'LightAgent\Errors\LightAgentError',
        // Result
        'RunResult' => 'LightAgent\Result\RunResult',
        'StreamEvent' => 'LightAgent\Result\StreamEvent',
        // Protocol
        'MemoryPolicy' => 'LightAgent\Protocol\MemoryPolicy',
        'MemoryScope' => 'LightAgent\Protocol\MemoryScope',
        'MemoryCandidate' => 'LightAgent\Protocol\MemoryCandidate',
        // Tools
        'ToolRegistry' => 'LightAgent\Tools\ToolRegistry',
        'ToolFunction' => 'LightAgent\Tools\ToolFunction',
        // Capabilities & Security
        'CapabilityRegistry' => 'LightAgent\Capabilities\CapabilityRegistry',
        'CapabilitySpec' => 'LightAgent\Capabilities\CapabilitySpec',
        'CapabilityRisk' => 'LightAgent\Capabilities\CapabilityRisk',
        'CapabilityScope' => 'LightAgent\Capabilities\CapabilityScope',
        'PermissionSet' => 'LightAgent\Capabilities\PermissionSet',
        'PolicyEngine' => 'LightAgent\Capabilities\PolicyEngine',
        'SecurityContext' => 'LightAgent\Security\SecurityContext',
        'ApprovalToken' => 'LightAgent\Security\ApprovalToken',
        'CapabilityGate' => 'LightAgent\Security\CapabilityGate',
        // Review
        'ApprovalRequest' => 'LightAgent\Review\ApprovalRequest',
        'ApprovalDecision' => 'LightAgent\Review\ApprovalDecision',
        'HumanApprovalHook' => 'LightAgent\Review\HumanApprovalHook',
        'InMemoryReviewStore' => 'LightAgent\Review\InMemoryReviewStore',
        // Hooks & Guardrails
        'HookDecision' => 'LightAgent\Hooks\HookDecision',
        'HookContext' => 'LightAgent\Hooks\HookContext',
        'HookManager' => 'LightAgent\Hooks\HookManager',
        'PolicyHook' => 'LightAgent\Hooks\PolicyHook',
        'GuardrailManager' => 'LightAgent\Guardrails\GuardrailManager',
        // Session
        'Session' => 'LightAgent\Session\Session',
        'SessionStore' => 'LightAgent\Session\SessionStore',
        'InMemorySessionStore' => 'LightAgent\Session\InMemorySessionStore',
        'JsonlSessionStore' => 'LightAgent\Session\JsonlSessionStore',
        'SqliteSessionStore' => 'LightAgent\Session\SqliteSessionStore',
        'ContextProjector' => 'LightAgent\Session\ContextProjector',
        'ContextBudget' => 'LightAgent\Session\ContextBudget',
        'ContextCompactor' => 'LightAgent\Session\ContextCompactor',
        // Skills
        'SkillManager' => 'LightAgent\Skills\SkillManager',
        'Skill' => 'LightAgent\Skills\Skill',
        // Memory
        'SharedMemoryPool' => 'LightAgent\Memory\SharedMemoryPool',
        // Knowledge
        'SqliteFtsRetrievalProvider' => 'LightAgent\Knowledge\SqliteFtsRetrievalProvider',
        'RetrievalDocument' => 'LightAgent\Knowledge\RetrievalDocument',
        // Evaluation
        'LightEvaluator' => 'LightAgent\Evaluation\LightEvaluator',
        'EvaluationCase' => 'LightAgent\Evaluation\EvaluationCase',
        // Tracing
        'TraceRecorder' => 'LightAgent\Tracing\TraceRecorder',
        'JsonlTraceExporter' => 'LightAgent\Tracing\JsonlTraceExporter',
        // Async
        'CancellationToken' => 'LightAgent\Async\CancellationToken',
        'AsyncRuntime' => 'LightAgent\Async\AsyncRuntime',
        // LLM
        'OpenAiClient' => 'LightAgent\Llm\OpenAiClient',
        'MCPClientManager' => 'LightAgent\Mcp\MCPClientManager',
    ];

    /** @var bool Whether aliases have been registered. */
    private static bool $registered = false;

    /**
     * Register short-name aliases for all public framework classes.
     * Idempotent; called automatically on first missing-class lookup.
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        foreach (self::ALIASES as $short => $fqcn) {
            if (!class_exists($short, false) && class_exists($fqcn, true)) {
                class_alias($fqcn, $short);
            }
        }
    }

    /**
     * Autoload hook: register aliases on any missing top-level class so
     * `new LightAgent()` works without calling register() explicitly.
     */
    public static function autoloadAlias(string $class): void
    {
        if (!str_contains($class, '\\') && isset(self::ALIASES[$class])) {
            self::register();
        }
    }
}

// Register the alias fallback at the end of the autoload chain so that
// unqualified short names resolve automatically.
spl_autoload_register([LightAgent::class, 'autoloadAlias'], true, false);
