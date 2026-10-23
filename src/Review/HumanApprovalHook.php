<?php

declare(strict_types=1);

namespace LightAgent\Review;

use LightAgent\Hooks\HookAction;
use LightAgent\Hooks\HookContext;
use LightAgent\Hooks\HookDecision;
use LightAgent\Support\BooleanValue;

/**
 * Fail-closed hook for selected tools and handoffs
 * (port of review.py HumanApprovalHook).
 *
 * Without a synchronous reviewer, the hook stores a pending request and blocks
 * the action. Resolve the request, then rerun with approval_id=request_id.
 */
final class HumanApprovalHook
{
    /**
     * The taxonomy code that marks an approval block, so that callers can
     * tell it apart from an ordinary hook block (V5-16).
     */
    public const ERROR_CODE = 'LA-APPROVAL';

    /** @var list<string>|null */
    private readonly ?array $tools;
    /** @var list<string> */
    private readonly array $phases;

    /**
     * @param list<string>|null $tools tool names that require approval (null = all)
     * @param list<string>|null $phases lifecycle phases (default: before_tool_call + on_handoff)
     * @param (callable(ApprovalRequest): mixed)|null $reviewer
     */
    public function __construct(
        ?InMemoryReviewStore $store = null,
        public readonly mixed $reviewer = null,
        ?array $tools = null,
        ?array $phases = null,
        public readonly ?float $timeout = null,
    ) {
        if ($this->timeout !== null && $this->timeout <= 0) {
            throw new \InvalidArgumentException('timeout must be greater than 0');
        }
        $this->store = $store ?? new InMemoryReviewStore();
        $this->tools = $tools !== null ? array_map('strval', $tools) : null;
        $this->phases = $phases ?? ['before_tool_call', 'on_handoff'];
    }

    public readonly InMemoryReviewStore $store;

    public function __invoke(HookContext $context): ?HookDecision
    {
        if (!in_array($context->phase, $this->phases, true)) {
            return null;
        }
        if ($context->phase === 'before_tool_call') {
            $toolName = (string) ($context->payload['tool_name'] ?? '');
            // Case/space-insensitive matching so `Delete_File` still requires
            // approval (M17).
            if ($this->tools !== null && !in_array(strtolower(trim($toolName)), array_map(static fn ($t) => strtolower(trim((string) $t)), $this->tools), true)) {
                return null;
            }
        }

        $approvalId = $context->metadata['approval_id'] ?? null;
        if ($approvalId !== null && $approvalId !== '') {
            $previousRequest = $this->store->getRequest((string) $approvalId);
            $existing = $this->store->getDecision((string) $approvalId);
            if ($previousRequest !== null && $existing !== null && self::matches($previousRequest, $context)) {
                return $this->hookDecision($existing, $context, reused: true);
            }
            if ($previousRequest !== null && $existing !== null) {
                return HookDecision::block(
                    'approval does not match the current action',
                    metadata: [
                        'approval_request_id' => (string) $approvalId,
                        'error_code' => self::ERROR_CODE,
                        'review_events' => [[
                            'type' => 'approval_rejected',
                            'request_id' => (string) $approvalId,
                            'phase' => $context->phase,
                            'reason' => 'approval context mismatch',
                            'reused' => true,
                        ]],
                    ],
                );
            }
            return HookDecision::block(
                'approval request not found or unresolved',
                metadata: [
                    'approval_request_id' => (string) $approvalId,
                    'error_code' => self::ERROR_CODE,
                    'review_events' => [[
                        'type' => 'approval_required',
                        'request_id' => (string) $approvalId,
                        'phase' => $context->phase,
                        'reason' => 'approval request not found or unresolved',
                        'reused' => true,
                    ]],
                ],
            );
        }

        $request = $this->buildRequest($context);
        $this->store->createRequest($request);
        if ($this->reviewer === null) {
            $decision = ApprovalDecision::pending(
                'human approval required',
                ['request_id' => $request->requestId],
            );
            return $this->hookDecision($decision, $context);
        }

        try {
            $raw = $this->callReviewer($request);
            $decision = self::normalizeDecision($raw, $request->requestId);
        } catch (\Throwable $exc) {
            $decision = ApprovalDecision::reject(
                "approval reviewer failed: {$exc->getMessage()}",
                ['request_id' => $request->requestId],
            );
        }
        $this->store->resolve($request->requestId, $decision);
        return $this->hookDecision($decision, $context);
    }

