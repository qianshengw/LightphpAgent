<?php

declare(strict_types=1);

namespace LightAgent\Session;

use LightAgent\Support\Json;
/**
 * Ordered migrations for persisted Session payloads and events
 * (port of session.py SessionMigrationRegistry).
 */
final class SessionMigrationRegistry
{
    /** @var array<int, \Closure(array<string, mixed>): array<string, mixed>> */
    private array $sessionMigrations = [];

    /** @var array<int, \Closure(array<string, mixed>): array<string, mixed>> */
    private array $eventMigrations = [];

    public function registerSession(int $fromVersion, \Closure $migration): void
    {
        $this->sessionMigrations[$fromVersion] = $migration;
    }

    public function registerEvent(int $fromVersion, \Closure $migration): void
    {
        $this->eventMigrations[$fromVersion] = $migration;
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    public function migrate(array $value): array
    {
        $payload = Json::decode($value);
        $version = (int) ($payload['schema_version'] ?? 1);
        if ($version > SessionConstants::SCHEMA_VERSION) {
            throw new \InvalidArgumentException(
                'unsupported Session schema_version=' . $version
                . '; maximum supported=' . SessionConstants::SCHEMA_VERSION,
            );
        }
        while ($version < SessionConstants::SCHEMA_VERSION) {
            $migration = $this->sessionMigrations[$version] ?? null;
            if ($migration === null) {
                throw new \InvalidArgumentException("missing Session migration from schema_version={$version}");
            }
            $payload = $migration($payload);
            $version = (int) ($payload['schema_version'] ?? $version + 1);
        }
        $events = [];
        foreach ($payload['events'] ?? [] as $event) {
            $events[] = $this->migrateEvent(is_array($event) ? $event : []);
        }
        $payload['events'] = $events;
        return $payload;
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    public function migrateEvent(array $value): array
    {
        $payload = $value;
        $version = (int) ($payload['schema_version'] ?? 1);
        if ($version > SessionConstants::SCHEMA_VERSION) {
            throw new \InvalidArgumentException(
                'unsupported SessionEvent schema_version=' . $version
                . '; maximum supported=' . SessionConstants::SCHEMA_VERSION,
            );
        }
        while ($version < SessionConstants::SCHEMA_VERSION) {
            $migration = $this->eventMigrations[$version] ?? null;
            if ($migration === null) {
                throw new \InvalidArgumentException("missing SessionEvent migration from schema_version={$version}");
            }
            $payload = $migration($payload);
            $version = (int) ($payload['schema_version'] ?? $version + 1);
        }
        return $payload;
    }
}
