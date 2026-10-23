<?php

declare(strict_types=1);

namespace LightAgent\Tests\Capabilities;

use LightAgent\Capabilities\BaseCapabilityProvider;
use LightAgent\Capabilities\CapabilityRegistry;
use LightAgent\Capabilities\CapabilityRisk;
use LightAgent\Capabilities\CapabilityScope;
use LightAgent\Capabilities\CapabilitySpec;
use LightAgent\Capabilities\PolicyDecision;
use LightAgent\Capabilities\PolicyEngine;
use LightAgent\Capabilities\PermissionSet;
use LightAgent\Capabilities\ProviderHealth;
use LightAgent\Capabilities\RuntimeContext;
use PHPUnit\Framework\TestCase;

/**
 * PHP port of tests/test_v010_capabilities.py.
 */
final class CapabilitiesV010Test extends TestCase
{
    private function echoProvider(string $prefix = ''): EchoProvider
    {
        return new EchoProvider($prefix);
    }

    public function test_provider_lifecycle_and_health(): void
    {
        $provider = $this->echoProvider();
        $context = new RuntimeContext(runtimeId: 'runtime');

        $provider->mount($context);
        $provider->start();
        $health = $provider->health();
        $provider->stop();
        $provider->unmount();

        self::assertEquals(new ProviderHealth(healthy: true, status: 'ready'), $health);
        self::assertFalse($provider->mounted);
    }

    public function test_registry_scope_precedence_and_conflict_diagnostics(): void
    {
        $registry = new CapabilityRegistry();
        $registry->register($this->echoProvider('runtime:'), scope: CapabilityScope::RUNTIME);
        $registry->register($this->echoProvider('agent:'), scope: CapabilityScope::AGENT, ownerId: 'a1');

        $result = $registry->invoke('text.echo', ['text' => 'hello'], context: new RuntimeContext(agentId: 'a1'));

        self::assertSame('agent:hello', $result);
        self::assertSame([], $registry->conflicts());
    }

    public function test_equal_scope_conflict_is_diagnostic_and_latest_wins(): void
    {
        $registry = new CapabilityRegistry();
        $registry->register($this->echoProvider('one:'));
        $second = $this->echoProvider('two:');
        $second->name = 'echo-two';
        $registry->register($second);

        self::assertSame('echo-two', $registry->conflicts()[0]['winner']);
        self::assertSame('two:x', $registry->invoke('text.echo', ['text' => 'x']));
    }

    public function test_permission_snapshot_cannot_escalate(): void
    {
        $parent = new PermissionSet(
            allowed: ['text.echo'],
            maxRisk: CapabilityRisk::ISOLATED_WRITE,
        );

        $child = $parent->narrow(allowed: ['text.echo'], maxRisk: CapabilityRisk::READ_ONLY);

        self::assertTrue($child->allows('text.echo'));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot add');
        $parent->narrow(allowed: ['text.echo', 'shell.execute']);
    }

    public function test_policy_can_rewrite_or_deny_arguments(): void
    {
        $policy = static function (object $request) {
            if (($request->arguments['text'] ?? null) === 'deny') {
                return PolicyDecision::block('blocked by test');
            }
            return PolicyDecision::allow(['text' => strtoupper((string) $request->arguments['text'])]);
        };

        $registry = new CapabilityRegistry(policyEngine: new PolicyEngine([$policy]));
        $registry->register($this->echoProvider());

        self::assertSame('OK', $registry->invoke('text.echo', ['text' => 'ok']));
        try {
            $registry->invoke('text.echo', ['text' => 'deny']);
            self::fail('expected denial');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('blocked by test', $e->getMessage());
        }
    }

    public function test_audit_contains_digest_but_not_credentials(): void
    {
        $events = [];
        $registry = new CapabilityRegistry(audit: static function (string $event, array $data) use (&$events): void {
            $events[] = [$event, $data];
        });
        $registry->register($this->echoProvider());

        $registry->invoke('text.echo', ['text' => 'ok']);

        $decision = null;
        foreach ($events as [$event, $data]) {
            if ($event === 'policy.decision') {
                $decision = $data;
                break;
            }
        }
        self::assertNotNull($decision);
        self::assertSame(64, strlen((string) $decision['configuration_digest']));
        self::assertStringNotContainsString('do-not-audit', print_r($decision, true));
    }

