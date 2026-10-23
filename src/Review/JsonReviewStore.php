<?php

declare(strict_types=1);

namespace LightAgent\Review;

/**
 * Small durable JSON store for delayed approvals and trace feedback
 * (review.py JsonReviewStore).
 */
final class JsonReviewStore extends InMemoryReviewStore
{
    public function __construct(
        public readonly string $path,
    ) {
        $this->loadFile();
    }

    public function createRequest(ApprovalRequest $request): ApprovalRequest
    {
        $value = parent::createRequest($request);
        $this->saveFile();
        return $value;
    }

    public function resolve(string $requestId, ApprovalDecision $decision): ApprovalDecision
    {
        $value = parent::resolve($requestId, $decision);
        $this->saveFile();
        return $value;
    }

    public function addFeedback(HumanFeedback $feedback): HumanFeedback
    {
        $value = parent::addFeedback($feedback);
        $this->saveFile();
        return $value;
    }

    private function loadFile(): void
    {
        if (!is_file($this->path)) {
            return;
        }
        $payload = json_decode((string) file_get_contents($this->path), true);
        if (!is_array($payload)) {
            return;
        }
        foreach ($payload['requests'] ?? [] as $item) {
            if (!is_array($item) || !isset($item['request_id'])) {
                continue;
            }
            $request = new ApprovalRequest(
                action: (string) $item['action'],
                requestId: (string) $item['request_id'],
                runId: isset($item['run_id']) ? (string) $item['run_id'] : null,
                traceId: isset($item['trace_id']) ? (string) $item['trace_id'] : null,
                actionName: isset($item['action_name']) ? (string) $item['action_name'] : null,
                toolName: isset($item['tool_name']) ? (string) $item['tool_name'] : null,
                arguments: is_array($item['arguments'] ?? null) ? $item['arguments'] : [],
                sourceAgent: isset($item['source_agent']) ? (string) $item['source_agent'] : null,
                targetAgent: isset($item['target_agent']) ? (string) $item['target_agent'] : null,
                allowedDecisions: is_array($item['allowed_decisions'] ?? null) ? $item['allowed_decisions'] : [],
                reviewerMetadata: is_array($item['reviewer_metadata'] ?? null) ? $item['reviewer_metadata'] : [],
                metadata: is_array($item['metadata'] ?? null) ? $item['metadata'] : [],
                createdAt: (string) ($item['created_at'] ?? ''),
            );
            $this->requests[$request->requestId] = $request;
        }
        foreach ($payload['decisions'] ?? [] as $item) {
            if (is_array($item) && isset($item['request_id'])) {
                $decision = ApprovalDecision::fromDict($item);
                $this->decisions[$decision->requestId] = $decision;
            }
        }
        foreach ($payload['feedback'] ?? [] as $item) {
            if (is_array($item) && isset($item['trace_id'])) {
                $this->feedback[] = new HumanFeedback(
                    traceId: (string) $item['trace_id'],
                    rating: isset($item['rating']) ? (float) $item['rating'] : null,
                    label: isset($item['label']) ? (string) $item['label'] : null,
                    comment: isset($item['comment']) ? (string) $item['comment'] : null,
                    reviewerId: isset($item['reviewer_id']) ? (string) $item['reviewer_id'] : null,
                    metadata: is_array($item['metadata'] ?? null) ? $item['metadata'] : [],
                    feedbackId: (string) ($item['feedback_id'] ?? '') ?: null,
                    createdAt: (string) ($item['created_at'] ?? ''),
                );
            }
        }
    }

    private function saveFile(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $payload = [
            'requests' => array_map(static fn (ApprovalRequest $r): array => $r->toDict(), array_values($this->requests)),
            'decisions' => array_map(static fn (ApprovalDecision $d): array => $d->toDict(), array_values($this->decisions)),
            'feedback' => array_map(static fn (HumanFeedback $f): array => $f->toDict(), $this->feedback),
        ];
        $temporary = $this->path . '.tmp';
        // Never let a failed encode truncate the approval store: json_encode()
        // returns false for values it cannot represent, and writing that as
        // '' would replace the store with a 0-byte file (T15).
        $encoded = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        if ($encoded === false) {
            throw new \RuntimeException(
                'review store payload cannot be encoded: ' . json_last_error_msg(),
            );
        }
        $written = file_put_contents($temporary, $encoded);
        if ($written === false || $written !== strlen($encoded)) {
            @unlink($temporary);
            throw new \RuntimeException("failed to write review store temporary file: {$this->path}.tmp");
        }
        if (!@rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new \RuntimeException("failed to replace review store: {$this->path}");
        }
    }
}