    private function callReviewer(ApprovalRequest $request): mixed
    {
        // PORTING-DIFF: Python times the reviewer out via a thread; PHP runs it
        // inline under AsyncRuntime::waitFor when a loop is active, else inline.
        if ($this->timeout === null) {
            return ($this->reviewer)($request);
        }
        $reviewer = $this->reviewer;
        return \LightAgent\Async\AsyncRuntime::waitFor(
            static fn (): mixed => $reviewer($request),
            $this->timeout,
        );
    }

    /**
     * @param array<string, mixed>|null $raw
     */
    private static function normalizeDecision(mixed $raw, string $requestId): ApprovalDecision
    {
        if ($raw instanceof ApprovalDecision) {
            $raw->requestId = $requestId;
            return $raw;
        }
        // A missing decision is NOT an approval (H10): null rejects, keeping
        // the fail-closed contract that exceptions already follow.
        if ($raw === null) {
            return ApprovalDecision::reject('human reviewer returned no decision', ['request_id' => $requestId]);
        }
        if ($raw === true) {
            return ApprovalDecision::approve(['request_id' => $requestId]);
        }
        if ($raw === false) {
            return ApprovalDecision::reject('human reviewer rejected action', ['request_id' => $requestId]);
        }
        if (is_string($raw)) {
            return ApprovalDecision::reject($raw, ['request_id' => $requestId]);
        }
        if (is_array($raw)) {
            $action = $raw['action'] ?? null;
            if ($action === null) {
                // A reviewer answering with a JSON-decoded payload spells the
                // verdict as the STRING 'false' — and `(bool) 'false'` is TRUE
                // in PHP, which turned a human "reject" into a human
                // "approve" (T6). Parse strictly: only an explicit truthy
                // spelling approves; everything else rejects.
                if (array_key_exists('approved', $raw)) {
                    $approved = BooleanValue::parse($raw['approved']);
                } elseif (array_key_exists('allowed', $raw)) {
                    $approved = BooleanValue::parse($raw['allowed']);
                } else {
                    $approved = false;
                }
                $action = $approved ? ApprovalConstants::APPROVE : ApprovalConstants::REJECT;
            }
            return new ApprovalDecision(
                action: (string) $action,
                requestId: $requestId,
                reason: isset($raw['reason']) ? (string) $raw['reason'] : null,
                arguments: is_array($raw['arguments'] ?? null) ? $raw['arguments'] : null,
                response: isset($raw['response']) ? (string) $raw['response'] : null,
                reviewerId: isset($raw['reviewer_id']) ? (string) $raw['reviewer_id'] : null,
                metadata: is_array($raw['metadata'] ?? null) ? $raw['metadata'] : [],
            );
        }
        return ApprovalDecision::reject('unsupported approval decision', ['request_id' => $requestId]);
    }

    private function buildRequest(HookContext $context): ApprovalRequest
    {
        $payload = $context->payload;
        $arguments = is_array($payload['arguments'] ?? null) ? $payload['arguments'] : [];
        if ($context->phase === 'on_handoff' && ($payload['query'] ?? null) !== null) {
            $arguments['query'] = $payload['query'];
        }
        return new ApprovalRequest(
            action: $context->phase,
            runId: $context->runId,
            traceId: $context->traceId,
            actionName: (string) ($payload['action_name'] ?? $context->phase),
            toolName: isset($payload['tool_name']) ? (string) $payload['tool_name'] : null,
            arguments: $arguments,
            sourceAgent: ($payload['source_agent'] ?? null) ?? $context->agentName,
            targetAgent: isset($payload['target_agent']) ? (string) $payload['target_agent'] : null,
            reviewerMetadata: ['user_id' => $context->userId],
            metadata: [
                'flow_id' => $context->flowId,
                'step_name' => $context->stepName,
                'run_group_id' => $context->runGroupId,
                // The stable correlation id, when the caller supplied one.
                'correlation_id' => self::correlationId($context),
            ],
        );
    }

    /**
     * The stable correlation id of the current logical operation.
     *
     * The Agent runtime can only pass it through the run metadata, so the
     * `metadata['correlation_id']` entry is checked first; the dedicated
     * context field is the direct-API spelling.
     */
    private static function correlationId(HookContext $context): ?string
    {
        $raw = $context->metadata['correlation_id'] ?? $context->correlationId;
        if ($raw === null) {
            return null;
        }
        $id = trim((string) $raw);
        return $id === '' ? null : $id;
    }

