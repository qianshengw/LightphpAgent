<?php

declare(strict_types=1);

namespace LightAgent\Runtime;

/**
 * Progress tracking primitives (runtime.py ProgressState / ProgressTracker).
 */
final class ProgressState
{
    public function __construct(
        public int $steps = 0,
        public int $unchangedSteps = 0,
        public int $repeatedToolCalls = 0,
        public ?string $lastOutput = null,
        public ?string $lastToolSignature = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'steps' => $this->steps,
            'unchanged_steps' => $this->unchangedSteps,
            'repeated_tool_calls' => $this->repeatedToolCalls,
            'last_output' => $this->lastOutput,
            'last_tool_signature' => $this->lastToolSignature,
        ];
    }
}

final class ProgressTracker
{
    public ProgressState $state;

    public function __construct(
        public readonly int $maxUnchangedSteps = 3,
        public readonly int $maxRepeatedToolCalls = 3,
        public readonly ?\Closure $eventSink = null,
    ) {
        $this->state = new ProgressState();
    }

    public function record(mixed $output = null, ?string $tool = null, mixed $arguments = null): ProgressState
    {
        $rendered = print_r($output, true);
        $signature = $tool !== null
            ? print_r([$tool, $arguments], true)
            : null;
        $this->state->steps++;
        $this->state->unchangedSteps = $rendered === $this->state->lastOutput
            ? $this->state->unchangedSteps + 1
            : 0;
        $this->state->repeatedToolCalls = $signature !== null && $signature === $this->state->lastToolSignature
            ? $this->state->repeatedToolCalls + 1
            : 0;
        $this->state->lastOutput = $rendered;
        $this->state->lastToolSignature = $signature;
        if ($this->state->unchangedSteps >= $this->maxUnchangedSteps) {
            $this->emit('progress.stalled', 'unchanged_output');
        }
        if ($this->state->repeatedToolCalls >= $this->maxRepeatedToolCalls) {
            $this->emit('progress.stalled', 'repeated_tool_call');
        }
        return $this->state;
    }

    public function isStalled(): bool
    {
        return $this->state->unchangedSteps >= $this->maxUnchangedSteps
            || $this->state->repeatedToolCalls >= $this->maxRepeatedToolCalls;
    }

    private function emit(string $eventType, string $reason): void
    {
        if ($this->eventSink !== null) {
            ($this->eventSink)($eventType, ['reason' => $reason, 'state' => $this->state->toDict()]);
        }
    }
}
