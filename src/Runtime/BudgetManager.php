<?php

declare(strict_types=1);

namespace LightAgent\Runtime;

use LightAgent\Session\Session;

/**
 * Budget consumption manager (runtime.py BudgetManager).
 *
 * The usage is PERSISTED as `budget.consumed` session events, so a budget
 * limit survives "reopening the session" (H-19). Without an event sink the
 * usage only lived in memory: `restore()` looked for `budget.consumed`
 * events that were never written, so every reopen reset the budget to zero
 * and a cross-session limit could be bypassed.
 */
final class BudgetManager
{
    public readonly BudgetLimits $limits;
    public BudgetUsage $usage;

    /**
     * @var (\Closure(string, array<string, mixed>): void)|null
     */
    public ?\Closure $eventSink;

    public function __construct(
        ?BudgetLimits $limits = null,
        ?\Closure $eventSink = null,
    ) {
        $this->limits = $limits ?? new BudgetLimits();
        $this->usage = new BudgetUsage();
        $this->eventSink = $eventSink;
    }

    /**
     * Attach (or replace) the session event sink.
     *
     * AgentRuntime calls this for an INJECTED budget as well, so a budget
     * that was built outside the runtime still persists its usage.
     */
    public function setEventSink(?\Closure $eventSink): void
    {
        $this->eventSink = $eventSink;
    }

    public function consume(
        int $modelCalls = 0,
        int $toolCalls = 0,
        int $tokens = 0,
        float $seconds = 0.0,
        float $cost = 0.0,
    ): BudgetUsage {
        // M-27: a NEGATIVE delta is never a "refund" — it inflates the
        // remaining budget (`consume(modelCalls: -10)` on a limit of 5 left
        // 15 model calls). The check below can only trip on an over-limit
        // CANDIDATE, so a negative delta was committed unnoticed.
        foreach ([
            'modelCalls' => $modelCalls,
            'toolCalls' => $toolCalls,
            'tokens' => $tokens,
            'seconds' => $seconds,
            'cost' => $cost,
        ] as $name => $delta) {
            if ($delta < 0) {
                throw new \InvalidArgumentException("budget delta `{$name}` must not be negative");
            }
        }

        $candidate = new BudgetUsage(
            modelCalls: $this->usage->modelCalls + $modelCalls,
            toolCalls: $this->usage->toolCalls + $toolCalls,
            tokens: $this->usage->tokens + $tokens,
            seconds: $this->usage->seconds + $seconds,
            cost: $this->usage->cost + $cost,
        );

        // V5-41: the usage is COMMITTED first. The old order raised
        // BudgetExceeded before `$this->usage` was updated, so the tokens
        // that were actually spent never reached the usage counters and
        // never reached the session log — the overrun was free.
        $this->usage = $candidate;
        if ($this->eventSink !== null) {
            ($this->eventSink)('budget.consumed', ['usage' => $this->usage->toDict()]);
        }

        $limits = $this->limits->toDict();
        foreach ($limits as $dimension => $limit) {
            $used = match ($dimension) {
                'model_calls' => $candidate->modelCalls,
                'tool_calls' => $candidate->toolCalls,
                'tokens' => $candidate->tokens,
                'seconds' => $candidate->seconds,
                'cost' => $candidate->cost,
                default => 0,
            };
            if ($limit !== null && $used > $limit) {
                if ($this->eventSink !== null) {
                    ($this->eventSink)('budget.exhausted', [
                        'dimension' => $dimension,
                        'limit' => $limit,
                        'used' => $used,
                    ]);
                }
                throw new BudgetExceeded((string) $dimension, (float) $limit, (float) $used);
            }
        }

        return BudgetUsage::fromDict($this->usage->toDict());
    }

    /**
     * @return array<string, int|float|null>
     */
    public function remaining(): array
    {
        $out = [];
        foreach ($this->limits->toDict() as $dimension => $limit) {
            if ($limit === null) {
                $out[$dimension] = null;
                continue;
            }
            $used = match ($dimension) {
                'model_calls' => $this->usage->modelCalls,
                'tool_calls' => $this->usage->toolCalls,
                'tokens' => $this->usage->tokens,
                'seconds' => $this->usage->seconds,
                'cost' => $this->usage->cost,
                default => 0,
            };
            $out[$dimension] = max(0, $limit - $used);
        }
        return $out;
    }

    public function restore(Session $session): void
    {
        foreach (array_reverse($session->events) as $event) {
            if ($event->type === 'budget.consumed' && is_array($event->data['usage'] ?? null)) {
                $this->usage = BudgetUsage::fromDict($event->data['usage']);
                return;
            }
        }
        $this->usage = new BudgetUsage();
    }
}
