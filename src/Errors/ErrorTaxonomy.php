<?php

declare(strict_types=1);

namespace LightAgent\Errors;

/**
 * LightAgent error taxonomy and classification helpers.
 *
 * Ported from errors.py: stable codes + user-facing formatting.
 */
final class ErrorTaxonomy
{
    private function __construct()
    {
    }

    private static ?array $taxonomy = null;

    public static function get(string $code): LightAgentErrorInfo
    {
        $taxonomy = self::$taxonomy ??= self::build();
        return $taxonomy[$code] ?? $taxonomy['LA-UNKNOWN'];
    }

    /**
     * Classify common provider and runtime exceptions into stable LightAgent codes.
     *
     * Transport-layer failures (DNS, connection refused, TLS handshake,
     * socket timeout) carry NO HTTP status code, so they used to fall
     * through to LA-UNKNOWN — "An unexpected LightAgent error occurred" —
     * which is the least actionable message in the whole taxonomy and the
     * single most common production failure mode. They now get their own
     * codes so operators can route on them.
     */
    public static function classify(\Throwable $exc, string $defaultCode = 'LA-UNKNOWN'): LightAgentErrorInfo
    {
        $statusCode = self::extractStatusCode($exc);

        if ($statusCode !== null) {
            $mapping = [
                400 => 'LA-400',
                401 => 'LA-401',
                403 => 'LA-403',
                404 => 'LA-404',
                408 => 'LA-408',
                413 => 'LA-413',
                429 => 'LA-429',
            ];
            if (isset($mapping[$statusCode])) {
                return self::get($mapping[$statusCode]);
            }
            if ($statusCode === 503 || $statusCode === 504) {
                return self::get('LA-503');
            }
            // Any other 5xx is a provider-side server error. Leaving them as
            // LA-UNKNOWN hid 501/505/599 behind "unexpected".
            if ($statusCode >= 500) {
                return self::get('LA-500');
            }
        }

        $name = strtolower((new \ReflectionClass($exc))->getShortName());
        $text = strtolower($exc->getMessage());

        // Transport-layer classification. Order matters: TLS is checked
        // before the generic connection bucket because a certificate
        // problem has a completely different remedy.
        if (self::looksLikeTlsFailure($text)) {
            return self::get('LA-TLS');
        }
        if (self::looksLikeTimeout($name, $text)) {
            return self::get('LA-408');
        }
        if (self::looksLikeConnectionFailure($text)) {
            return self::get('LA-CONNECTION');
        }

        if (str_contains($name, 'authentication') || str_contains($text, 'unauthorized') || str_contains($text, 'api key')) {
            return self::get('LA-401');
        }
        // An approval gate denial is its own category: "waiting for a human"
        // is not the same as "the provider said no". Checked before the
        // generic permission rule, which would otherwise swallow it.
        if (str_contains($text, 'la-approval')) {
            return self::get('LA-APPROVAL');
        }
        if (str_contains($name, 'permission') || str_contains($text, 'forbidden')) {
            return self::get('LA-403');
        }
        if (str_contains($name, 'notfound') || str_contains($text, 'not found')) {
            return self::get('LA-404');
        }
        if (str_contains($name, 'timeout') || str_contains($text, 'timed out')) {
            return self::get('LA-408');
        }
        if (str_contains($name, 'ratelimit') || str_contains($text, 'rate limit') || str_contains($text, 'quota')) {
            return self::get('LA-429');
        }
        if (str_contains($text, 'context length') || str_contains($text, 'maximum context') || str_contains($text, 'too large')) {
            return self::get('LA-413');
        }

        return self::get($defaultCode);
    }

