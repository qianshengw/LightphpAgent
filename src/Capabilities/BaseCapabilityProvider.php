<?php

declare(strict_types=1);

namespace LightAgent\Capabilities;

use LightAgent\Support\Json;
/**
 * Shared provider lifecycle (capabilities.py BaseCapabilityProvider).
 */
abstract class BaseCapabilityProvider implements CapabilityProvider
{
    public string $name = 'provider';
    public string $version = '1';

    /**
     * @see CapabilityProvider::getName()
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @see CapabilityProvider::getVersion()
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /** @var array<string, CapabilitySpec> */
    protected array $capabilitySpecs = [];
    public ?RuntimeContext $context = null;

    /**
     * The context of the invocation that is about to run.
     *
     * `context` is bound by `mount()` and belongs to the provider's
     * LIFECYCLE; `invocationContext` is bound by `bindContext()` and
     * belongs to a single call. The two are kept apart on purpose: a
     * capability invocation is not a lifecycle event, and the registry
     * must not make a provider look mounted just because it was called.
     *
     * A provider that has to know WHO is calling — the memory adapter
     * takes the caller's identity from the context and never from the
     * model-supplied arguments (M-40) — reads it from here.
     */
    public ?RuntimeContext $invocationContext = null;
    /** @var array<string, mixed> */
    public array $config = [];
    public bool $mounted = false;
    public bool $started = false;

    /**
     * @param list<CapabilitySpec>|null $capabilities
     */
    public function __construct(?array $capabilities = null)
    {
        foreach ($capabilities ?? [] as $spec) {
            $this->capabilitySpecs[$spec->name] = $spec;
        }
    }

    /** @return array<string, CapabilitySpec> */
    public function capabilities(): array
    {
        return $this->capabilitySpecs;
    }

    public function mount(RuntimeContext $context): void
    {
        $this->context = $context;
        $this->mounted = true;
    }

    /**
     * Bind the invocation context WITHOUT touching the lifecycle state.
     *
     * `mount()` is part of the provider lifecycle and flips the `mounted`
     * flag; a capability invocation is not a lifecycle event. A provider
     * that has to know WHO is calling (the memory adapter takes the
     * caller's identity from the context, never from the model-supplied
     * arguments — M-40) needs the context at INVOKE time, so the registry
     * binds it right before the provider runs.
     */
    public function bindContext(RuntimeContext $context): void
    {
        $this->invocationContext = $context;
    }

    public function start(): void
    {
        if (!$this->mounted) {
            throw new \RuntimeException("provider `{$this->name}` must be mounted before start");
        }
        $this->started = true;
    }

    public function health(): ProviderHealth
    {
        return new ProviderHealth(healthy: $this->started, status: $this->started ? 'ready' : 'stopped');
    }

    public function reload(array $config): void
    {
        $this->config = Json::decode($config) ?? [];
    }

    public function stop(): void
    {
        $this->started = false;
    }

    public function unmount(): void
    {
        if ($this->started) {
            $this->stop();
        }
        $this->context = null;
        $this->mounted = false;
    }
}
