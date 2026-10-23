<?php

declare(strict_types=1);

namespace LightAgent\Security;

use LightAgent\Errors\LightAgentError;

/**
 * A permission / approval decision that denied the operation.
 *
 * README §13 promises this class: "参数或上下文不匹配 → PermissionError"
 * (a parameter or context mismatch raises a PermissionError). The port used
 * to raise a bare `\RuntimeException`, so a caller could not tell an
 * approval mismatch apart from any other runtime failure.
 *
 * The class extends `LightAgentError` so that the denial also carries a
 * stable, machine-readable code (`LA-APPROVAL`) in its message — the
 * taxonomy entry that was defined but never used (V5-16).
 */
final class PermissionError extends LightAgentError
{
    /**
     * @param string      $message what was denied, in plain words
     * @param string      $code    the taxonomy code (LA-APPROVAL by default)
     * @param mixed       $details optional structured details
     */
    public function __construct(
        string $message = 'permission denied',
        string $code = 'LA-APPROVAL',
        mixed $details = null,
    ) {
        parent::__construct($code, $message, null, $details);
    }
}
