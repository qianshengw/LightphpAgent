<?php

declare(strict_types=1);

namespace LightAgent\Llm;

/**
 * LLM client exception carrying an optional HTTP status for classification.
 * (PSR-4: this file is the canonical home of the class; OpenAiCompletions.php
 * previously held the declaration, breaking Composer autoloading — M7.)
 */
final class LlmException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(self::redactSecrets($message), 0, $previous);
    }

    public static function fromHttpStatus(int $status, string $body, string $curlError = ''): self
    {
        $detail = '';
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $err = $decoded['error'] ?? [];
            if (is_array($err)) {
                $detail = (string) ($err['message'] ?? '');
            }
        }
        if ($detail === '') {
            $detail = $body !== '' ? substr($body, 0, 300) : $curlError;
        }
        return new self("HTTP {$status}: {$detail}", statusCode: $status);
    }

    /**
     * Provider error bodies sometimes echo the caller's API key (M8: OpenAI
     * returns "Incorrect API key provided: sk-..."). Mask any key-shaped
     * token before the message reaches logs/exceptions.
     */
    public static function redactSecrets(string $message): string
    {
        $redacted = (string) preg_replace_callback(
            '/\b(sk|ak|pk)-[A-Za-z0-9_\-]{6,}/',
            static fn (array $m): string => substr($m[0], 0, 5) . '[redacted]',
            $message,
        );
        $redacted = (string) preg_replace_callback(
            '/(Bearer\s+)[A-Za-z0-9_\-\.]{8,}/i',
            static fn (array $m): string => $m[1] . '[redacted]',
            $redacted,
        );
        return $redacted;
    }
}
