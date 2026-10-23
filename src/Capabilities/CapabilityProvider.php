<?php

declare(strict_types=1);

namespace LightAgent\Capabilities;

interface CapabilityProvider
{
    /**
     * The provider's name.
     *
     * NOTE: the contract is expressed as a getter and NOT as the property
     * hook spelling (`public string $name { get; }`): property hooks are a
     * PHP 8.4 feature, while the project supports PHP >= 8.1. Every
     * provider still exposes the public `$name` / `$version` properties
     * that the runtime reads.
     */
    public function getName(): string;

    /**
     * The provider's version. See `getName()` for the PHP 8.1 note.
     */
    public function getVersion(): string;

    /** @return array<string, CapabilitySpec> */
    public function capabilities(): array;

    public function mount(RuntimeContext $context): void;

    public function start(): void;

    public function health(): ProviderHealth;

    /**
     * @param array<string, mixed> $config
     */
    public function reload(array $config): void;

    public function stop(): void;

    public function unmount(): void;
}

