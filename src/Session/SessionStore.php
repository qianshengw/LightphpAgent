<?php

declare(strict_types=1);

namespace LightAgent\Session;

/**
 * SessionStore contract (session.py SessionStore Protocol).
 */
interface SessionStore
{
    /**
     * @param array<string, mixed>|null $metadata
     */
    public function create(?Session $session = null, ?array $metadata = null): Session;

    public function get(string $sessionId): ?Session;

    public function save(Session $session): void;

    /**
     * @return list<Session>
     */
    public function list(int $limit = 100): array;

    public function delete(string $sessionId): bool;
}
