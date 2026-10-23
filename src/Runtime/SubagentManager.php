<?php

declare(strict_types=1);

namespace LightAgent\Runtime;

use LightAgent\Async\AsyncCancelledException;
use LightAgent\Async\AsyncRuntime;
use LightAgent\Async\CancellationToken;
use LightAgent\Capabilities\PermissionSet;

/**
 * Subagent registry with depth/permission narrowing
 * (port of runtime.py SubagentManager).
 */
final class SubagentManager
{
    /** @var array<string, array{0: mixed, 1: SubagentRecord}> */
    private array $agents = [];
    private int $running = 0;

    /** @var array<string, array<string, CancellationToken>> */
    private array $cancellations = [];

    public function __construct(
        public readonly int $maxDepth = 2,
        public readonly int $maxAgents = 8,
        public readonly int $maxConcurrency = 4,
        public readonly ?\Closure $eventSink = null,
    ) {
    }

    /**
     * @param list<string>|null $allowedCapabilities
     * @param array<string, mixed>|null $metadata
     */
    public function register(
        mixed $agent,
        ?string $parentAgentId = null,
        ?PermissionSet $parentPermissions = null,
        ?array $allowedCapabilities = null,
        ?\LightAgent\Capabilities\CapabilityRisk $maxRisk = null,
        bool $persistent = false,
        ?array $metadata = null,
    ): SubagentRecord {
        if (count($this->agents) >= $this->maxAgents) {
            throw new \RuntimeException('subagent limit reached');
        }
        $parentRecord = null;
        if ($parentAgentId !== null) {
            $parentRecord = $this->agents[$parentAgentId][1] ?? null;
            if ($parentRecord === null) {
                // V4-14 / V5-25: an UNKNOWN parent must not degenerate into
                // "no parent at all". The old fallback was
                // `new PermissionSet()` — an UNRESTRICTED set that also
                // allows DESTRUCTIVE tools — so an orphan subagent was
                // granted more than any registered parent could hold. The
                // registration now fails closed.
                throw new \RuntimeException("unknown parent agent `{$parentAgentId}`");
            }
        }
        $depth = $parentRecord !== null ? $parentRecord->depth + 1 : 1;
        if ($depth > $this->maxDepth) {
            throw new \RuntimeException('subagent depth limit reached');
        }
        // The permission ceiling:
        //  - a ROOT registration (no parent) is explicitly UNRESTRICTED —
        //    `PermissionSet::unrestricted()` — and may then be narrowed by
        //    the requested capabilities / risk;
        //  - a CHILD is narrowed from its parent's EFFECTIVE permission set.
        //    A caller-supplied `parentPermissions` may only narrow that set
        //    further; `PermissionSet::narrow()` refuses any widening (an
        //    added capability or a higher risk) by throwing.
        if ($parentRecord === null) {
            $basePermissions = $parentPermissions ?? PermissionSet::unrestricted();
        } else {
            $basePermissions = $parentRecord->permissions;
            if ($parentPermissions !== null) {
                $basePermissions = $basePermissions->narrow(
                    allowed: $parentPermissions->allowed,
                    maxRisk: $parentPermissions->maxRisk->order() <= $basePermissions->maxRisk->order()
                        ? $parentPermissions->maxRisk
                        : null,
                );
            }
        }
        $permissions = $basePermissions->narrow(
            allowed: $allowedCapabilities,
            maxRisk: $maxRisk,
        );
        $agentId = bin2hex(random_bytes(16));
        $record = new SubagentRecord(
            agentId: $agentId,
            name: is_object($agent) && isset($agent->name) ? (string) $agent->name : $agentId,
            parentAgentId: $parentAgentId,
            depth: $depth,
            permissions: $permissions,
            persistent: $persistent,
            metadata: $metadata ?? [],
        );
        $this->agents[$agentId] = [$agent, $record];
        $this->emit('subagent.created', $record);
        return $record;
    }

