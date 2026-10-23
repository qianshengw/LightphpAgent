<?php

declare(strict_types=1);

namespace LightAgent\Hooks;

use LightAgent\Async\AsyncTimeoutException;
use LightAgent\Support\BooleanValue;

/**
 * Run hooks in list order while isolating observability hook failures
 * (port of hooks.py HookManager).
 *
 * Porting notes vs Python:
 *  - Awaitable resolution (asyncio.run/ThreadPoolExecutor) has no PHP
 *    equivalent need: PHP hooks are plain callables.
 *  - PolicyHook timeouts are enforced via AsyncRuntime::waitFor(), which
 *    requires a running event loop; timeout hooks inside Async::run() get
 *    real timeout semantics, sync-only contexts fail fast instead.
 *  - Method-style hooks: if the handler object exposes a method named after
 *    the phase, it is called with the context (Python getattr(hook, phase)).
 */
final class HookManager
{
    /**
     * The phases where a hook failure must BLOCK.
     *
     * A plain (non-PolicyHook) hook used to fail OPEN: the exception was
     * swallowed and the action continued. A `HumanApprovalHook` registered
     * as a bare callable therefore let the TOOL CALL PROCEED WITH NO
     * APPROVAL whenever its store write failed (M-29 / V4-05).
     *
     * The phases below guard a security decision, so a broken hook there
     * has to block. The other phases keep the historical "continue"
     * behaviour, which is what an observability hook wants.
     */
    private const FAIL_CLOSED_PHASES = [
        'before_run',
        'before_model_request',
        'after_model_response',
        'before_tool_call',
    ];

    /** @var list<callable|PolicyHook|object> */
    public readonly array $hooks;

    /**
     * @param list<callable|PolicyHook|object>|null $hooks
     */
    public function __construct(?array $hooks = null)
    {
        $this->hooks = $hooks ?? [];
    }

    public function run(HookContext $context): HookDecision
    {
        $payload = $context->payload;
        /** @var list<array<string, mixed>> $hookEvents */
        $hookEvents = [];
        /** @var array<string, mixed> $metadata */
        $metadata = [];

        foreach ($this->hooks as $hook) {
            $policy = $hook instanceof PolicyHook ? $hook : null;
            $handler = $policy !== null ? $policy->handler : $hook;

            if ($policy !== null && $policy->phases !== null && !in_array($context->phase, $policy->phases, true)) {
                continue;
            }

            $hookName = $policy?->name ?? self::describeHandler($handler);

            try {
                $raw = $this->invokeHook($handler, $context, $policy?->timeout);
                $decision = $this->normalize($raw);
            } catch (\Throwable $exc) {
                // A plain hook fails CLOSED in the security-sensitive
                // phases: swallowing the error there would let a tool
                // call proceed without the approval it was waiting for
                // (M-29). A PolicyHook keeps its own, explicit failure
                // mode.
                $failureMode = $policy !== null
                    ? $policy->failureMode
                    : (in_array($context->phase, self::FAIL_CLOSED_PHASES, true) ? 'block' : 'continue');
                $hookEvents[] = [
                    'phase' => $context->phase,
                    'hook' => $hookName,
                    'action' => 'error',
                    'error' => $exc->getMessage(),
                    'error_type' => $exc instanceof AsyncTimeoutException ? 'timeout' : 'exception',
                    'failure_mode' => $failureMode,
                ];
                if ($failureMode === 'block') {
                    return HookDecision::block(
                        "Policy hook `{$hookName}` failed closed: {$exc->getMessage()}",
                        metadata: array_merge($metadata, [
                            'policy_hook' => $hookName,
                            'failure_mode' => $failureMode,
                            'hook_events' => $hookEvents,
                        ]),
                    );
                }
                continue;
            }

            if ($decision->metadata !== []) {
                foreach ($decision->metadata as $k => $v) {
                    $metadata[$k] = $v;
                }
            }

            if ($decision->action === HookAction::REPLACE) {
                $payload = $decision->payload ?? [];
                $context->payload = $payload;
                $hookEvents[] = [
                    'phase' => $context->phase,
                    'hook' => $hookName,
                    'action' => HookAction::REPLACE,
                ];
                continue;
            }

            if ($decision->action === HookAction::METADATA) {
                $hookEvents[] = [
                    'phase' => $context->phase,
                    'hook' => $hookName,
                    'action' => HookAction::METADATA,
                ];
                continue;
            }

            if ($decision->action !== HookAction::CONTINUE) {
                $decision->payload = $payload;
                $decision->metadata = array_merge($metadata, $decision->metadata, ['hook_events' => $hookEvents]);
                $hookEvents[] = [
                    'phase' => $context->phase,
                    'hook' => $hookName,
                    'action' => $decision->action,
                    'reason' => $decision->reason,
                ];
                $decision->metadata['hook_events'] = $hookEvents;
                return $decision;
            }
        }

        return new HookDecision(
            action: HookAction::CONTINUE,
            payload: $payload,
            metadata: $hookEvents !== [] ? array_merge($metadata, ['hook_events' => $hookEvents]) : $metadata,
        );
    }