    private static function matches(ApprovalRequest $request, HookContext $context): bool
    {
        $payload = $context->payload;
        $arguments = is_array($payload['arguments'] ?? null) ? $payload['arguments'] : [];
        if ($context->phase === 'on_handoff' && ($payload['query'] ?? null) !== null) {
            $arguments['query'] = $payload['query'];
        }
        // The approval is bound to the ACTION, not to the run.
        //
        // The run id is regenerated for every `LightAgent::run()` call, so
        // binding an approval to it made the documented "mode 1" flow
        // impossible: the first run produced a request bound to its own
        // run id, and the second run — with a different id — could never
        // consume that approval, so the tool stayed blocked forever
        // (M-41 / V5-03). The run id is still recorded on the request for
        // traceability, but it is ADVISORY.
        //
        // The argument binding is NOT weakened: the same tool with the
        // same arguments, in the same phase, for the same agents and the
        // same user, is still required.
        //
        // A caller that wants a stricter binding supplies a STABLE
        // correlation id; an approval that carries one may only be
        // consumed in the same correlation scope.
        $requestCorrelation = $request->metadata['correlation_id'] ?? null;
        $contextCorrelation = self::correlationId($context);
        $correlationMatches = $requestCorrelation === null
            || ($contextCorrelation !== null && $requestCorrelation === $contextCorrelation);
        return $correlationMatches
            && $request->action === $context->phase
            && ($request->toolName ?? null) === ($payload['tool_name'] ?? null)
            && self::argumentsMatch($request->arguments, $arguments)
            && ($request->sourceAgent ?? null) === (($payload['source_agent'] ?? null) ?? $context->agentName)
            && ($request->targetAgent ?? null) === ($payload['target_agent'] ?? null)
            && ($request->reviewerMetadata['user_id'] ?? null) === $context->userId;
    }

    /**
     * Strict recursive comparison: loose == would accept '1e3' == 1000.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function argumentsMatch(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $key => $value) {
            if (!array_key_exists($key, $b)) {
                return false;
            }
            if (is_array($value) && is_array($b[$key])) {
                if (!self::argumentsMatch($value, $b[$key])) {
                    return false;
                }
                continue;
            }
            if (get_debug_type($value) !== get_debug_type($b[$key]) || $value !== $b[$key]) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private static function hookDecision(
        ApprovalDecision $decision,
        HookContext $context,
        bool $reused = false,
    ): HookDecision {
        $eventType = $decision->action === ApprovalConstants::PENDING
            ? 'approval_required'
            : "approval_{$decision->action}";
        $event = [
            'type' => $eventType,
            'request_id' => $decision->requestId,
            'phase' => $context->phase,
            'tool_name' => $context->payload['tool_name'] ?? null,
            'source_agent' => ($context->payload['source_agent'] ?? null) ?? $context->agentName,
            'target_agent' => $context->payload['target_agent'] ?? null,
            'reason' => $decision->reason,
            'reviewer_id' => $decision->reviewerId,
            'reused' => $reused,
        ];
        $metadata = [
            'approval_request_id' => $decision->requestId,
            'review_events' => [$event],
        ];
        if ($decision->action === ApprovalConstants::APPROVE) {
            return HookDecision::cont(metadata: $metadata);
        }
        if ($decision->action === ApprovalConstants::EDIT) {
            $payload = $context->payload;
            if ($context->phase === 'before_tool_call') {
                // An EDIT decision that carries NO arguments must not wipe
                // the reviewed ones: the tool would then run with no
                // arguments at all instead of the ones the human looked at
                // (H-12). Fall back to the arguments of the current
                // payload.
                $payload['arguments'] = $decision->arguments
                    ?? (is_array($payload['arguments'] ?? null) ? $payload['arguments'] : []);
            } else {
                $payload = array_merge($payload, $decision->arguments ?? []);
            }
            return HookDecision::replace($payload, metadata: $metadata);
        }
        if ($decision->action === ApprovalConstants::RESPOND) {
            return HookDecision::block(
                $decision->response ?? 'action replaced by human response',
                metadata: self::withErrorCode($metadata),
            );
        }
        return HookDecision::block(
            $decision->reason ?? "approval {$decision->action}",
            metadata: self::withErrorCode($metadata),
        );
    }

    /**
     * Mark a decision as an APPROVAL block.
     *
     * The taxonomy already defines `LA-APPROVAL` ("This operation is
     * waiting for explicit approval"), but the approval path produced a
     * plain-text message with no `[LA-` prefix, so a caller could not
     * tell "blocked by approval" apart from "blocked by an ordinary
     * hook" (V5-16). The machine-readable marker travels in the decision
     * metadata; the runtime turns it into
     * `ErrorTaxonomy::formatCode('LA-APPROVAL', …)`.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private static function withErrorCode(array $metadata): array
    {
        $metadata['error_code'] = self::ERROR_CODE;
        return $metadata;
    }
}
