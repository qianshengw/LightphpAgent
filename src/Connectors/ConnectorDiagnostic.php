<?php

declare(strict_types=1);

namespace LightAgent\Connectors;

use LightAgent\Skills\Skill;
use LightAgent\Tools\ToolRegistry;

/**
 * One offline connector validation finding (connectors.py ConnectorDiagnostic).
 */
final class ConnectorDiagnostic
{
    public function __construct(
        public readonly string $connector,
        public readonly string $level,
        public readonly string $field,
        public readonly string $message,
        public readonly ?string $component = null,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toDict(): array
    {
        $data = [
            'connector' => $this->connector,
            'level' => $this->level,
            'field' => $this->field,
            'message' => $this->message,
        ];
        if ($this->component !== null) {
            $data['component'] = $this->component;
        }
        return $data;
    }
}