    /**
     * Invoke a handler: method named after the phase wins, else the
     * callable itself receives the context.
     *
     * TIMEOUT SEMANTICS (M-30): a handler that YIELDS (an awaited I/O
     * operation, a sleep) is bounded by the event loop — `waitFor()`
     * cancels it at the deadline and raises a timeout. A handler that
     * BLOCKS (a synchronous sleep, a long CPU loop) CANNOT be preempted
     * in PHP: the fiber never yields, so the cancellation can only be
     * delivered after the handler returned. The overrun is therefore
     * detected here and reported as the configured failure mode. The
     * deadline is enforced in both cases, but a blocking handler is never
     * interrupted — that would need a second thread, which the single
     * threaded runtime does not have.
     */
    private function invokeHook(mixed $handler, HookContext $context, ?float $timeout): mixed
    {
        $call = self::resolveCall($handler, $context);

        if ($timeout === null) {
            return $call();
        }
        $started = microtime(true);
        $result = \LightAgent\Async\AsyncRuntime::waitFor(
            static fn (): mixed => $call(),
            $timeout,
        );
        $elapsed = microtime(true) - $started;
        if ($elapsed > $timeout) {
            throw new AsyncTimeoutException(
                'hook timed out after ' . sprintf('%g', $timeout) . 's (elapsed ' . sprintf('%.3f', $elapsed) . 's)',
            );
        }
        return $result;
    }

    /**
     * @return \Closure():mixed
     */
    private static function resolveCall(mixed $handler, HookContext $context): \Closure
    {
        if (is_object($handler) && !($handler instanceof \Closure)) {
            $method = $context->phase;
            if (method_exists($handler, $method)) {
                return static fn (): mixed => $handler->$method($context);
            }
            if (is_callable($handler)) {
                return static fn (): mixed => $handler($context);
            }
            throw new \TypeError('hook handler is not callable: ' . $handler::class);
        }
        if (is_callable($handler)) {
            return static fn (): mixed => $handler($context);
        }
        throw new \TypeError('hook handler is not callable');
    }

    private static function normalize(mixed $raw): HookDecision
    {
        if ($raw === null) {
            return HookDecision::cont();
        }
        if ($raw instanceof HookDecision) {
            return $raw;
        }
        if (is_bool($raw)) {
            return $raw ? HookDecision::cont() : HookDecision::block('hook returned False');
        }
        if (is_string($raw)) {
            return HookDecision::block($raw);
        }
        if (is_array($raw)) {
            // Strict boolean parsing (V4-05): a hook that answers with a
            // JSON-decoded payload spells the verdict as the STRING
            // 'false', and `(bool) 'false'` is TRUE in PHP — a "deny"
            // became an "allow". Parse it the way the guardrail path
            // already does. A *missing* 'allowed' key still means "no
            // opinion" -> allow.
            if (array_key_exists('allowed', $raw) && !BooleanValue::parse($raw['allowed'])) {
                return HookDecision::block(
                    isset($raw['reason']) ? (string) $raw['reason'] : null,
                    metadata: is_array($raw['metadata'] ?? null) ? $raw['metadata'] : [],
                );
            }
            $action = $raw['action'] ?? null;
            if ($action !== null) {
                return new HookDecision(
                    action: (string) $action,
                    payload: is_array($raw['payload'] ?? null) ? $raw['payload'] : null,
                    reason: isset($raw['reason']) ? (string) $raw['reason'] : null,
                    metadata: is_array($raw['metadata'] ?? null) ? $raw['metadata'] : [],
                );
            }
            if (array_key_exists('payload', $raw)) {
                return HookDecision::replace(
                    is_array($raw['payload']) ? $raw['payload'] : [],
                    metadata: is_array($raw['metadata'] ?? null) ? $raw['metadata'] : null,
                );
            }
            return HookDecision::cont(metadata: is_array($raw['metadata'] ?? null) ? $raw['metadata'] : null);
        }
        return HookDecision::cont();
    }

    private static function describeHandler(mixed $handler): string
    {
        if (is_object($handler)) {
            if ($handler instanceof \Closure) {
                try {
                    $ref = new \ReflectionFunction($handler);
                    return $ref->getName();
                } catch (\ReflectionException) {
                    return '{closure}';
                }
            }
            return $handler::class;
        }
        if (is_string($handler)) {
            return $handler;
        }
        if (is_array($handler) && count($handler) === 2) {
            $class = is_object($handler[0]) ? $handler[0]::class : (string) $handler[0];
            return $class . '::' . $handler[1];
        }
        return 'callable';
    }
}
