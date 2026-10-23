<?php

declare(strict_types=1);

namespace LightAgent\Review;

/**
 * In-memory request, decision, batch, and feedback store
 * (review.py InMemoryReviewStore).
 */
class InMemoryReviewStore
{
    /** @var array<string, ApprovalRequest> */
    public array $requests = [];

    /** @var array<string, ApprovalDecision> */
    public array $decisions = [];

    /** @var list<HumanFeedback> */
    public array $feedback = [];

    public function createRequest(ApprovalRequest $request): ApprovalRequest
    {
        $this->requests[$request->requestId] = $request;
        return $request;
    }

    /**
     * @param list<ApprovalRequest> $requests
     * @return list<ApprovalRequest>
     */
    public function createBatch(array $requests): array
    {
        $out = [];
        foreach ($requests as $request) {
            $out[] = $this->createRequest($request);
        }
        return $out;
    }

    public function getRequest(string $requestId): ?ApprovalRequest
    {
        return $this->requests[$requestId] ?? null;
    }

    public function resolve(string $requestId, ApprovalDecision $decision): ApprovalDecision
    {
        if (!isset($this->requests[$requestId])) {
            throw new \RuntimeException("approval request '{$requestId}' not found");
        }
        $decision->requestId = $requestId;
        $this->decisions[$requestId] = $decision;
        return $decision;
    }

    /**
     * @param array<string, ApprovalDecision> $decisions
     * @return array<string, ApprovalDecision>
     */
    public function resolveBatch(array $decisions): array
    {
        $out = [];
        foreach ($decisions as $requestId => $decision) {
            $out[$requestId] = $this->resolve((string) $requestId, $decision);
        }
        return $out;
    }

    public function getDecision(string $requestId): ?ApprovalDecision
    {
        return $this->decisions[$requestId] ?? null;
    }

    /**
     * @return list<ApprovalRequest>
     */
    public function listPending(): array
    {
        $pending = [];
        foreach ($this->requests as $requestId => $request) {
            if (!isset($this->decisions[$requestId])) {
                $pending[] = $request;
            }
        }
        return $pending;
    }

    public function addFeedback(HumanFeedback $feedback): HumanFeedback
    {
        $this->feedback[] = $feedback;
        return $feedback;
    }

    /**
     * @return list<HumanFeedback>
     */
    public function listFeedback(?string $traceId = null): array
    {
        if ($traceId === null) {
            return $this->feedback;
        }
        return array_values(array_filter($this->feedback, static fn (HumanFeedback $f): bool => $f->traceId === $traceId));
    }
}
