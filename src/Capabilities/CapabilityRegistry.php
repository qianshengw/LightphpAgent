<?php

declare(strict_types=1);

namespace LightAgent\Capabilities;

use LightAgent\Support\Json;
/**
 * Provider registry with scope resolution and conflict tracking
 * (port of capabilities.py CapabilityRegistry).
 *
 * PORTING-DIFF: the async mount/stop/invoke lifecycle maps to sync calls in
 * the single-threaded runtime; timeouts via spec.timeout apply only where a
 * fiber suspension point exists (AsyncRuntime::waitFor).
 */
final class CapabilityRegistry
{
    public PolicyEngine $policyEngine;
    /** @var (callable(string, array<string, mixed>): mixed)|null */
    public $audit = null;

    /** @var list<array{provider: CapabilityProvider, scope: CapabilityScope, owner_id: ?string, order: int}> */
    private array $registrations = [];

    /** @var list<array<string, mixed>> */
    private array $conflicts = [];

    private int $counter = 0;

    private static function precedence(CapabilityScope $scope): int
    {
        return match ($scope) {
            CapabilityScope::DEFAULT => 0,
            CapabilityScope::RUNTIME => 1,
            CapabilityScope::SESSION => 2,
            CapabilityScope::AGENT => 3,
        };
    }

    public function __construct(?PolicyEngine $policyEngine = null, ?callable $audit = null)
    {
        $this->policyEngine = $policyEngine ?? new PolicyEngine();
        $this->audit = $audit;
    }

