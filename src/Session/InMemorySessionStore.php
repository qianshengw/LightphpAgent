<?php

declare(strict_types=1);

namespace LightAgent\Session;

/**
 * In-memory SessionStore (session.py InMemorySessionStore).
 * PHP is single-threaded; the Python RLock maps to no-op here.
 */
final class InMemorySessionStore implements SessionStore
{
    /** @var array<string, Session> */
    private array $sessions = [];

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function create(?Session $session = null, ?array $metadata = null): Session
    {
        $value = $session ?? new Session(metadata: $metadata ?? []);
        if (isset($this->sessions[$value->sessionId])) {
            throw new \InvalidArgumentException("session already exists: {$value->sessionId}");
        }
        $this->sessions[$value->sessionId] = $this->cloneSession($value);
        return $this->cloneSession($value);
    }

    public function get(string $sessionId): ?Session
    {
        $value = $this->sessions[$sessionId] ?? null;
        return $value !== null ? $this->cloneSession($value) : null;
    }

    public function save(Session $session): void
    {
        $session->validate();
        $current = $this->sessions[$session->sessionId] ?? null;
        if (
            $current !== null && $current->events !== [] && $session->events !== []
            && end($session->events)->sequence < end($current->events)->sequence
        ) {
            throw new \InvalidArgumentException('refusing to replace a Session with an older event sequence');
        }
        $this->sessions[$session->sessionId] = $this->cloneSession($session);
    }

    public function list(int $limit = 100): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('limit must be at least 1');
        }
        $values = array_values($this->sessions);
        usort($values, static fn (Session $a, Session $b): int => strcmp($b->updatedAt, $a->updatedAt));
        return array_map([$this, 'cloneSession'], array_slice($values, 0, $limit));
    }

    public function delete(string $sessionId): bool
    {
        if (!isset($this->sessions[$sessionId])) {
            return false;
        }
        unset($this->sessions[$sessionId]);
        return true;
    }

    private function cloneSession(Session $session): Session
    {
        // Sessions are value-like: rebuild from dict for deepcopy semantics.
        return Session::fromDict($session->toDict());
    }
}