    /**
     * True when the message describes a TLS/SSL trust or handshake problem.
     */
    private static function looksLikeTlsFailure(string $text): bool
    {
        foreach ([
            'ssl certificate',
            'ssl peer certificate',
            'certificate verify failed',
            'unable to get local issuer certificate',
            'self signed certificate',
            'tls handshake',
            'ssl connect error',
            'ssl error',
            'certificate has expired',
        ] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * True when the message describes a transport timeout.
     */
    private static function looksLikeTimeout(string $name, string $text): bool
    {
        if (str_contains($name, 'timeout')) {
            return true;
        }
        foreach ([
            'timed out',
            'timeout was reached',
            'operation timed out',
            'deadline exceeded',
        ] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * True when the message describes a DNS / connection-level failure.
     */
    private static function looksLikeConnectionFailure(string $text): bool
    {
        foreach ([
            'could not resolve host',
            'couldn\'t resolve host',
            'name or service not known',
            'connection refused',
            'connection reset',
            'connection timed out',
            'failed to connect',
            'network is unreachable',
            'no route to host',
            'empty reply from server',
            'server closed the connection',
            'recv failure',
            'send failure',
            'broken pipe',
        ] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return a concise user-facing error with a stable code and troubleshooting hint.
     */
    public static function formatException(\Throwable $exc, ?string $action = null, string $defaultCode = 'LA-UNKNOWN'): string
    {
        $info = self::classify($exc, $defaultCode);
        $prefix = "[{$info->code}] {$info->message}";
        if ($action !== null) {
            $prefix .= " Action: {$action}.";
        }
        return "{$prefix} Guidance: {$info->guidance} Details: {$exc->getMessage()}";
    }

    /**
     * Format a known LightAgent error code with optional details.
     */
    public static function formatCode(string $code, mixed $details = null): string
    {
        $info = self::get($code);
        return LightAgentError::format($info->code, $info->message, $info->guidance, $details);
    }

    /**
     * Extract an HTTP status code from a throwable, checking:
     *  1. a public property or getter-style method `getStatusCode()`
     *  2. a `response` object exposing the same
     */
    private static function extractStatusCode(\Throwable $exc): ?int
    {
        $statusCode = self::readStatusCode($exc);
        if ($statusCode !== null) {
            return $statusCode;
        }
        $response = self::readProperty($exc, 'response');
        if (is_object($response)) {
            return self::readStatusCode($response);
        }
        return null;
    }

    private static function readStatusCode(object $obj): ?int
    {
        if (method_exists($obj, 'getStatusCode')) {
            $value = $obj->getStatusCode();
            if (is_int($value)) {
                return $value;
            }
        }
        $value = self::readProperty($obj, 'statusCode')
            ?? self::readProperty($obj, 'status_code');
        return is_int($value) ? $value : null;
    }

    private static function readProperty(object $obj, string $name): mixed
    {
        try {
            $ref = new \ReflectionProperty($obj, $name);
        } catch (\ReflectionException) {
            return null;
        }
        if (!$ref->isInitialized($obj)) {
            return null;
        }
        return $ref->getValue($obj);
    }

    private static function build(): array
    {
        return [
            'LA-400' => new LightAgentErrorInfo(
                'LA-400',
                'Invalid request sent to the model provider.',
                'Check the request parameters, tool schema, message format, and model name.',
            ),
            'LA-401' => new LightAgentErrorInfo(
                'LA-401',
                'Authentication failed.',
                'Check that api_key or OPENAI_API_KEY is set and valid for the configured provider.',
            ),
            'LA-403' => new LightAgentErrorInfo(
                'LA-403',
                'The provider rejected this request because of permissions or policy.',
                'Check account permissions, model access, and provider-side safety or quota settings.',
            ),
            'LA-404' => new LightAgentErrorInfo(
                'LA-404',
                'The requested endpoint or model was not found.',
                'Check base_url and model. For OpenAI-compatible local servers, base_url should end at /v1.',
            ),
            'LA-408' => new LightAgentErrorInfo(
                'LA-408',
                'The model request timed out.',
                'Retry the request, reduce input size, or increase provider/client timeout settings.',
            ),
            'LA-413' => new LightAgentErrorInfo(
                'LA-413',
                'The request or response exceeded the provider limit.',
                'Reduce the prompt, history, tool output, or requested completion size.',
            ),
            'LA-429' => new LightAgentErrorInfo(
                'LA-429',
                'Rate limit or quota was exceeded.',
                'Wait before retrying, reduce concurrency, or check provider quota and billing status.',
            ),
            'LA-500' => new LightAgentErrorInfo(
                'LA-500',
                'The model provider returned a server error.',
                'Retry later or switch to another compatible provider endpoint.',
            ),
            'LA-503' => new LightAgentErrorInfo(
                'LA-503',
                'The model provider is temporarily unavailable.',
                'Retry later, reduce traffic, or use a fallback model/provider.',
                'provider',
            ),
            // Transport-layer codes. They carry no HTTP status, so before
            // these existed every DNS failure, refused connection and TLS
            // handshake error was reported as LA-UNKNOWN — "an unexpected
            // error occurred" — which told an operator nothing at all.
            'LA-CONNECTION' => new LightAgentErrorInfo(
                'LA-CONNECTION',
                'The model provider could not be reached.',
                'Check the base_url host and port, DNS resolution, proxy settings and network egress rules.',
                'network',
            ),
            'LA-TLS' => new LightAgentErrorInfo(
                'LA-TLS',
                'The TLS connection to the model provider failed certificate verification.',
                'Install the CA bundle for the provider (for example via the ca_bundle option) or configure the system trust store; do not disable certificate verification.',
                'network',
            ),
            'LA-JSON' => new LightAgentErrorInfo(
                'LA-JSON',
                'A tool call argument could not be parsed as JSON.',
                'Check that the model returned valid tool-call JSON matching the registered tool schema.',
            ),
            'LA-TOOL' => new LightAgentErrorInfo(
                'LA-TOOL',
                'Tool execution failed.',
                'Check the tool implementation, required arguments, dependencies, and external service credentials.',
            ),
            'LA-GUARDRAIL' => new LightAgentErrorInfo(
                'LA-GUARDRAIL',
                'A configured guardrail blocked this operation.',
                'Review the configured input, tool, or output guardrail policy and adjust the request if appropriate.',
            ),
            'LA-HOOK' => new LightAgentErrorInfo(
                'LA-HOOK',
                'A configured runtime hook blocked this operation.',
                'Review the hook decision, configured phase, and application policy before retrying.',
            ),
            'LA-POLICY' => new LightAgentErrorInfo(
                'LA-POLICY',
                'The unified capability policy denied this operation.',
                'Review Provider scope, permission snapshots, approval requirements, and Policy decisions.',
            ),
            'LA-SANDBOX' => new LightAgentErrorInfo(
                'LA-SANDBOX',
                'This operation requires an explicit sandbox.',
                'Register an isolated SandboxProvider before enabling execution-capable tools.',
            ),
            'LA-BUDGET' => new LightAgentErrorInfo(
                'LA-BUDGET',
                'The configured runtime budget was exhausted.',
                'Increase the relevant budget or resume with a narrower goal, tool set, or context.',
            ),
            'LA-SESSION' => new LightAgentErrorInfo(
                'LA-SESSION',
                'Session persistence or replay failed.',
                'Inspect the SessionStore, event sequence, schema version, and latest checkpoint.',
            ),
            'LA-EMPTY-RESPONSE' => new LightAgentErrorInfo(
                'LA-EMPTY-RESPONSE',
                'The model provider returned an empty response (no choices or no stream content).',
                'Retry the request; if it persists, check provider health and model availability.',
                'provider',
            ),
            'LA-PROVIDER' => new LightAgentErrorInfo(
                'LA-PROVIDER',
                'A capability Provider failed or is unavailable.',
                'Inspect Provider health, lifecycle state, configuration digest, and optional dependencies.',
            ),
            'LA-APPROVAL' => new LightAgentErrorInfo(
                'LA-APPROVAL',
                'This operation is waiting for explicit approval.',
                'Resolve the approval request and resume the same Session or workflow run.',
            ),
            'LA-CANCELLED' => new LightAgentErrorInfo(
                'LA-CANCELLED',
                'The run, Job, workflow, or Session was cancelled.',
                'Inspect the cancellation event and resume from a checkpoint when the operation is resumable.',
            ),
            'LA-UNKNOWN' => new LightAgentErrorInfo(
                'LA-UNKNOWN',
                'An unexpected LightAgent error occurred.',
                'Enable debug logging and inspect the original exception details.',
            ),
        ];
    }
}
