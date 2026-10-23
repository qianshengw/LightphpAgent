<?php

declare(strict_types=1);

namespace LightAgent\Knowledge;

use LightAgent\Capabilities\BaseCapabilityProvider;
use LightAgent\Capabilities\CapabilityRisk;
use LightAgent\Capabilities\CapabilitySpec;
use LightAgent\Session\SessionStore;
use LightAgent\Skills\SkillManager;
use LightAgent\Support\Json;

/**
 * Capability adapters over existing primitives (knowledge.py tail).
 *
 * SessionSearchProvider / SkillProviderAdapter / MCPProviderAdapter /
 * WorkflowProviderAdapter.
 */
final class SessionSearchProvider extends BaseCapabilityProvider
{
    public function __construct(private readonly SessionStore $store)
    {
        $this->name = 'session-search';
        $this->version = '1';
        parent::__construct([new CapabilitySpec('session.search', read: true)]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit = 20): array
    {
        if (trim($query) === '') {
            return [];
        }
        $needle = mb_strtolower($query);
        $matches = [];
        foreach ($this->store->list(max($limit, 100)) as $session) {
            foreach (array_reverse($session->events) as $event) {
                $rendered = mb_strtolower(Json::encode($event->data, JSON_UNESCAPED_UNICODE));
                if (!str_contains($rendered, $needle)) {
                    continue;
                }
                $matches[] = [
                    'session_id' => $session->sessionId,
                    'event_id' => $event->eventId,
                    'sequence' => $event->sequence,
                    'event_type' => $event->type,
                    'citation_id' => "session:{$session->sessionId}:{$event->sequence}",
                    'timestamp' => $event->timestamp,
                    'data' => $event->data,
                ];
                if (count($matches) >= $limit) {
                    return $matches;
                }
            }
        }
        return $matches;
    }

    public function invoke(string $capability, mixed ...$arguments): mixed
    {
        if ($capability !== 'session.search') {
            throw new \RuntimeException($capability);
        }
        return $this->search(
            (string) ($arguments['query'] ?? $arguments[0] ?? ''),
            (int) ($arguments['limit'] ?? 20),
        );
    }
}