    /**
     * @param array<string, mixed> $kwargs
     */
    public function run(
        string $agentId,
        string $query,
        ?CancellationToken $cancellationToken = null,
        ?string $idempotencyKey = null,
        array $kwargs = [],
    ): mixed {
        if (!isset($this->agents[$agentId])) {
            throw new \RuntimeException($agentId);
        }
        [$agent, $record] = $this->agents[$agentId];
        if ($this->running >= $this->maxConcurrency) {
            throw new \RuntimeException('subagent concurrency limit reached');
        }
        $token = $cancellationToken !== null ? $cancellationToken->child() : new CancellationToken();
        $this->cancellations[$agentId][$token->tokenId] = $token;
        $this->running++;
        $record->status = 'running';
        $this->emit('subagent.started', $record);
        // V4-14 / V5-25: the narrowed permission set must REACH the child.
        // It used to live on the record only (metadata), so the child ran
        // with the full permissions of its own runtime. The runtime context
        // of the child is narrowed to the recorded set, and the set is
        // handed to the agent as a `permissions` run option.
        $runtime = is_object($agent) && isset($agent->runtime) ? $agent->runtime : null;
        if ($runtime instanceof AgentRuntime) {
            $runtime->context->permissions = $record->permissions;
        }
        try {
            if ($token->isCancelled()) {
                throw new AsyncCancelledException($token->getReason() ?? 'cancelled');
            }
            $kwargs['cancellation_token'] = $token;
            $kwargs['permissions'] = $record->permissions;
            if ($idempotencyKey !== null) {
                $kwargs['idempotency_key'] = $idempotencyKey;
            }
            $result = $agent->run($query, $kwargs);
            if ($token->isCancelled()) {
                throw new AsyncCancelledException($token->getReason() ?? 'cancelled');
            }
            $record->status = 'success';
            $this->emit('subagent.completed', $record);
            return $result;
        } catch (AsyncCancelledException $e) {
            $record->status = 'cancelled';
            $this->emit('subagent.cancelled', $record, $token->getReason() ?? $e->getMessage());
            throw $e;
        } catch (\Throwable $error) {
            $record->status = 'failed';
            $this->emit('subagent.failed', $record, $error::class . ': ' . $error->getMessage());
            throw $error;
        } finally {
            $this->running--;
            if (isset($this->cancellations[$agentId][$token->tokenId])) {
                unset($this->cancellations[$agentId][$token->tokenId]);
                if ($this->cancellations[$agentId] === []) {
                    unset($this->cancellations[$agentId]);
                }
            }
        }
    }

    public function cancel(string $agentId, ?string $reason = null): int
    {
        if (!isset($this->agents[$agentId])) {
            throw new \RuntimeException($agentId);
        }
        $cancelled = 0;
        foreach ($this->cancellations[$agentId] ?? [] as $token) {
            if ($token->cancel($reason ?? 'subagent cancellation requested')) {
                $cancelled++;
            }
        }
        return $cancelled;
    }

    /**
     * @return list<SubagentRecord>
     */
    public function list(): array
    {
        $out = [];
        foreach ($this->agents as [, $record]) {
            $out[] = $record;
        }
        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tree(): array
    {
        $out = [];
        foreach ($this->agents as [, $record]) {
            $out[] = [
                'agent_id' => $record->agentId,
                'name' => $record->name,
                'parent_agent_id' => $record->parentAgentId,
                'depth' => $record->depth,
                'status' => $record->status,
                'persistent' => $record->persistent,
            ];
        }
        return $out;
    }

    public function send(string $agentId, mixed $content, InboxMessageType|string $messageType = InboxMessageType::CONTEXT): mixed
    {
        if (!isset($this->agents[$agentId])) {
            throw new \RuntimeException($agentId);
        }
        $agent = $this->agents[$agentId][0];
        $runtime = is_object($agent) && isset($agent->runtime) ? $agent->runtime : null;
        if ($runtime === null || $runtime->session === null) {
            throw new \RuntimeException('target subagent does not have an open runtime Session');
        }
        return $runtime->inbox->enqueue($messageType, $content);
    }

    private function emit(string $eventType, SubagentRecord $record, ?string $error = null): void
    {
        if ($this->eventSink === null) {
            return;
        }
        $data = [
            'agent' => [
                'agent_id' => $record->agentId,
                'name' => $record->name,
                'parent_agent_id' => $record->parentAgentId,
                'depth' => $record->depth,
                'permissions' => [
                    'allowed' => $record->permissions->allowed,
                    'denied' => $record->permissions->denied,
                    'max_risk' => $record->permissions->maxRisk->value,
                ],
                'persistent' => $record->persistent,
                'status' => $record->status,
                'metadata' => $record->metadata,
            ],
        ];
        if ($error !== null) {
            $data['error'] = $error;
        }
        ($this->eventSink)($eventType, $data);
    }
}
