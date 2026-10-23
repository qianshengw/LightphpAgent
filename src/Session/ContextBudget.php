<?php

declare(strict_types=1);

namespace LightAgent\Session;

use LightAgent\Support\Json;

/**
 * Dependency-free context budget using a conservative character estimate
 * (session.py ContextBudget, frozen dataclass).
 *
 * The estimate is character based, so it has to be calibrated per script:
 * an English character is roughly a quarter of a token, while a CJK
 * character is roughly ONE token (M-26). Using the English ratio for
 * Chinese text under-estimated the real cost by ~4x, so the compaction
 * never triggered and the provider rejected the oversized request.
 */
final class ContextBudget
{
    /**
     * Characters that cost roughly one token EACH (CJK ideographs,
     * kana, hangul and the full-width forms).
     */
    private const WIDE_CHAR_PATTERN = '/[\x{1100}-\x{115F}\x{2E80}-\x{303E}\x{3041}-\x{33FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{A000}-\x{A4CF}\x{AC00}-\x{D7A3}\x{F900}-\x{FAFF}\x{FE30}-\x{FE4F}\x{FF00}-\x{FF60}\x{FFE0}-\x{FFE6}\x{20000}-\x{2A6DF}\x{2A700}-\x{2EBEF}\x{2F800}-\x{2FA1F}]/u';

    public function __construct(
        public readonly int $maxTokens = 8192,
        public readonly int $reservedOutputTokens = 1024,
        public readonly float $charsPerToken = 4.0,
        /**
         * Characters per token for the wide (CJK / full-width) characters.
         * One token per character is the calibration measured against a
         * real tokenizer.
         */
        public readonly float $wideCharsPerToken = 1.0,
    ) {
        if ($this->maxTokens < 1 || $this->reservedOutputTokens < 0) {
            throw new \InvalidArgumentException('context token limits must be non-negative');
        }
        if ($this->reservedOutputTokens >= $this->maxTokens) {
            throw new \InvalidArgumentException('reserved_output_tokens must be smaller than max_tokens');
        }
        if ($this->charsPerToken <= 0) {
            throw new \InvalidArgumentException('chars_per_token must be positive');
        }
        if ($this->wideCharsPerToken <= 0) {
            throw new \InvalidArgumentException('wide_chars_per_token must be positive');
        }
    }

    /**
     * Estimate the number of tokens the given messages occupy.
     *
     * `Json::encode()` is used instead of the raw `json_encode()`: the
     * latter returns FALSE for a payload with invalid UTF-8, and the
     * `false` was then read as "zero characters" — a single broken byte
     * made a 4000 character message look like ONE token, so the budget
     * check passed and the oversized request was sent (M-26).
     *
     * @param iterable<array<string, mixed>> $messages
     */
    public function estimate(iterable $messages): int
    {
        $rendered = Json::encode(
            is_array($messages) ? array_values($messages) : iterator_to_array($messages),
            JSON_UNESCAPED_UNICODE,
        );
        $length = mb_strlen($rendered);
        if ($length === 0) {
            return 1;
        }
        // The wide characters are counted separately: they cost about one
        // token EACH, the remaining (ASCII) characters about 1/4 token.
        $wide = preg_match_all(self::WIDE_CHAR_PATTERN, $rendered);
        $wide = is_int($wide) ? $wide : 0;
        $narrow = max(0, $length - $wide);
        $tokens = ($narrow / $this->charsPerToken) + ($wide / $this->wideCharsPerToken);
        return max(1, (int) $tokens + 1);
    }

    /**
     * @param iterable<array<string, mixed>> $messages
     */
    public function fits(iterable $messages): bool
    {
        return $this->estimate($messages) <= $this->maxTokens - $this->reservedOutputTokens;
    }
}