    public function register(
        CapabilityProvider $provider,
        CapabilityScope|string $scope = CapabilityScope::RUNTIME,
        ?string $ownerId = null,
    ): CapabilityProvider {
        $resolvedScope = $scope instanceof CapabilityScope ? $scope : CapabilityScope::from((string) $scope);
        if (in_array($resolvedScope, [CapabilityScope::SESSION, CapabilityScope::AGENT], true) && $ownerId === null) {
            throw new \InvalidArgumentException("owner_id is required for {$resolvedScope->value} scope");
        }
        foreach ($this->registrations as $item) {
            if ($item['provider']->name === $provider->name && $item['scope'] === $resolvedScope && $item['owner_id'] === $ownerId) {
                throw new \InvalidArgumentException(
                    "provider `{$provider->name}` is already registered for {$resolvedScope->value}:" . ($ownerId ?? '*'),
                );
            }
        }
        $this->counter++;
        foreach ($this->registrations as $existing) {
            $overlap = array_values(array_intersect(
                array_keys($existing['provider']->capabilities()),
                array_keys($provider->capabilities()),
            ));
            sort($overlap);
            if ($overlap !== [] && $existing['scope'] === $resolvedScope && $existing['owner_id'] === $ownerId) {
                $this->conflicts[] = [
                    'capabilities' => $overlap,
                    'winner' => $provider->name,
                    'shadowed' => $existing['provider']->name,
                    'scope' => $resolvedScope->value,
                    'owner_id' => $ownerId,
                    'rule' => 'latest registration wins within an equal scope',
                ];
            }
        }
        $this->registrations[] = [
            'provider' => $provider,
            'scope' => $resolvedScope,
            'owner_id' => $ownerId,
            'order' => $this->counter,
        ];
        return $provider;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function conflicts(): array
    {
        return Json::decode($this->conflicts) ?? [];
    }

    public function mount(?RuntimeContext $context = null): void
    {
        $context = $this->runtimeContext($context);
        foreach ($this->matching($context) as $item) {
            $item['provider']->mount($context);
            $item['provider']->start();
            $this->doAudit('provider.started', $item, context: $context);
        }
    }

    public function unregister(
        string $name,
        CapabilityScope|string|null $scope = null,
        ?string $ownerId = null,
    ): bool {
        $resolvedScope = $scope !== null ? ($scope instanceof CapabilityScope ? $scope : CapabilityScope::from((string) $scope)) : null;
        $matches = [];
        foreach ($this->registrations as $item) {
            if (
                $item['provider']->name === $name
                && ($resolvedScope === null || $item['scope'] === $resolvedScope)
                && ($ownerId === null || $item['owner_id'] === $ownerId)
            ) {
                $matches[] = $item;
            }
        }
        foreach (array_reverse($matches) as $item) {
            $item['provider']->stop();
            $item['provider']->unmount();
            $key = array_search($item, $this->registrations, true);
            if ($key !== false) {
                array_splice($this->registrations, $key, 1);
            }
            $this->doAudit('provider.unregistered', $item);
        }
        return $matches !== [];
    }

    public function resolve(string $capability, ?RuntimeContext $context = null): CapabilityProvider
    {
        $runtimeContext = $this->runtimeContext($context);
        $matches = [];
        foreach ($this->matching($runtimeContext) as $item) {
            if (isset($item['provider']->capabilities()[$capability])) {
                $matches[] = $item;
            }
        }
        if ($matches === []) {
            throw new \RuntimeException("no Provider registered for capability `{$capability}`");
        }
        usort($matches, function (array $a, array $b): int {
            $pa = self::precedence($a['scope']);
            $pb = self::precedence($b['scope']);
            return ($pb <=> $pa) ?: ($b['order'] <=> $a['order']);
        });
        return $matches[0]['provider'];
    }

    public function get(string $name, ?RuntimeContext $context = null): CapabilityProvider
    {
        $matches = [];
        foreach ($this->matching($this->runtimeContext($context)) as $item) {
            if ($item['provider']->name === $name) {
                $matches[] = $item;
            }
        }
        if ($matches === []) {
            throw new \RuntimeException("provider `{$name}` is not registered");
        }
        usort($matches, function (array $a, array $b): int {
            $pa = self::precedence($a['scope']);
            $pb = self::precedence($b['scope']);
            return ($pb <=> $pa) ?: ($b['order'] <=> $a['order']);
        });
        return $matches[0]['provider'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?RuntimeContext $context = null): array
    {
        $values = [];
        foreach ($this->matching($this->runtimeContext($context)) as $item) {
            $values[] = [
                'name' => $item['provider']->name,
                'version' => $item['provider']->version,
                'scope' => $item['scope']->value,
                'owner_id' => $item['owner_id'],
                'capabilities' => array_map(
                    static fn (CapabilitySpec $spec): array => $spec->toDict(),
                    array_values($item['provider']->capabilities()),
                ),
            ];
        }
        return $values;
    }

    /**
     * @return array<string, ProviderHealth>
     */
    public function health(?RuntimeContext $context = null): array
    {
        $result = [];
        foreach ($this->matching($this->runtimeContext($context)) as $item) {
            $result[$item['provider']->name] = $item['provider']->health();
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function reload(string $name, array $config, ?RuntimeContext $context = null): void
    {
        $this->get($name, $context)->reload($config);
    }

    public function stop(?RuntimeContext $context = null): void
    {
        $runtimeContext = $this->runtimeContext($context);
        foreach (array_reverse($this->matching($runtimeContext)) as $item) {
            $item['provider']->stop();
            $item['provider']->unmount();
            $this->doAudit('provider.stopped', $item, context: $runtimeContext);
        }
    }

    /**
     * Invoke a capability through the policy engine.
     *
     * @param array<string, mixed>|null $arguments
     */
    public function invoke(
        string $capability,
        ?array $arguments = null,
        ?RuntimeContext $context = null,
        mixed $approvalToken = null,
        ?string $resource = null,
    ): mixed {
        if ($context !== null && $context->securityContext !== null && !($context->securityContext instanceof RuntimeContext)) {
            // SecurityContext path delegates to CapabilityGate
            return (new \LightAgent\Security\CapabilityGate($this))->invoke(
                $capability,
                $arguments,
                $context->securityContext,
                approvalToken: $approvalToken,
                resource: $resource,
            );
        }
        $runtimeContext = $this->runtimeContext($context);
        $provider = $this->resolve($capability, $runtimeContext);
        $spec = $provider->capabilities()[$capability];
        $decision = $this->policyEngine->evaluate(new PolicyRequest(
            capability: $spec,
            providerName: $provider->name,
            arguments: $arguments ?? [],
            context: $runtimeContext,
        ));
        $this->doAudit('policy.decision', $this->registrationFor($provider), context: $runtimeContext, extra: [
            'capability' => $capability,
            'allowed' => $decision->allowed,
            'requires_approval' => $decision->requiresApproval,
            'reason' => $decision->reason,
        ]);
        if (!$decision->allowed) {
            $state = $decision->requiresApproval ? 'requires approval' : 'was denied';
            throw new \RuntimeException("capability `{$capability}` {$state}: " . ($decision->reason ?? 'policy decision'));
        }
        if (!method_exists($provider, 'invoke')) {
            throw new \LogicException("provider `{$provider->name}` does not implement invoke()");
        }
        // The provider must know WHO is calling: the invocation context is
        // the only trustworthy source of the caller's identity, so it is
        // bound before the provider runs (M-40).
        self::bindContext($provider, $runtimeContext);
        // The argument map is passed as a MAP. Spreading it into the
        // provider would turn its string keys into named arguments, and a
        // key that matches the provider's own parameter name (`capability`)
        // raises a raw PHP Error (V5-15).
        $result = ProviderInvoker::invoke($provider, $capability, $decision->arguments ?? $arguments ?? []);
        if ($spec->outputLimit !== null && mb_strlen((string) $result) > $spec->outputLimit) {
            $result = mb_substr((string) $result, 0, $spec->outputLimit);
        }
        return $result;
    }

    /**
     * Bind the invocation context to the provider.
     *
     * A provider that needs to know WHO is calling (the memory adapter
     * derives the caller's identity from it) must not read the identity
     * from the model-supplied arguments (M-40). The context is bound per
     * invocation, without touching the provider's lifecycle state.
     */
    private static function bindContext(CapabilityProvider $provider, RuntimeContext $context): void
    {
        if (method_exists($provider, 'bindContext')) {
            $provider->bindContext($context);
        }
    }

    private function runtimeContext(mixed $context = null): RuntimeContext
    {
        if ($context === null) {
            return new RuntimeContext();
        }
        if ($context instanceof RuntimeContext) {
            return $context;
        }
        if (is_object($context) && method_exists($context, 'toRuntimeContext')) {
            return $context->toRuntimeContext();
        }
        throw new \TypeError('context must be RuntimeContext or SecurityContext');
    }

    /**
     * @return list<array{provider: CapabilityProvider, scope: CapabilityScope, owner_id: ?string, order: int}>
     */
    private function matching(RuntimeContext $context): array
    {
        $out = [];
        foreach ($this->registrations as $item) {
            if (
                in_array($item['scope'], [CapabilityScope::DEFAULT, CapabilityScope::RUNTIME], true)
                || ($item['scope'] === CapabilityScope::SESSION && $item['owner_id'] === $context->sessionId)
                || ($item['scope'] === CapabilityScope::AGENT && $item['owner_id'] === $context->agentId)
            ) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * @param array{provider: CapabilityProvider, scope: CapabilityScope, owner_id: ?string, order: int} $item
     * @return array{provider: CapabilityProvider, scope: CapabilityScope, owner_id: ?string, order: int}
     */
    private function registrationFor(CapabilityProvider $provider): array
    {
        foreach ($this->registrations as $item) {
            if ($item['provider'] === $provider) {
                return $item;
            }
        }
        throw new \RuntimeException('provider not registered');
    }

    /**
     * @param array{provider: CapabilityProvider, scope: CapabilityScope, owner_id: ?string, order: int} $item
     * @param array<string, mixed>|null $extra
     */
    private function doAudit(string $eventType, array $item, ?RuntimeContext $context = null, ?array $extra = null): void
    {
        if ($this->audit === null) {
            return;
        }
        ($this->audit)($eventType, array_merge([
            'provider' => $item['provider']->name,
            'provider_version' => $item['provider']->version,
            'scope' => $item['scope']->value,
            'owner_id' => $item['owner_id'],
            'session_id' => $context?->sessionId,
            'agent_id' => $context?->agentId,
            'configuration_digest' => $this->configurationDigest($item['provider']),
        ], $extra ?? []));
    }

    /**
     * @return array<string, mixed>
     */
    private static function configurationDigest(CapabilityProvider $provider): array|string
    {
        $config = [];
        if (property_exists($provider, 'config')) {
            $ref = new \ReflectionProperty($provider, 'config');
            $config = $ref->isInitialized($provider) ? $provider->config : [];
        }
        $safe = [];
        foreach ((array) $config as $key => $value) {
            $name = strtolower((string) $key);
            $safe[(string) $key] = self::strContainsAny($name, ['key', 'token', 'secret', 'password'])
                ? '[redacted]'
                : $value;
        }
        $rendered = Json::encode($safe, JSON_UNESCAPED_SLASHES);
        if ($rendered === '') {
            // The config cannot be represented as JSON (INF/NAN). Falling
            // back to a fixed literal would give EVERY such provider the same
            // digest, so two different configs would be indistinguishable
            // (T5b). var_export() still distinguishes them.
            $rendered = 'unencodable:' . var_export($safe, true);
        }
        return hash('sha256', $rendered);
    }

    private static function strContainsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }
}
