<?php

declare(strict_types=1);

namespace LightAgent\Connectors;

use LightAgent\Skills\Skill;
use LightAgent\Tools\ToolRegistry;

/**
 * Structured result returned by validate() (connectors.py ConnectorValidationReport).
 */
final class ConnectorValidationReport
{
    /**
     * @param list<ConnectorDiagnostic> $diagnostics
     */
    public function __construct(
        public readonly string $connector,
        public array $diagnostics = [],
    ) {
    }

    public function isValid(): bool
    {
        foreach ($this->diagnostics as $item) {
            if ($item->level === 'error') {
                return false;
            }
        }
        return true;
    }

    /**
     * @return list<ConnectorDiagnostic>
     */
    public function errors(): array
    {
        return array_values(array_filter($this->diagnostics, static fn (ConnectorDiagnostic $d): bool => $d->level === 'error'));
    }

    /**
     * @return list<ConnectorDiagnostic>
     */
    public function warnings(): array
    {
        return array_values(array_filter($this->diagnostics, static fn (ConnectorDiagnostic $d): bool => $d->level === 'warning'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDict(): array
    {
        return [
            'connector' => $this->connector,
            'valid' => $this->isValid(),
            'diagnostics' => array_map(static fn (ConnectorDiagnostic $d): array => $d->toDict(), $this->diagnostics),
        ];
    }
}
