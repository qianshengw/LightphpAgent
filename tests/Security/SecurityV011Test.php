<?php

declare(strict_types=1);

namespace LightAgent\Tests\Security;

use LightAgent\Capabilities\BaseCapabilityProvider;
use LightAgent\Capabilities\CapabilityRegistry;
use LightAgent\Capabilities\CapabilityRisk;
use LightAgent\Capabilities\CapabilitySpec;
use LightAgent\Capabilities\PermissionSet;
use LightAgent\Security\ApprovalToken;
use LightAgent\Security\CapabilityGate;
use LightAgent\Security\ProviderManifest;
use LightAgent\Security\SecurityContext;
use PHPUnit\Framework\TestCase;

/**
 * PHP port of tests/test_v011_security.py (subset not requiring AgentRuntime
 * security binding, which lands with the full AgentRuntime in this phase).
 */
final class SecurityV011Test extends TestCase
{
    private function makeContext(array $kwargs = []): SecurityContext
    {
        $values = [
            'userId' => 'alice',
            'tenantId' => 'tenant-a',
            'projectId' => 'project-a',
            'runId' => 'run-a',
            'permissions' => new PermissionSet(
                allowed: ['tool.read', 'tool.write'],
                maxRisk: CapabilityRisk::SENSITIVE,
            ),
            'resources' => ['workspace:a', 'db:reports'],
            'networkAllowed' => false,
            'sandboxRequired' => true,
            'policyVersion' => 'policy-7',
        ];
        foreach ($kwargs as $key => $value) {
            $values[$key] = $value;
        }
        return new SecurityContext(
            userId: $values['userId'],
            tenantId: $values['tenantId'],
            projectId: $values['projectId'],
            runId: $values['runId'],
            permissions: $values['permissions'],
            resources: $values['resources'],
            networkAllowed: $values['networkAllowed'],
            sandboxRequired: $values['sandboxRequired'],
            policyVersion: $values['policyVersion'],
            deadline: $values['deadline'] ?? null,
        );
    }

    public function test_security_context_round_trip_and_narrowing(): void
    {
        $parent = $this->makeContext();
        $child = $parent->narrow(
            taskId: 'task-a',
            attemptId: 'attempt-a',
            agentId: 'worker-a',
            allowedCapabilities: ['tool.read'],
            resources: ['workspace:a'],
            maxRisk: CapabilityRisk::READ_ONLY,
        );

        $restored = SecurityContext::fromDict($child->toDict());

        self::assertSame('task-a', $restored->taskId);
        // Python: parent.narrow() with parent.agent_id=None -> parent_agent_id stays None
        self::assertNull($restored->parentAgentId);
        self::assertSame(['tool.read'], $restored->permissions->allowed);
        self::assertSame(['workspace:a'], $restored->resources);

        try {
            $child->narrow(allowedCapabilities: ['tool.read', 'tool.write']);
            self::fail('expected capability escalation error');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('cannot add', $e->getMessage());
        }
        try {
            $child->narrow(resources: ['workspace:a', 'workspace:b']);
            self::fail('expected resource escalation error');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('cannot add resources', $e->getMessage());
        }
        try {
            $child->narrow(networkAllowed: true);
            self::fail('expected network error');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('network', $e->getMessage());
        }
        try {
            $child->narrow(sandboxRequired: false);
            self::fail('expected sandbox error');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('sandbox', $e->getMessage());
        }
    }

    public function test_approval_token_is_argument_identity_and_policy_bound(): void
    {
        $context = $this->makeContext(['taskId' => 'task-a', 'attemptId' => 'attempt-a']);
        $token = ApprovalToken::issue(
            'tool.write',
            ['path' => 'report.txt', 'content' => 'ok'],
            $context,
            resource: 'workspace:a',
        );

        $token->verify(
            'tool.write',
            ['content' => 'ok', 'path' => 'report.txt'],
            $context,
            resource: 'workspace:a',
        );
        self::assertNotNull($token->consumedAt);
        try {
            $token->verify(
                'tool.write',
                ['path' => 'report.txt', 'content' => 'ok'],
                $context,
                resource: 'workspace:a',
            );
            self::fail('expected consumed error');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('consumed', $e->getMessage());
        }

        $changed = ApprovalToken::issue('tool.write', ['path' => 'report.txt'], $context);
        try {
            $changed->verify('tool.write', ['path' => 'other.txt'], $context);
            self::fail('expected mismatch error');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('does not match', $e->getMessage());
        }

        $restored = ApprovalToken::fromDict(
            ApprovalToken::issue('tool.write', ['path' => 'report.txt'], $context)->toDict(),
        );
        $restored->verify('tool.write', ['path' => 'report.txt'], $context);
        $this->addToAssertionCount(1);
    }

