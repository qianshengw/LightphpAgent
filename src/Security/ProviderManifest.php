<?php

declare(strict_types=1);

namespace LightAgent\Security;

use LightAgent\Capabilities\BaseCapabilityProvider;

/**
 * Serializable Provider identity without raw configuration secrets
 * (security.py ProviderManifest, frozen dataclass).
 */
final class ProviderManifest
{
    /**
     * @param list<string> $capabilities
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public array $capabilities,
        public readonly string $configurationDigest,
        public readonly string $capabilityDigest,
        public readonly int $schemaVersion = SecurityUtils::PROVIDER_MANIFEST_SCHEMA_VERSION,
        public array $metadata = [],
    ) {
        sort($this->capabilities);
    }

    /**
     * @param mixed $provider object with name/version/capabilities/config
     */
    public static function fromProvider(mixed $provider): self
    {
        $capabilities = array_keys($provider->capabilities());
        $safeConfig = SecurityUtils::secretSafe($provider->config ?? []);
        $providerCapabilities = $provider->capabilities();
        $capabilityPayload = [];
        foreach ($capabilities as $name) {
            $capabilityPayload[$name] = $providerCapabilities[$name]->toDict();
        }
        return new self(
            name: (string) $provider->name,
            version: (string) $provider->version,
            capabilities: array_map('strval', $capabilities),
            configurationDigest: SecurityUtils::canonicalDigest($safeConfig),
            capabilityDigest: SecurityUtils::canonicalDigest($capabilityPayload),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'capabilities' => $this->capabilities,
            'configuration_digest' => $this->configurationDigest,
            'capability_digest' => $this->capabilityDigest,
            'schema_version' => $this->schemaVersion,
            'metadata' => $this->metadata,
        ];
    }
}

