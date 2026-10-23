<?php

declare(strict_types=1);

namespace LightAgent\Tests\Protocol;

use LightAgent\Protocol\MemoryAdmissionDecision;
use LightAgent\Protocol\MemoryCandidate;
use LightAgent\Protocol\MemoryPolicy;
use LightAgent\Protocol\MemoryPromotion;
use LightAgent\Protocol\MemoryPromotionDecision;
use LightAgent\Protocol\MemoryScope;
use PHPUnit\Framework\TestCase;

/**
 * PHP port of the pure-logic subset of tests/test_memory_policy.py.
 * (Agent-integration tests migrate in Phase 4.)
 */
final class MemoryPolicyTest extends TestCase
{
    public function test_constructor_validates_bounds(): void
    {
        $policy = new MemoryPolicy(
            namespace: 'tenant-a',
            allowUnattributedResults: false,
            allowedSources: ['user'],
            allowedScopes: ['user'],
            allowedAgentNames: ['writer'],
            allowedTrustLevels: ['verified'],
            minConfidence: 0.8,
            enforceExpiresAt: true,
            memoryWriteAdmission: null,
            maxWritesPerRun: 7,
            rejectDuplicateWrites: true,
            minWriteLength: 4,
            rejectWritePatterns: ['ignore previous instructions'],
        );

        self::assertSame(7, $policy->maxWritesPerRun);
        self::assertTrue($policy->rejectDuplicateWrites);
        self::assertSame(4, $policy->minWriteLength);
        self::assertSame(['ignore previous instructions'], $policy->rejectWritePatterns);
        self::assertNull($policy->memoryPromotionAdmission);
        self::assertTrue($policy->requirePromotionForInternalMemory);
        self::assertFalse($policy->requireWriteAdmission);
    }

