<?php

declare(strict_types=1);

namespace LightAgent\Security;

use LightAgent\Support\Json;
use LightAgent\Capabilities\CapabilityRegistry;
use LightAgent\Capabilities\CapabilitySpec;
use LightAgent\Capabilities\PolicyDecision;
use LightAgent\Capabilities\PolicyEngine;
use LightAgent\Capabilities\PolicyRequest;
use LightAgent\Capabilities\ProviderInvoker;

/**
 * Apply identity, permission, policy, approval, and deadline checks
 * (port of security.py CapabilityGate).
 */
final class CapabilityGate
{
    /** @var (callable(string, array<string, mixed>): mixed)|null */
    public $audit = null;
    public ?PolicyEngine $policyEngine = null;

    public function __construct(
        public readonly ?CapabilityRegistry $registry = null,
        ?PolicyEngine $policyEngine = null,
        ?callable $audit = null,
    ) {
        $this->audit = $audit;
        $this->policyEngine = $policyEngine ?? ($this->registry !== null ? $this->registry->policyEngine : new PolicyEngine());
    }

    /**
     * Apply identity, permission, policy, approval, and deadline checks
     * (port of security.py CapabilityGate).
     *
     * Every denial is a `PermissionError`, the PHP analogue of the
     * Python original's builtin `PermissionError` (README §13). It stays
     * a `\RuntimeException` subclass, so existing handlers keep working.
     *
     * @param array<string, mixed>|null $arguments
     */
    public function authorize(
        CapabilitySpec $capability,
        string $providerName,
        ?array $arguments,
        SecurityContext $context,
        ?ApprovalToken $approvalToken = null,
        ?string $resource = null,
    ): PolicyDecision {
        if ($context->isExpired()) {
            throw new PermissionError('security context deadline has expired');
        }
        if ($resource !== null && (
            ($context->resources !== [] && !in_array($resource, $context->resources, true))
            // An explicitly emptied resource boundary admits nothing (H3).
            || ($context->resources === [] && $context->isResourceRestricted())
        )) {
            throw new PermissionError("resource `{$resource}` is outside the security context");
        }
        if ($capability->network && !$context->networkAllowed) {
            throw new PermissionError("capability `{$capability->name}` requires network access");
        }
        if ($capability->requiresSandbox && !$context->sandboxRequired) {
            throw new PermissionError("capability `{$capability->name}` requires a sandbox-bound context");
        }

        $requestedArguments = Json::decode($arguments ?? []) ?? [];
        $policyCapability = $capability;
        if ($capability->requiresApproval && $approvalToken !== null) {
            // Recreate the spec without the approval flag so that the
            // policy chain actually runs. The approval itself is verified
            // BELOW — against the arguments that will really be executed.
            $policyCapability = new CapabilitySpec(
                name: $capability->name,
                description: $capability->description,
                risk: $capability->risk,
                read: $capability->read,
                write: $capability->write,
                network: $capability->network,
                execute: $capability->execute,
                persistent: $capability->persistent,
                cancellable: $capability->cancellable,
                resumable: $capability->resumable,
                timeout: $capability->timeout,
                outputLimit: $capability->outputLimit,
                requiresSandbox: $capability->requiresSandbox,
                requiresApproval: false,
                uiType: $capability->uiType,
                metadata: $capability->metadata,
            );
        }
        $decision = ($this->policyEngine ?? new PolicyEngine())->evaluate(new PolicyRequest(
            capability: $policyCapability,
            providerName: $providerName,
            arguments: $requestedArguments,
            context: $context->toRuntimeContext(),
        ));
        $this->doAudit('capability.gate', [
            'capability' => $capability->name,
            'provider' => $providerName,
            'allowed' => $decision->allowed,
            'requires_approval' => $decision->requiresApproval,
            'reason' => $decision->reason,
            'run_id' => $context->runId,
            'task_id' => $context->taskId,
            'attempt_id' => $context->attemptId,
            'policy_version' => $context->policyVersion,
            'arguments_digest' => SecurityUtils::canonicalDigest($requestedArguments),
        ]);
        if (!$decision->allowed) {
            $state = $decision->requiresApproval ? 'requires approval' : 'was denied';
            throw new PermissionError(
                "capability `{$capability->name}` {$state}: " . ($decision->reason ?? 'policy decision'),
            );
        }
        // The approval is verified against the FINAL arguments — the ones
        // the provider will really receive. A policy may rewrite them, and
        // an approval that covered the pre-rewrite arguments would then
        // authorise something the reviewer never saw (M-42).
        //
        // Verifying here, after the policy chain and only on the path that
        // executes, also means a policy rejection no longer CONSUMES the
        // user's approval: the token is still usable once the policy
        // allows the call.
        if ($capability->requiresApproval) {
            if ($approvalToken === null) {
                throw new PermissionError(
                    "capability `{$capability->name}` requires approval: no approval token was supplied",
                );
            }
            $finalArguments = $decision->arguments ?? $requestedArguments;
            $approvalToken->verify($capability->name, $finalArguments, $context, resource: $resource);
        }
        return $decision;
    }

    /**
     * @param array<string, mixed>|null $arguments
     */
    public function invoke(
        string $capabilityName,
        ?array $arguments,
        SecurityContext $context,
        ?ApprovalToken $approvalToken = null,
        ?string $resource = null,
    ): mixed {
        if ($this->registry === null) {
            throw new \RuntimeException('CapabilityGate.invoke requires a CapabilityRegistry');
        }
        $runtimeContext = $context->toRuntimeContext();
        $provider = $this->registry->resolve($capabilityName, $runtimeContext);
        $capability = $provider->capabilities()[$capabilityName];
        $decision = $this->authorize(
            $capability,
            $provider->name,
            $arguments,
            $context,
            approvalToken: $approvalToken,
            resource: $resource,
        );
        // The provider must know WHO is calling: the invocation context is
        // the only trustworthy source of the caller's identity (M-40).
        if (method_exists($provider, 'bindContext')) {
            $provider->bindContext($runtimeContext);
        }
        // The argument map is passed as a MAP. Spreading it into the
        // provider would turn its string keys into named arguments, and a
        // key that matches the provider's own parameter name (`capability`)
        // raises a raw PHP Error (V5-15).
        $result = ProviderInvoker::invoke($provider, $capabilityName, $decision->arguments ?? $arguments ?? []);
        if ($capability->outputLimit !== null && mb_strlen((string) $result) > $capability->outputLimit) {
            $result = mb_substr((string) $result, 0, $capability->outputLimit);
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function doAudit(string $eventType, array $data): void
    {
        if ($this->audit !== null) {
            ($this->audit)($eventType, Json::decode($data) ?? []);
        }
    }
}
