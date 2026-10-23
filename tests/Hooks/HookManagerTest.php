<?php

declare(strict_types=1);

namespace LightAgent\Tests\Hooks;

use LightAgent\Hooks\HookContext;
use LightAgent\Hooks\HookManager;
use LightAgent\Hooks\PolicyHook;
use PHPUnit\Framework\TestCase;

/**
 * Failure-mode and timeout contract of the hook manager.
 */
final class HookManagerTest extends TestCase
{
    public function test_a_plain_hook_that_throws_blocks_in_security_sensitive_phases(): void
    {
        $throwing = static function (HookContext $ctx): void {
            throw new \RuntimeException('boom');
        };
        $manager = new HookManager([$throwing]);

        foreach (['before_run', 'before_model_request', 'after_model_response', 'before_tool_call'] as $phase) {
            $decision = $manager->run(new HookContext(phase: $phase, payload: []));
            self::assertSame(
                'block',
                $decision->action,
                "a hook failure in `{$phase}` must block, not continue",
            );
        }
    }

    public function test_a_plain_hook_that_throws_still_continues_in_observability_phases(): void
    {
        $throwing = static function (HookContext $ctx): void {
            throw new \RuntimeException('boom');
        };
        $manager = new HookManager([$throwing]);

        $decision = $manager->run(new HookContext(phase: 'after_run', payload: []));
        self::assertSame('continue', $decision->action);
    }

    public function test_a_policy_hook_keeps_its_own_failure_mode(): void
    {
        $throwing = static function (HookContext $ctx): void {
            throw new \RuntimeException('boom');
        };
        $manager = new HookManager([
            new PolicyHook($throwing, phases: ['before_tool_call'], failureMode: 'continue'),
        ]);

        $decision = $manager->run(new HookContext(phase: 'before_tool_call', payload: []));
        self::assertSame('continue', $decision->action);
    }

    public function test_an_array_verdict_is_parsed_strictly(): void
    {
        $deny = new HookManager([static fn (HookContext $ctx): array => ['allowed' => 'false']]);
        self::assertSame(
            'block',
            $deny->run(new HookContext(phase: 'after_run', payload: []))->action,
            'the string "false" must not be read as an allow',
        );

        $allow = new HookManager([static fn (HookContext $ctx): array => ['allowed' => 'true']]);
        self::assertSame('continue', $allow->run(new HookContext(phase: 'after_run', payload: []))->action);

        $noOpinion = new HookManager([static fn (HookContext $ctx): array => ['reason' => 'no opinion']]);
        self::assertSame('continue', $noOpinion->run(new HookContext(phase: 'after_run', payload: []))->action);
    }

    /**
     * A handler that YIELDS is bounded by the event loop.
     */
    public function test_a_yielding_handler_is_timed_out(): void
    {
        $slow = new PolicyHook(
            handler: static function (HookContext $ctx): void {
                \LightAgent\Async\AsyncRuntime::sleep(2);
            },
            phases: ['before_tool_call'],
            failureMode: 'block',
            timeout: 0.05,
        );

        $decision = (new HookManager([$slow]))->run(new HookContext(phase: 'before_tool_call', payload: []));
        self::assertSame('block', $decision->action);
        $events = $decision->metadata['hook_events'] ?? [];
        self::assertSame('timeout', $events[0]['error_type'] ?? null);
    }

    /**
     * A handler that BLOCKS cannot be preempted in PHP — the overrun is
     * detected after the handler returned and reported as the configured
     * failure mode.
     */
    public function test_a_blocking_handler_that_overruns_is_reported_as_a_timeout(): void
    {
        $blocking = new PolicyHook(
            handler: static function (HookContext $ctx): void {
                sleep(1);
            },
            phases: ['before_tool_call'],
            failureMode: 'block',
            timeout: 0.05,
        );

        $decision = (new HookManager([$blocking]))->run(new HookContext(phase: 'before_tool_call', payload: []));
        self::assertSame('block', $decision->action);
        $events = $decision->metadata['hook_events'] ?? [];
        self::assertSame('timeout', $events[0]['error_type'] ?? null);
        self::assertStringContainsString('timed out', (string) ($events[0]['error'] ?? ''));
    }
}