    public function test_negative_max_writes_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MemoryPolicy(maxWritesPerRun: -1);
    }

    public function test_negative_min_write_length_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MemoryPolicy(minWriteLength: -2);
    }

    public function test_scoped_user_id(): void
    {
        self::assertSame('tenant-a:alice', (new MemoryPolicy(namespace: 'tenant-a'))->scopedUserId('alice'));
        self::assertSame('alice', (new MemoryPolicy())->scopedUserId('alice'));
    }

    public function test_allows_result_non_dict_item(): void
    {
        $policy = new MemoryPolicy();
        self::assertTrue($policy->allowsResult('plain string', 'alice', 'alice'));
        self::assertFalse((new MemoryPolicy(allowUnattributedResults: false))->allowsResult('plain', 'alice', 'alice'));
    }

    public function test_cross_user_results_blocked(): void
    {
        $policy = new MemoryPolicy(namespace: 'tenant-a', allowUnattributedResults: false);

        self::assertTrue($policy->allowsResult(
            ['memory' => 'safe memory', 'metadata' => ['user_id' => 'tenant-a:alice']],
            'tenant-a:alice',
            'alice',
        ));
        self::assertFalse($policy->allowsResult(
            ['memory' => 'other user memory', 'metadata' => ['user_id' => 'tenant-a:bob']],
            'tenant-a:alice',
            'alice',
        ));
        self::assertFalse($policy->allowsResult(
            ['memory' => 'unattributed memory'],
            'tenant-a:alice',
            'alice',
        ));
    }

    public function test_filters_by_source_scope_agent_trust_confidence(): void
    {
        $policy = new MemoryPolicy(
            namespace: 'tenant-a',
            allowUnattributedResults: false,
            allowedSources: ['user'],
            allowedScopes: ['user'],
            allowedAgentNames: ['writer'],
            allowedTrustLevels: ['verified'],
            minConfidence: 0.85,
        );

        $ok = static fn (array $overrides, mixed $confidence = 0.92) => array_merge(
            ['memory' => 'x', 'metadata' => array_merge([
                'user_id' => 'tenant-a:alice',
                'source' => 'user',
                'scope' => 'user',
                'agent_name' => 'writer',
                'trust_level' => 'verified',
                'confidence' => $confidence,
            ], $overrides)],
        );

        self::assertTrue($policy->allowsResult($ok([]), 'tenant-a:alice', 'alice'));
        self::assertFalse($policy->allowsResult($ok(['source' => 'reflection']), 'tenant-a:alice', 'alice'));
        self::assertFalse($policy->allowsResult($ok(['scope' => 'agent']), 'tenant-a:alice', 'alice'));
        self::assertFalse($policy->allowsResult($ok(['agent_name' => 'critic']), 'tenant-a:alice', 'alice'));
        self::assertFalse($policy->allowsResult($ok(['trust_level' => 'unknown']), 'tenant-a:alice', 'alice'));
        self::assertFalse($policy->allowsResult($ok([], 0.5), 'tenant-a:alice', 'alice'));
        // missing scope metadata -> blocked when filters enabled
        self::assertFalse($policy->allowsResult(
            ['memory' => 'legacy', 'metadata' => ['user_id' => 'tenant-a:alice']],
            'tenant-a:alice',
            'alice',
        ));
    }

    public function test_filters_expired_memory_results(): void
    {
        $policy = new MemoryPolicy(enforceExpiresAt: true);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $future = $now->modify('+1 hour')->format(\DateTimeInterface::ATOM);
        $past = $now->modify('-1 hour')->format(\DateTimeInterface::ATOM);

        $fresh = ['memory' => 'fresh', 'metadata' => ['user_id' => 'alice', 'expires_at' => $future]];
        $expired = ['memory' => 'expired', 'metadata' => ['user_id' => 'alice', 'expires_at' => $past]];
        $missing = ['memory' => 'missing expiry', 'metadata' => ['user_id' => 'alice']];

        self::assertTrue($policy->allowsResult($fresh, 'alice', 'alice'));
        self::assertFalse($policy->allowsResult($expired, 'alice', 'alice'));
        self::assertFalse($policy->allowsResult($missing, 'alice', 'alice'));
    }

    public function test_unpromoted_internal_results_blocked_before_prompt_injection(): void
    {
        $policy = new MemoryPolicy(allowUnattributedResults: true);

        $unpromotedReflection = [
            'memory' => 'private reflection',
            'metadata' => ['user_id' => 'agent', 'source' => 'reflection', 'scope' => 'agent'],
        ];
        $explicitCandidate = [
            'memory' => 'candidate text',
            'metadata' => ['user_id' => 'alice', 'source' => 'user', 'scope' => 'user', 'promotion_status' => 'candidate'],
        ];
        $promotedReflection = [
            'memory' => 'approved reflection',
            'metadata' => [
                'user_id' => 'agent', 'source' => 'reflection', 'scope' => 'agent',
                'promotion_status' => 'promoted', 'injectable' => true,
            ],
        ];

        self::assertFalse($policy->allowsResult($unpromotedReflection, 'agent', 'agent'));
        self::assertFalse($policy->allowsResult($explicitCandidate, 'alice', 'alice'));
        self::assertTrue($policy->allowsResult($promotedReflection, 'agent', 'agent'));
    }

    public function test_business_status_does_not_block_user_memory(): void
    {
        $policy = new MemoryPolicy(requirePromotionForInternalMemory: false);
        $orderMemory = [
            'memory' => 'Customer order is pending',
            'metadata' => ['user_id' => 'alice', 'source' => 'user', 'scope' => 'user', 'status' => 'pending'],
        ];

        self::assertTrue($policy->allowsResult($orderMemory, 'alice', 'alice'));
    }

    public function test_legacy_internal_memory_compatibility_opt_out(): void
    {
        $legacyReflection = [
            'memory' => 'Legacy reflection',
            'metadata' => ['user_id' => 'writer', 'source' => 'reflection', 'scope' => 'agent'],
        ];

        self::assertFalse((new MemoryPolicy())->allowsResult($legacyReflection, 'writer', 'writer'));
        self::assertTrue(
            (new MemoryPolicy(requirePromotionForInternalMemory: false))->allowsResult($legacyReflection, 'writer', 'writer'),
        );
    }

    public function test_scope_exports_recommended_metadata_shape(): void
    {
        $scope = MemoryScope::reflection(
            agentName: 'writer',
            traceId: 'reflection-trace',
            parentTraceId: 'parent-trace',
            metadata: ['project_id' => 'docs'],
        );

        self::assertSame([
            'project_id' => 'docs',
            'source' => 'reflection',
            'scope' => 'agent',
            'agent_name' => 'writer',
            'trace_id' => 'reflection-trace',
            'parent_trace_id' => 'parent-trace',
        ], $scope->toMetadata());
    }

    public function test_scope_user_factory(): void
    {
        $scope = MemoryScope::user(agentName: 'a', traceId: 't', metadata: ['k' => 'v']);
        self::assertSame('user', $scope->source);
        self::assertSame('user', $scope->scope);
        self::assertSame(['k' => 'v', 'source' => 'user', 'scope' => 'user', 'agent_name' => 'a', 'trace_id' => 't'], $scope->toMetadata());
    }

    public function test_write_limit_per_run(): void
    {
        $policy = new MemoryPolicy(maxWritesPerRun: 1);

        $first = $policy->allowsWrite('first', ['user_id' => 'alice'], writeCount: 0);
        $second = $policy->allowsWrite('second', ['user_id' => 'alice'], writeCount: 1);

        self::assertTrue($first->allowed);
        self::assertFalse($second->allowed);
        self::assertStringContainsString('Memory write limit exceeded', (string) $second->reason);
    }

    public function test_write_admission_can_block_reflection(): void
    {
        $policy = new MemoryPolicy(memoryWriteAdmission: static function (string $data, array $context) {
            if (($context['source'] ?? '') === 'reflection') {
                return 'reflection memory writes require review';
            }
            return true;
        });

        $userWrite = $policy->allowsWrite('remember', ['source' => 'user']);
        $reflectionWrite = $policy->allowsWrite('reflect', ['source' => 'reflection']);

        self::assertTrue($userWrite->allowed);
        self::assertFalse($reflectionWrite->allowed);
        self::assertStringContainsString('require review', (string) $reflectionWrite->reason);
    }

    public function test_write_admission_can_rewrite_before_store(): void
    {
        $policy = new MemoryPolicy(memoryWriteAdmission: static function (string $data, array $context) {
            return new MemoryAdmissionDecision(allowed: true, value: ($context['source'] ?? '') . '::' . strtoupper($data));
        });

        $decision = $policy->allowsWrite('hello', ['source' => 'user']);
        self::assertTrue($decision->allowed);
        self::assertSame('user::HELLO', $decision->value);
    }

    public function test_required_write_admission_fails_closed(): void
    {
        $policy = new MemoryPolicy(requireWriteAdmission: true);
        $missing = $policy->allowsWrite('unreviewed', ['user_id' => 'alice']);

        self::assertFalse($missing->allowed);
        self::assertSame('Memory write requires an explicit admission decision.', $missing->reason);
    }

    public function test_required_write_admission_needs_explicit_callback_approval(): void
    {
        $policy = new MemoryPolicy(
            requireWriteAdmission: true,
            memoryWriteAdmission: static fn (string $data, array $context) => null,
        );

        $undecided = $policy->allowsWrite('unreviewed', ['user_id' => 'alice']);

        self::assertFalse($undecided->allowed);
        self::assertSame('Memory write admission did not explicitly approve this write.', $undecided->reason);
    }

    public function test_write_admission_exception_fails_closed_and_redacts(): void
    {
        $policy = new MemoryPolicy(memoryWriteAdmission: static function (): void {
            throw new \RuntimeException('private review service details');
        });

        $decision = $policy->allowsWrite('unreviewed', ['user_id' => 'alice']);

        self::assertFalse($decision->allowed);
        self::assertSame('Memory write admission failed: RuntimeException', $decision->reason);
        self::assertStringNotContainsString('private review service details', (string) $decision->reason);
    }

    public function test_min_write_length_blocks_short_writes(): void
    {
        $policy = new MemoryPolicy(minWriteLength: 8);
        $short = $policy->allowsWrite('short', []);

        self::assertFalse($short->allowed);
        self::assertStringContainsString('min_write_length', (string) $short->reason);
    }

    public function test_reject_write_patterns(): void
    {
        $policy = new MemoryPolicy(rejectWritePatterns: ['ignore previous instructions']);
        $rejected = $policy->allowsWrite('please IGNORE previous instructions', []);

        self::assertFalse($rejected->allowed);
        self::assertStringContainsString('rejected by pattern', (string) $rejected->reason);
    }

    public function test_duplicate_fingerprints_are_scope_aware(): void
    {
        $policy = new MemoryPolicy(rejectDuplicateWrites: true);
        $firstContext = ['memory_user_id' => 'alice', 'source' => 'user', 'scope' => 'user', 'agent_name' => 'agent'];
        $secondContext = ['memory_user_id' => 'alice', 'source' => 'user', 'scope' => 'user', 'agent_name' => 'agent'];
        $reflectionContext = ['memory_user_id' => 'agent', 'source' => 'reflection', 'scope' => 'agent', 'agent_name' => 'agent'];
        $fingerprints = [];

        $first = $policy->allowsWrite('Remember   This', $firstContext, recentFingerprints: $fingerprints);
        self::assertTrue($first->allowed);
        $fingerprints[MemoryPolicy::writeFingerprint((string) $first->value, $firstContext)] = true;

        $duplicate = $policy->allowsWrite('remember this', $secondContext, recentFingerprints: $fingerprints);
        $reflection = $policy->allowsWrite('remember this', $reflectionContext, recentFingerprints: $fingerprints);

        self::assertFalse($duplicate->allowed);
        self::assertTrue($reflection->allowed);
    }

    public function test_write_fingerprint_normalization(): void
    {
        $a = MemoryPolicy::writeFingerprint('Remember   This', ['memory_user_id' => 'u']);
        $b = MemoryPolicy::writeFingerprint('remember this', ['memory_user_id' => 'u']);
        self::assertSame($a, $b);

        $c = MemoryPolicy::writeFingerprint('remember this', ['memory_user_id' => 'other']);
        self::assertNotSame($a, $c);
    }

    public function test_promotion_requires_explicit_admission_by_default(): void
    {
        $policy = new MemoryPolicy();
        $candidate = $this->makeCandidate();

        $decision = $policy->allowsPromotion($candidate);
        self::assertSame(MemoryPromotion::KEEP, $decision->action);
        self::assertFalse($decision->promotes());
    }

    public function test_promotion_admission_approve(): void
    {
        $policy = new MemoryPolicy(memoryPromotionAdmission: static function (MemoryCandidate $c, array $ctx) {
            self::assertSame('reflection', $c->source);
            self::assertSame($c->id(), $ctx['candidate_id']);
            return MemoryPromotionDecision::approve(metadata: ['reviewed_by' => 'policy']);
        });

        $candidate = $this->makeCandidate();
        $decision = $policy->allowsPromotion($candidate, ['candidate_id' => $candidate->id()]);
        self::assertSame(MemoryPromotion::APPROVE, $decision->action);
        self::assertTrue($decision->promotes());
        self::assertSame(['reviewed_by' => 'policy'], $decision->metadata);
    }

    public function test_promotion_admission_rewrite(): void
    {
        $policy = new MemoryPolicy(memoryPromotionAdmission: static fn (MemoryCandidate $c, array $ctx) => MemoryPromotionDecision::rewrite("reviewed::{$c->data}"));

        $decision = $policy->allowsPromotion($this->makeCandidate('reflection content'));
        self::assertSame(MemoryPromotion::REWRITE, $decision->action);
        self::assertSame('reviewed::reflection content', $decision->value);
        self::assertTrue($decision->promotes());
    }

    public function test_promotion_admission_exception_fails_closed(): void
    {
        $policy = new MemoryPolicy(memoryPromotionAdmission: static function (): void {
            throw new \RuntimeException('review service unavailable');
        });

        $decision = $policy->allowsPromotion($this->makeCandidate());
        self::assertSame(MemoryPromotion::KEEP, $decision->action);
        self::assertSame('Memory promotion admission failed: RuntimeException', $decision->reason);
        self::assertSame(['admission_error_type' => 'RuntimeException'], $decision->metadata);
    }

    public function test_promotion_decision_validates_action(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MemoryPromotionDecision(action: 'explode');
    }

    public function test_promotion_coercion_from_primitives(): void
    {
        $policy = new MemoryPolicy(memoryPromotionAdmission: static fn () => true);
        self::assertSame(MemoryPromotion::APPROVE, $policy->allowsPromotion($this->makeCandidate('v1'))->action);

        $policy2 = new MemoryPolicy(memoryPromotionAdmission: static fn () => false);
        self::assertSame(MemoryPromotion::REJECT, $policy2->allowsPromotion($this->makeCandidate())->action);

        $policy3 = new MemoryPolicy(memoryPromotionAdmission: static fn () => 'nope');
        self::assertSame(MemoryPromotion::REJECT, $policy3->allowsPromotion($this->makeCandidate())->action);
        self::assertSame('nope', $policy3->allowsPromotion($this->makeCandidate())->reason);
    }

    public function test_promotion_coercion_array_allowed_with_value_change_becomes_rewrite(): void
    {
        $policy = new MemoryPolicy(memoryPromotionAdmission: static fn () => ['allowed' => true, 'value' => 'new text', 'reason' => 'edited']);
        $decision = $policy->allowsPromotion($this->makeCandidate('old text'));

        self::assertSame(MemoryPromotion::REWRITE, $decision->action);
        self::assertSame('new text', $decision->value);
        self::assertSame('edited', $decision->reason);
    }

    public function test_promotion_coercion_array_allowed_same_value_is_approve(): void
    {
        $policy = new MemoryPolicy(memoryPromotionAdmission: static fn () => ['allowed' => true, 'value' => 'old text']);
        $decision = $policy->allowsPromotion($this->makeCandidate('old text'));

        self::assertSame(MemoryPromotion::APPROVE, $decision->action);
        self::assertSame('old text', $decision->value);
    }

    public function test_candidate_to_dict_and_metadata(): void
    {
        $candidate = new MemoryCandidate(
            data: 'private note',
            memoryUserId: 'agent',
            originalUserId: 'alice',
            source: 'reflection',
            scope: 'agent',
            agentName: 'writer',
            confidence: 0.7,
        );

        $dict = $candidate->toDict();
        self::assertSame('private note', $dict['data']);
        self::assertSame('reflection', $dict['source']);
        self::assertSame('agent', $dict['scope']);
        self::assertArrayNotHasKey('run_id', $dict); // None values filtered
        self::assertArrayHasKey('candidate_id', $dict);

        $noData = $candidate->toDict(includeData: false);
        self::assertArrayNotHasKey('data', $noData);

        $meta = $candidate->toMetadata();
        self::assertSame('agent', $meta['user_id']);
        self::assertSame('alice', $meta['original_user_id']);
        self::assertSame('candidate', $meta['promotion_status']);
        self::assertFalse($meta['injectable']);
    }

    public function test_candidate_with_updates_preserves_identity(): void
    {
        $candidate = $this->makeCandidate('original');
        $updated = $candidate->withUpdates(['status' => 'promoted', 'injectable' => true]);

        self::assertSame('promoted', $updated->status);
        self::assertTrue($updated->injectable);
        self::assertSame($candidate->id(), $updated->candidateId);
        self::assertSame('original', $updated->data);
        self::assertSame('candidate', $candidate->status);
    }

    public function test_candidate_ids_are_unique(): void
    {
        $a = $this->makeCandidate();
        $b = $this->makeCandidate();
        self::assertNotSame($a->id(), $b->id());
    }

    private function makeCandidate(string $data = 'reflection content'): MemoryCandidate
    {
        return new MemoryCandidate(
            data: $data,
            memoryUserId: 'agent',
            originalUserId: 'alice',
            source: 'reflection',
            scope: 'agent',
        );
    }
}
