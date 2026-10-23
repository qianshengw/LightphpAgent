<?php

declare(strict_types=1);

namespace LightAgent\Connectors;

use LightAgent\Skills\Skill;
use LightAgent\Tools\ToolRegistry;

/**
 * Validate a connector manifest without loading external services
 * (connectors.py ConnectorValidator tail).
 */
final class ConnectorFunctions
{
    /**
     * Offline connector validation entry point (connectors.py validate_connector).
     */
    public static function validateConnector(ConnectorManifest $manifest, ?string $basePath = null): ConnectorValidationReport
    {
        return (new ConnectorValidator(basePath: $basePath ?? '.'))->validate($manifest);
    }
}
