<?php

declare(strict_types=1);

namespace LightAgent\Capabilities;

use LightAgent\Support\Json;
use LightAgent\Support\BooleanValue;

/**
 * Ordered fail-closed policy evaluation for capability execution
 * (capabilities.py PolicyEngine).
 *
 * PORTING-DIFF: Python awaits awaitable policy results; PHP policies are
 * plain callables.
 */
final class PolicyEngine
{
    /** @var list<callable> */
    public array $policies = [];

    /**
     * @param list<callable>|null $policies fn(PolicyRequest): PolicyDecision|bool|array|null
     */
    public function __construct(?array $policies = null, public readonly bool $failClosed = true)
    {
        $this->policies = $policies ?? [];
    }

    public function add(callable $policy): void
    {
        $this->policies[] = $policy;
    }

    public function evaluate(PolicyRequest $request): PolicyDecision
    {
        $permissions = $request->context->permissions;
        if ($permissions !== null && !$permissions->allows($request->capability->name, $request->capability->risk)) {
            return PolicyDecision::block(
                "capability `{$request->capability->name}` is outside the permission snapshot",
            );
        }
        if ($request->capability->requiresApproval) {
            return PolicyDecision::approval("capability `{$request->capability->name}` requires approval");
        }

        $arguments = Json::decode($request->arguments) ?? [];
        $collectedMetadata = [];
        foreach ($this->policies as $policy) {
            try {
                $result = $policy(new PolicyRequest(
                    capability: $request->capability,
                    providerName: $request->providerName,
                    arguments: Json::decode($arguments) ?? [],
                    context: $request->context,
                ));
                $decision = $this->coerce($result, $arguments);
            } catch (\Throwable $error) {
                if ($this->failClosed) {
                    $name = is_object($policy) && !$policy instanceof \Closure
                        ? $policy::class
                        : (is_string($policy) ? $policy : '{closure}');
                    return PolicyDecision::block(
                        "policy `{$name}` failed: " . $error::class,
                    );
                }
                $collectedMetadata['policy_errors'][] = $error::class;
                continue;
            }
            $collectedMetadata = array_merge($collectedMetadata, $decision->metadata);
            if (!$decision->allowed) {
                $decision->metadata = array_merge($collectedMetadata, $decision->metadata);
                return $decision;
            }
            if ($decision->arguments !== null) {
                $arguments = Json::decode($decision->arguments) ?? [];
            }
        }
        return PolicyDecision::allow($arguments, metadata: $collectedMetadata);
    }

    public function evaluateSync(PolicyRequest $request): PolicyDecision
    {
        return $this->evaluate($request);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function coerce(mixed $value, array $arguments): PolicyDecision
    {
        if ($value === null || $value === true) {
            return PolicyDecision::allow($arguments);
        }
        if ($value === false) {
            return PolicyDecision::block('policy denied the capability');
        }
        if ($value instanceof PolicyDecision) {
            return $value;
        }
        if (is_array($value)) {
            if (!empty($value['requires_approval'])) {
                return PolicyDecision::approval(
                    isset($value['reason']) ? (string) $value['reason'] : null,
                    metadata: is_array($value['metadata'] ?? null) ? $value['metadata'] : [],
                );
            }
            return new PolicyDecision(
                // Strict boolean parsing: `(bool) 'false'` is TRUE in PHP, so a
                // policy answering with a JSON-decoded payload would turn its
                // own "deny" into an "allow" (T6). A *missing* 'allowed' key
                // still means "no opinion" -> allow.
                allowed: array_key_exists('allowed', $value)
                    ? BooleanValue::parse($value['allowed'])
                    : true,
                reason: isset($value['reason']) ? (string) $value['reason'] : null,
                arguments: is_array($value['arguments'] ?? null) ? $value['arguments'] : $arguments,
                metadata: is_array($value['metadata'] ?? null) ? $value['metadata'] : [],
            );
        }
        throw new \TypeError('policy must return PolicyDecision, bool, array, or null');
    }
}