    public function test_security_context_normalizes_deadlines(): void
    {
        $parentDeadline = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+10 minutes')
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u0'); // naive-style (no offset suffix kept simple)
        $childDeadline = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+5 minutes')
            ->format(\DateTimeInterface::ATOM);
        $parent = $this->makeContext(['deadline' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+10 minutes')->format(\DateTimeInterface::ATOM)]);

        $child = $parent->narrow(deadline: $childDeadline);

        self::assertFalse($child->isExpired());
    }

    public function test_capability_gate_requires_bound_approval_and_invokes_provider(): void
    {
        $provider = new class extends BaseCapabilityProvider {
            public function __construct()
            {
                parent::__construct([new CapabilitySpec(
                    'tool.write',
                    risk: CapabilityRisk::SENSITIVE,
                    write: true,
                    requiresApproval: true,
                )]);
                $this->name = 'writer';
                $this->version = '2';
            }

            public function invoke(string $capability, mixed ...$arguments): mixed
            {
                return $arguments['value'];
            }
        };

        $registry = new CapabilityRegistry();
        $registry->register($provider);
        $gate = new CapabilityGate($registry);
        $context = $this->makeContext(['resources' => []]);

        try {
            $gate->invoke('tool.write', ['value' => 3], $context);
            self::fail('expected approval requirement');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('requires approval', $e->getMessage());
        }

        $token = ApprovalToken::issue('tool.write', ['value' => 3], $context);
        self::assertSame(3, $gate->invoke('tool.write', ['value' => 3], $context, approvalToken: $token));
    }

    public function test_provider_manifest_hashes_secret_configuration(): void
    {
        $provider = new class extends BaseCapabilityProvider {
            public function __construct()
            {
                parent::__construct([new CapabilitySpec('tool.read', read: true)]);
                $this->name = 'provider-a';
                $this->version = '4';
                $this->config = ['api_key' => 'super-secret', 'region' => 'local'];
            }
        };

        $manifest = ProviderManifest::fromProvider($provider);
        $rendered = print_r($manifest->toDict(), true);

        self::assertSame(['tool.read'], $manifest->capabilities);
        self::assertStringNotContainsString('super-secret', $rendered);
        self::assertSame(64, strlen($manifest->configurationDigest));
    }

    public function test_registry_accepts_security_context_without_bypassing_approval(): void
    {
        $provider = new class extends BaseCapabilityProvider {
            public function __construct()
            {
                parent::__construct([new CapabilitySpec(
                    'tool.write',
                    risk: CapabilityRisk::SENSITIVE,
                    write: true,
                    requiresApproval: true,
                )]);
                $this->name = 'writer';
            }

            public function invoke(string $capability, mixed ...$arguments): mixed
            {
                \PHPUnit\Framework\Assert::assertNull($this->context);
                return $arguments['value'];
            }
        };

        $registry = new CapabilityRegistry();
        $registry->register($provider);
        $context = $this->makeContext(['resources' => []]);
        $token = ApprovalToken::issue('tool.write', ['value' => 7], $context);

        $result = $registry->invoke(
            'tool.write',
            ['value' => 7],
            context: new \LightAgent\Capabilities\RuntimeContext(
                securityContext: $context,
            ),
            approvalToken: $token,
        );

        self::assertSame(7, $result);
        self::assertNotNull($token->consumedAt);
    }

    // ------------------------------------------------------------------
    // H-1 · a serialised permission snapshot must never load as
    // "unrestricted" unless it is a legacy payload
    // ------------------------------------------------------------------

    public function test_current_schema_permission_payloads_fail_closed(): void
    {
        $payloads = [
            'no allowlist key' => ['schema_version' => 2, 'permissions' => []],
            'the string "false"' => ['schema_version' => 2, 'permissions' => ['allowed' => [], 'unrestricted' => 'false']],
            'a non-array permissions value' => ['schema_version' => 2, 'permissions' => 'oops'],
            'no permissions key at all' => ['schema_version' => 2],
            'no schema version' => ['permissions' => []],
        ];
        foreach ($payloads as $label => $payload) {
            $restored = SecurityContext::fromDict($payload);
            self::assertFalse(
                $restored->permissions->isUnrestricted(),
                "{$label} must not load as unrestricted",
            );
            self::assertFalse(
                $restored->permissions->allows('dangerous.write', CapabilityRisk::DESTRUCTIVE),
                "{$label} must deny every capability",
            );
        }
    }

    public function test_legacy_schema_permission_payload_stays_unrestricted(): void
    {
        $restored = SecurityContext::fromDict(['schema_version' => 1, 'permissions' => []]);
        self::assertTrue(
            $restored->permissions->isUnrestricted(),
            'a schema 1 payload keeps the legacy meaning',
        );
    }

    // ------------------------------------------------------------------
    // V5-10 / V5-11 · a relative deadline is resolved exactly once
    // ------------------------------------------------------------------

    public function test_a_relative_deadline_is_resolved_once_and_inherited_by_the_child(): void
    {
        $parent = new SecurityContext(userId: 'u', deadline: '+5 minutes');
        $child = $parent->narrow(taskId: 'task-1', agentId: 'worker');

        self::assertSame($parent->deadline, $child->deadline, 'the child inherits the parent deadline');
        self::assertFalse($child->isExpired());
        self::assertNotSame('+5 minutes', $parent->deadline, 'a relative deadline is stored as an instant');
    }

    public function test_a_relative_deadline_expires(): void
    {
        $context = new SecurityContext(userId: 'u', deadline: '+1 seconds');
        self::assertFalse($context->isExpired());
        sleep(2);
        self::assertTrue($context->isExpired(), 'the deadline must become effective');
    }

    public function test_narrow_still_rejects_a_deadline_beyond_the_parent(): void
    {
        $parent = new SecurityContext(userId: 'u', deadline: '+5 minutes');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot extend the deadline');
        $parent->narrow(deadline: '+10 minutes');
    }

    // ------------------------------------------------------------------
    // H-11 / H-13 · the approval token
    // ------------------------------------------------------------------

    public function test_a_restored_token_is_not_reusable_when_the_flag_says_false(): void
    {
        $token = ApprovalToken::fromDict([
            'operation' => 'tool.write',
            'arguments_digest' => 'digest',
            'policy_version' => '1',
            'reusable' => 'false',
        ]);

        self::assertFalse($token->reusable, 'the string "false" must not be read as true');
    }

    public function test_a_mismatching_approval_raises_a_permission_error(): void
    {
        $context = $this->makeContext();
        $token = ApprovalToken::issue('tool.write', ['path' => 'a.txt'], $context);

        $this->expectException(\LightAgent\Security\PermissionError::class);
        $this->expectExceptionMessage('does not match');
        $token->verify('tool.write', ['path' => 'b.txt'], $context);
    }

    // ------------------------------------------------------------------
    // M-42 · the approval covers the arguments that really run
    // ------------------------------------------------------------------

    public function test_a_policy_rewrite_invalidates_the_approval(): void
    {
        $provider = new class extends BaseCapabilityProvider {
            public function __construct()
            {
                parent::__construct([new CapabilitySpec(
                    'tool.write',
                    risk: CapabilityRisk::SENSITIVE,
                    write: true,
                    requiresApproval: true,
                )]);
                $this->name = 'writer';
            }

            public function invoke(string $capability, mixed ...$arguments): mixed
            {
                return $arguments['path'] ?? null;
            }
        };
        $registry = new CapabilityRegistry(policyEngine: new \LightAgent\Capabilities\PolicyEngine([
            static fn (object $request): \LightAgent\Capabilities\PolicyDecision
                => \LightAgent\Capabilities\PolicyDecision::allow(['path' => '/etc/passwd']),
        ]));
        $registry->register($provider);
        $gate = new CapabilityGate($registry);
        $context = $this->makeContext(['resources' => []]);
        $token = ApprovalToken::issue('tool.write', ['path' => '/safe/x'], $context);

        $this->expectException(\LightAgent\Security\PermissionError::class);
        $gate->authorize(
            $provider->capabilities()['tool.write'],
            'writer',
            ['path' => '/safe/x'],
            $context,
            approvalToken: $token,
        );
    }

    public function test_a_policy_rejection_does_not_consume_the_approval(): void
    {
        $provider = new class extends BaseCapabilityProvider {
            public function __construct()
            {
                parent::__construct([new CapabilitySpec(
                    'tool.write',
                    risk: CapabilityRisk::SENSITIVE,
                    write: true,
                    requiresApproval: true,
                )]);
                $this->name = 'writer';
            }

            public function invoke(string $capability, mixed ...$arguments): mixed
            {
                return null;
            }
        };
        $registry = new CapabilityRegistry(policyEngine: new \LightAgent\Capabilities\PolicyEngine([
            static fn (object $request): \LightAgent\Capabilities\PolicyDecision
                => \LightAgent\Capabilities\PolicyDecision::block('nope'),
        ]));
        $registry->register($provider);
        $gate = new CapabilityGate($registry);
        $context = $this->makeContext(['resources' => []]);
        $token = ApprovalToken::issue('tool.write', ['path' => '/safe/x'], $context);

        try {
            $gate->authorize(
                $provider->capabilities()['tool.write'],
                'writer',
                ['path' => '/safe/x'],
                $context,
                approvalToken: $token,
            );
            self::fail('expected the policy to deny the call');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('nope', $e->getMessage());
        }
        self::assertNull($token->consumedAt, 'a denied call must not burn the approval');
    }

    // ------------------------------------------------------------------
    // V5-15 · an argument key must never collide with the provider's
    // own parameter names
    // ------------------------------------------------------------------

    public function test_an_argument_named_capability_does_not_crash_the_provider(): void
    {
        $provider = new class extends BaseCapabilityProvider {
            public function __construct()
            {
                parent::__construct([new CapabilitySpec('tool.demo', execute: true)]);
                $this->name = 'demo';
            }

            public function invoke(string $capability, mixed ...$arguments): mixed
            {
                return $arguments;
            }
        };
        $registry = new CapabilityRegistry();
        $registry->register($provider);

        $result = $registry->invoke('tool.demo', ['capability' => 'x', 'text' => 'hello']);
        self::assertIsArray($result);
        self::assertSame('hello', $result['text'] ?? null);
        self::assertContains('x', $result, 'the colliding argument is still delivered');
    }
}
