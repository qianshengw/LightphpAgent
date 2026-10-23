<?php

declare(strict_types=1);

namespace LightAgent\Flow;

/**
 * Result captured for one LightFlow step (flow.py LightFlowStepResult).
 */
final class LightFlowStepResult implements \Stringable
{
    /**
     * @param list<array<string, mixed>> $trace
     */
    public function __construct(
        public readonly string $name,
        public readonly string $content = '',
        public readonly ?string $error = null,
        public readonly int $attempts = 1,
        public array $trace = [],
        public readonly string $status = FlowConstants::SUCCESS,
        public readonly ?float $startedAt = null,
        public readonly ?float $endedAt = null,
        public readonly ?float $durationMs = null,
        public readonly ?string $inputSummary = null,
        public readonly ?string $outputSummary = null,
        public readonly int $retryCount = 0,
        public readonly bool $usedFallback = false,
        public readonly bool $timedOut = false,
        public readonly ?string $timeoutMode = null,
        public readonly ?string $idempotencyKey = null,
        public ?string $approvalRequestId = null,
        public ?string $approvalDecision = null,
    ) {
    }

    public function __toString(): string
    {
        return $this->content;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'name' => $this->name,
            'content' => $this->content,
            'error' => $this->error,
            'attempts' => $this->attempts,
            'trace' => $this->trace,
            'status' => $this->status,
            'started_at' => $this->startedAt,
            'ended_at' => $this->endedAt,
            'duration_ms' => $this->durationMs,
            'input_summary' => $this->inputSummary,
            'output_summary' => $this->outputSummary,
            'retry_count' => $this->retryCount,
            'used_fallback' => $this->usedFallback,
            'timed_out' => $this->timedOut,
            'timeout_mode' => $this->timeoutMode,
            'idempotency_key' => $this->idempotencyKey,
            'approval_request_id' => $this->approvalRequestId,
            'approval_decision' => $this->approvalDecision,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromDict(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            content: (string) ($data['content'] ?? ''),
            error: isset($data['error']) ? (string) $data['error'] : null,
            attempts: (int) ($data['attempts'] ?? 1),
            trace: is_array($data['trace'] ?? null) ? $data['trace'] : [],
            status: (string) ($data['status'] ?? FlowConstants::SUCCESS),
            startedAt: isset($data['started_at']) ? (float) $data['started_at'] : null,
            endedAt: isset($data['ended_at']) ? (float) $data['ended_at'] : null,
            durationMs: isset($data['duration_ms']) ? (float) $data['duration_ms'] : null,
            inputSummary: isset($data['input_summary']) ? (string) $data['input_summary'] : null,
            outputSummary: isset($data['output_summary']) ? (string) $data['output_summary'] : null,
            retryCount: (int) ($data['retry_count'] ?? 0),
            usedFallback: (bool) ($data['used_fallback'] ?? false),
            timedOut: (bool) ($data['timed_out'] ?? false),
            timeoutMode: isset($data['timeout_mode']) ? (string) $data['timeout_mode'] : null,
            idempotencyKey: isset($data['idempotency_key']) ? (string) $data['idempotency_key'] : null,
            approvalRequestId: isset($data['approval_request_id']) ? (string) $data['approval_request_id'] : null,
            approvalDecision: isset($data['approval_decision']) ? (string) $data['approval_decision'] : null,
        );
    }
}
