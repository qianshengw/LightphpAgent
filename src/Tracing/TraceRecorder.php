<?php

declare(strict_types=1);

namespace LightAgent\Tracing;

/**
 * Collect trace events when tracing is explicitly enabled (tracing.py TraceRecorder).
 */
final class TraceRecorder
{
    /** @var list<TraceEvent> */
    public array $events = [];

    public function __construct(
        public readonly bool $enabled = false,
        public readonly ?string $traceId = null,
        public readonly ?string $parentTraceId = null,
        public readonly ?string $runGroupId = null,
    ) {
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public function record(string $eventType, ?array $data = null): ?TraceEvent
    {
        if (!$this->enabled) {
            return null;
        }
        $event = new TraceEvent(
            type: $eventType,
            data: $data ?? [],
            traceId: $this->traceId,
            parentTraceId: $this->parentTraceId,
            runGroupId: $this->runGroupId,
        );
        $this->events[] = $event;
        return $event;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toList(): array
    {
        return array_map(static fn (TraceEvent $e): array => $e->toDict(), $this->events);
    }

    /**
     * Attach a serializable human annotation to this trace.
     */
    public function recordFeedback(mixed $feedback): ?TraceEvent
    {
        if (is_object($feedback) && method_exists($feedback, 'toDict')) {
            $data = $feedback->toDict();
        } elseif (is_object($feedback) && method_exists($feedback, 'to_dict')) {
            $data = $feedback->to_dict();
        } elseif (is_array($feedback)) {
            $data = $feedback;
        } else {
            throw new \TypeError('feedback must be an array or provide toDict()');
        }
        return $this->record('human_feedback', $data);
    }

    /**
     * @param array<string, float>|null $pricing
     * @param bool $usageIsTraceScoped see `TraceUtils::summarize()`
     */
    public function summarize(mixed $usage = null, ?array $pricing = null, bool $usageIsTraceScoped = false): TraceSummary
    {
        return TraceUtils::summarize(
            $this->toList(),
            usage: $usage,
            pricing: $pricing,
            usageIsTraceScoped: $usageIsTraceScoped,
        );
    }

    /**
     * @param array<string, float>|null $pricing
     * @param array<string, mixed>|null $metadata
     * @param bool $usageIsTraceScoped see `TraceUtils::summarize()`
     */
    public function export(
        callable|object $exporter,
        mixed $usage = null,
        ?array $pricing = null,
        ?array $metadata = null,
        bool $usageIsTraceScoped = false
    ): mixed {
        return TraceUtils::export(
            $this->toList(),
            $exporter,
            usage: $usage,
            pricing: $pricing,
            metadata: $metadata,
            usageIsTraceScoped: $usageIsTraceScoped,
        );
    }
}
