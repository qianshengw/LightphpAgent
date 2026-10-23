<?php

declare(strict_types=1);

namespace LightAgent\Tracing;

/**
 * A machine-readable event emitted during an agent run (tracing.py TraceEvent).
 */
final class TraceEvent
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $type,
        public array $data = [],
        public ?string $traceId = null,
        public ?string $parentTraceId = null,
        public ?string $runGroupId = null,
        public string $timestamp = '',
    ) {
        $this->timestamp ??= '';
        if ($this->timestamp === '') {
            $this->timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        // Trace data goes through the same credential redaction as session
        // event data (H6): trace/audit exports previously leaked secrets.
        $event = [
            'type' => $this->type,
            'data' => \LightAgent\Session\SessionConstants::jsonSafe($this->data),
            'trace_id' => $this->traceId,
            'timestamp' => $this->timestamp,
        ];
        if ($this->parentTraceId !== null) {
            $event['parent_trace_id'] = $this->parentTraceId;
        }
        if ($this->runGroupId !== null) {
            $event['run_group_id'] = $this->runGroupId;
        }
        return $event;
    }
}
