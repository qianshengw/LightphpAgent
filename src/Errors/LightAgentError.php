<?php

declare(strict_types=1);

namespace LightAgent\Errors;

/**
 * Structured LightAgent exception with a stable error code from the taxonomy.
 */
class LightAgentError extends \RuntimeException
{
    public readonly string $code2;

    /**
     * @param string $code      Stable LightAgent code (e.g. "LA-401")
     * @param string|null $message  Overrides the taxonomy message
     * @param string|null $guidance Overrides the taxonomy guidance
     * @param mixed $details     Optional structured details (rendered into the string form)
     */
    public function __construct(
        string $code,
        ?string $message = null,
        ?string $guidance = null,
        public readonly mixed $details = null,
    ) {
        $info = ErrorTaxonomy::get($code);
        $this->code2 = $info->code;
        parent::__construct(self::format($info->code, $message ?? $info->message, $guidance ?? $info->guidance, $details));
    }

    public static function format(string $code, string $message, string $guidance, mixed $details = null): string
    {
        $text = "[{$code}] {$message} Guidance: {$guidance}";
        if ($details !== null) {
            $text .= ' Details: ' . self::renderDetails($details);
        }
        return $text;
    }

    private static function renderDetails(mixed $details): string
    {
        if (is_scalar($details) || $details === null) {
            return (string) $details;
        }
        $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? print_r($details, true) : $json;
    }
}
