<?php

declare(strict_types=1);

namespace LightAgent\Tests\Guardrails;

use LightAgent\Guardrails\GuardrailFactories;
use LightAgent\Guardrails\GuardrailManager;
use PHPUnit\Framework\TestCase;

/**
 * Contract of the built-in guardrail factories.
 */
final class GuardrailFactoriesTest extends TestCase
{
    private function privacy(): GuardrailManager
    {
        return new GuardrailManager(inputGuardrails: [GuardrailFactories::privacyInput()]);
    }

    public function test_the_privacy_patterns_cover_bare_api_keys(): void
    {
        foreach ([
            'sk-abcdefghijklmnopqrstuvwx',
            'ak_abcdefghijklmnop',
            'pk-abcdefghijklmnop',
            'ghp_abcdefghijklmnopqrstuvwxyz012345',
            'AIzaSyA0123456789abcdefghijklmnopqrstuv',
        ] as $sample) {
            self::assertFalse(
                $this->privacy()->checkInput($sample)->allowed,
                "a bare API key ({$sample}) must be blocked",
            );
        }
    }

    public function test_the_privacy_patterns_cover_a_bearer_token(): void
    {
        self::assertFalse(
            $this->privacy()->checkInput('Authorization: Bearer abcdefghijklmnopqrstuvwxyz')->allowed,
            'a bearer token must be blocked',
        );
    }

    public function test_the_privacy_patterns_cover_a_chinese_mobile_number(): void
    {
        self::assertFalse(
            $this->privacy()->checkInput('call me on 13800138000')->allowed,
            'a Chinese mobile number must be blocked',
        );
    }

    public function test_the_existing_privacy_patterns_still_work(): void
    {
        foreach (['123-45-6789', '4111111111111111', 'someone@example.com', 'api_key=abcdef'] as $sample) {
            self::assertFalse(
                $this->privacy()->checkInput($sample)->allowed,
                "the pre-existing pattern for `{$sample}` must still block",
            );
        }
        self::assertTrue($this->privacy()->checkInput('hello world')->allowed);
    }

    public function test_high_risk_parameter_accepts_any_callable_rule(): void
    {
        $invokable = new class {
            public function __invoke(mixed $value): bool
            {
                return $value !== '/etc';
            }
        };
        $manager = new GuardrailManager(toolGuardrails: [
            GuardrailFactories::highRiskParameter(['path' => $invokable]),
        ]);

        self::assertFalse($manager->checkTool('t', ['path' => '/etc'])->allowed);
        self::assertTrue($manager->checkTool('t', ['path' => '/tmp'])->allowed);
    }

    public function test_high_risk_parameter_fails_closed_on_an_unknown_rule_type(): void
    {
        $manager = new GuardrailManager(toolGuardrails: [
            GuardrailFactories::highRiskParameter(['path' => 42]),
        ]);

        self::assertFalse(
            $manager->checkTool('t', ['path' => '/tmp'])->allowed,
            'a rule that cannot be understood must never be mistaken for a rule that passed',
        );
    }

    public function test_high_risk_parameter_still_accepts_a_list_of_values(): void
    {
        $manager = new GuardrailManager(toolGuardrails: [
            GuardrailFactories::highRiskParameter(['path' => ['a', 'b']]),
        ]);

        self::assertTrue($manager->checkTool('t', ['path' => 'a'])->allowed);
        self::assertFalse($manager->checkTool('t', ['path' => 'c'])->allowed);
    }
}