    /**
     * @return array<string, array{0: bool, 1: bool}>
     */
    public static function failClosedProvider(): array
    {
        return ['fail_closed blocks' => [true, false], 'fail_open continues' => [false, true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failClosedProvider')]
    public function test_policy_exception_respects_fail_closed_mode(bool $failClosed, bool $allowed): void
    {
        $brokenPolicy = static function (object $request): never {
            throw new \RuntimeException('policy backend unavailable');
        };

        $engine = new PolicyEngine([$brokenPolicy], failClosed: $failClosed);
        $request = new RuntimeContext();
        $registry = new CapabilityRegistry(policyEngine: $engine);
        $registry->register($this->echoProvider());

        if ($allowed) {
            self::assertSame('ok', $registry->invoke('text.echo', ['text' => 'ok'], context: $request));
        } else {
            try {
                $registry->invoke('text.echo', ['text' => 'ok'], context: $request);
                self::fail('expected policy failure block');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('failed: RuntimeException', $e->getMessage());
            }
        }
    }

    public function test_capability_requires_approval_before_provider_invocation(): void
    {
        $provider = new ControlledProvider(new CapabilitySpec('dangerous.write', requiresApproval: true));
        $registry = new CapabilityRegistry();
        $registry->register($provider);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires approval');
        $registry->invoke('dangerous.write');
    }

    public function test_capability_output_limit_is_enforced(): void
    {
        $limited = new ControlledProvider(new CapabilitySpec('limited.read', outputLimit: 4), result: 'abcdefgh');
        $limited->name = 'limited';
        $registry = new CapabilityRegistry();
        $registry->register($limited);

        self::assertSame('abcd', $registry->invoke('limited.read'));
    }

    public function test_registry_lifecycle_reload_unregister_and_reverse_stop_order(): void
    {
        $calls = [];

        $first = new LifecycleEchoProvider('first', $calls);
        $second = new LifecycleEchoProvider('second', $calls);
        $registry = new CapabilityRegistry();
        $registry->register($first);
        $registry->register($second);

        $registry->mount(new RuntimeContext(runtimeId: 'runtime'));
        $registry->reload('first', ['mode' => 'strict']);
        self::assertFalse($registry->unregister('missing'));
        self::assertTrue($registry->unregister('second'));
        $registry->stop();

        self::assertSame(['mode' => 'strict'], $first->config);
        self::assertSame(
            ['stop:second', 'unmount:second', 'stop:first', 'unmount:first'],
            $calls,
        );
    }

    /**
     * M-40: the memory adapter takes the caller's identity from the
     * INVOCATION CONTEXT, never from the model-supplied arguments.
     */
    public function test_the_memory_adapter_takes_the_identity_from_the_context(): void
    {
        $backend = new class {
            /** @var list<array{0: string, 1: string, 2: string}> */
            public array $calls = [];

            public function retrieve(string $query, string $userId): string
            {
                $this->calls[] = ['retrieve', $query, $userId];
                return $userId;
            }

            public function store(string $data, string $userId): string
            {
                $this->calls[] = ['store', $data, $userId];
                return $userId;
            }
        };
        $adapter = new \LightAgent\Capabilities\MemoryProviderAdapter($backend);
        $adapter->bindContext(new RuntimeContext(userId: 'alice'));

        $adapter->invoke('memory.retrieve', query: 'q', user_id: 'victim');

        self::assertSame('alice', $backend->calls[0][2], 'the caller identity comes from the context');
    }

    public function test_the_memory_adapter_fails_closed_without_an_identity(): void
    {
        $backend = new class {
            public function retrieve(string $query, string $userId): string
            {
                return $userId;
            }

            public function store(string $data, string $userId): string
            {
                return $userId;
            }
        };
        $adapter = new \LightAgent\Capabilities\MemoryProviderAdapter($backend);

        $this->expectException(\RuntimeException::class);
        $adapter->invoke('memory.retrieve', query: 'q', user_id: 'victim');
    }

    /**
     * V5-18: the tool capabilities are built on demand, so a tool that is
     * registered after the adapter was constructed is still a capability.
     */
    public function test_a_tool_registered_late_is_still_a_capability(): void
    {
        $toolRegistry = new \LightAgent\Tools\ToolRegistry();
        $adapter = new \LightAgent\Capabilities\ToolProviderAdapter($toolRegistry);
        self::assertSame([], $adapter->capabilities());

        $toolRegistry->registerTool(new \LightAgent\Tools\ToolFunction(
            callable: static fn (): string => 'ok',
            toolInfo: [
                'tool_name' => 'my_custom_tool',
                'tool_description' => 'demo',
                'tool_params' => [],
            ],
        ));

        self::assertArrayHasKey('tool.my_custom_tool', $adapter->capabilities());
    }
}

/**
 * Python EchoProvider port.
 */
class EchoProvider extends BaseCapabilityProvider
{
    public function __construct(private readonly string $prefix = '')
    {
        parent::__construct([new CapabilitySpec('text.echo', read: true, timeout: 0.1)]);
        $this->name = 'echo';
        $this->version = 'test';
        $this->config = ['prefix' => $prefix, 'api_key' => 'do-not-audit'];
    }

    public function invoke(string $capability, mixed ...$arguments): mixed
    {
        return $this->prefix . ($arguments['text'] ?? '');
    }
}

/**
 * Python ControlledProvider port.
 */
final class ControlledProvider extends BaseCapabilityProvider
{
    public function __construct(
        CapabilitySpec $spec,
        private readonly string $result = 'result',
    ) {
        parent::__construct([$spec]);
        $this->name = 'controlled';
    }

    public function invoke(string $capability, mixed ...$arguments): mixed
    {
        return $this->result;
    }
}

/**
 * Lifecycle-tracking provider for stop-order verification.
 */
final class LifecycleEchoProvider extends EchoProvider
{
    /** @var list<string> */
    private array $callsRef;

    public function __construct(string $name, array &$calls)
    {
        parent::__construct();
        $this->name = $name;
        $this->callsRef = &$calls;
    }

    public function stop(): void
    {
        $this->callsRef[] = "stop:{$this->name}";
        parent::stop();
    }

    public function unmount(): void
    {
        $this->callsRef[] = "unmount:{$this->name}";
        parent::unmount();
    }
}
