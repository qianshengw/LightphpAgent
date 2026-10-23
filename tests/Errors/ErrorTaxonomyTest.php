<?php

declare(strict_types=1);

namespace LightAgent\Tests\Errors;

use LightAgent\Errors\ErrorTaxonomy;
use LightAgent\Errors\LightAgentError;
use LightAgent\Errors\LightAgentErrorInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ErrorTaxonomyTest extends TestCase
{
    public function test_known_code_returns_taxonomy_entry(): void
    {
        $info = ErrorTaxonomy::get('LA-401');
        self::assertSame('LA-401', $info->code);
        self::assertSame('Authentication failed.', $info->message);
        self::assertStringContainsString('api_key or OPENAI_API_KEY', $info->guidance);
    }

    public function test_unknown_code_falls_back_to_la_unknown(): void
    {
        $info = ErrorTaxonomy::get('LA-NOPE');
        self::assertSame('LA-UNKNOWN', $info->code);
    }

    public function test_error_uses_taxonomy_defaults(): void
    {
        $err = new LightAgentError('LA-429');
        self::assertSame('LA-429', $err->code2);
        self::assertSame(
            '[LA-429] Rate limit or quota was exceeded. Guidance: Wait before retrying, reduce concurrency, or check provider quota and billing status.',
            $err->getMessage(),
        );
    }

    public function test_error_overrides_and_details_rendering(): void
    {
        $err = new LightAgentError('LA-TOOL', 'boom', 'fix it', ['k' => 'v']);
        self::assertSame(
            '[LA-TOOL] boom Guidance: fix it Details: {"k":"v"}',
            $err->getMessage(),
        );
    }

    public function test_error_without_details_has_no_details_suffix(): void
    {
        $err = new LightAgentError('LA-JSON');
        self::assertSame(
            '[LA-JSON] A tool call argument could not be parsed as JSON. Guidance: Check that the model returned valid tool-call JSON matching the registered tool schema.',
            $err->getMessage(),
        );
    }

    public static function statusCodeProvider(): array
    {
        return [
            '400' => [400, 'LA-400'],
            '401' => [401, 'LA-401'],
            '403' => [403, 'LA-403'],
            '404' => [404, 'LA-404'],
            '408' => [408, 'LA-408'],
            '413' => [413, 'LA-413'],
            '429' => [429, 'LA-429'],
            '500' => [500, 'LA-500'],
            '502' => [502, 'LA-500'],
            '503' => [503, 'LA-503'],
            '504' => [504, 'LA-503'],
        ];
    }

    #[DataProvider('statusCodeProvider')]
    public function test_classify_by_status_code_property(int $status, string $expectedCode): void
    {
        $exc = new class ('provider error', $status) extends \RuntimeException {
            public function __construct(string $message, public readonly int $statusCode)
            {
                parent::__construct($message);
            }
        };

        self::assertSame($expectedCode, ErrorTaxonomy::classify($exc)->code);
    }

    public function test_classify_by_response_object(): void
    {
        $response = new class {
            public int $statusCode = 429;
        };
        $exc = new class ('limited', $response) extends \RuntimeException {
            public function __construct(string $message, public readonly object $response)
            {
                parent::__construct($message);
            }
        };

        self::assertSame('LA-429', ErrorTaxonomy::classify($exc)->code);
    }

    public function test_classify_by_message_heuristics(): void
    {
        self::assertSame('LA-401', ErrorTaxonomy::classify(new \RuntimeException('invalid api key provided'))->code);
        self::assertSame('LA-403', ErrorTaxonomy::classify(new \RuntimeException('request forbidden by policy'))->code);
        self::assertSame('LA-404', ErrorTaxonomy::classify(new \RuntimeException('model not found'))->code);
        self::assertSame('LA-408', ErrorTaxonomy::classify(new \RuntimeException('request timed out'))->code);
        self::assertSame('LA-429', ErrorTaxonomy::classify(new \RuntimeException('rate limit exceeded'))->code);
        self::assertSame('LA-413', ErrorTaxonomy::classify(new \RuntimeException('maximum context length exceeded'))->code);
    }

    public function test_classify_by_exception_name_heuristics(): void
    {
        $exc = new \LightAgent\Tests\Errors\Fixture\AuthenticationError('bad key');
        self::assertSame('LA-401', ErrorTaxonomy::classify($exc)->code);

        $nf = new \LightAgent\Tests\Errors\Fixture\NotFoundError('gone');
        self::assertSame('LA-404', ErrorTaxonomy::classify($nf)->code);
    }

    public function test_classify_default_code(): void
    {
        $exc = new \RuntimeException('something entirely unknown');
        self::assertSame('LA-UNKNOWN', ErrorTaxonomy::classify($exc)->code);
        self::assertSame('LA-TOOL', ErrorTaxonomy::classify($exc, 'LA-TOOL')->code);
    }

    public function test_format_exception_includes_action_and_details(): void
    {
        $exc = new \RuntimeException('request timed out');
        $text = ErrorTaxonomy::formatException($exc, action: 'calling model');
        self::assertStringStartsWith('[LA-408] The model request timed out. Action: calling model.', $text);
        self::assertStringContainsString('Guidance:', $text);
        self::assertStringContainsString('Details: request timed out', $text);
    }

    public function test_format_code_with_details(): void
    {
        $text = ErrorTaxonomy::formatCode('LA-SESSION', 'sqlite locked');
        self::assertSame(
            '[LA-SESSION] Session persistence or replay failed. Guidance: Inspect the SessionStore, event sequence, schema version, and latest checkpoint. Details: sqlite locked',
            $text,
        );
    }

    public function test_error_info_is_immutable_shape(): void
    {
        $ref = new \ReflectionClass(LightAgentErrorInfo::class);
        foreach ($ref->getProperties() as $prop) {
            self::assertTrue($prop->isReadOnly(), "property {$prop->getName()} should be readonly");
        }
    }
}
