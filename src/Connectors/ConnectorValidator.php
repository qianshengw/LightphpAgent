<?php

declare(strict_types=1);

namespace LightAgent\Connectors;

use LightAgent\Skills\Skill;
use LightAgent\Tools\ToolRegistry;

final class ConnectorValidator
{
    private const HOOK_PHASES = [
        'before_run', 'after_run', 'on_error', 'before_model_request',
        'after_model_response', 'before_tool_call', 'after_tool_result',
        'before_memory_retrieve', 'after_memory_retrieve', 'before_memory_write',
        'after_memory_write', 'before_memory_promote', 'after_memory_promote',
        'on_handoff', 'before_flow_run', 'after_flow_run',
        'before_flow_step', 'after_flow_step',
    ];

    private const UNSAFE_IMPORT_HINTS = [
        'ctypes' => 'native process access',
        'importlib' => 'dynamic imports',
        'os' => 'host operating-system access',
        'pickle' => 'unsafe deserialization',
        'pty' => 'pseudo-terminal access',
        'shutil' => 'host filesystem mutation',
        'socket' => 'direct network access',
        'subprocess' => 'child-process execution',
    ];

    private const NETWORK_IMPORT_HINTS = ['aiohttp', 'httpx', 'requests', 'urllib'];

    public function __construct(
        private readonly string $basePath = '.',
    ) {
    }

    public function validate(ConnectorManifest $manifest): ConnectorValidationReport
    {
        $connectorName = $manifest->name !== '' ? $manifest->name : '<unknown>';
        /** @var list<ConnectorDiagnostic> $diagnostics */
        $diagnostics = [];
        $add = static function (string $level, string $field, string $message, ?string $component = null) use (&$diagnostics, $connectorName): void {
            $diagnostics[] = new ConnectorDiagnostic($connectorName, $level, $field, $message, $component);
        };

        self::validateIdentity($manifest, $add);
        self::validateTools($manifest, $add);
        $this->validateSkills($manifest, $add);
        self::validateMcpServers($manifest, $add);
        self::validateHooks($manifest, $add);
        self::validateMemoryAdapters($manifest, $add);
        self::validateExtras($manifest, $add);
        $this->validateDocs($manifest, $add);
        return new ConnectorValidationReport($connectorName, $diagnostics);
    }

    private static function validateIdentity(ConnectorManifest $manifest, \Closure $add): void
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._-]*$/', $manifest->name) !== 1) {
            $add('error', 'name', 'name must match [A-Za-z][A-Za-z0-9._-]*');
        }
        if (preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/', $manifest->version) !== 1) {
            $add('error', 'version', 'version must be a semantic version such as 1.0.0');
        }
        if (trim($manifest->description) === '') {
            $add('warning', 'description', 'description should not be empty');
        }
    }

    private static function validateTools(ConnectorManifest $manifest, \Closure $add): void
    {
        /** @var array<string, int> $names */
        $names = [];
        foreach (array_values($manifest->tools) as $index => $tool) {
            $fieldName = "tools[{$index}]";
            if (!is_callable($tool) && !(is_object($tool) && isset($tool->callable))) {
                $add('error', $fieldName, 'tool must be callable');
                continue;
            }
            $component = is_object($tool) ? $tool::class : 'callable';
            $toolInfo = is_object($tool) && isset($tool->toolInfo) ? $tool->toolInfo : null;
            if ($toolInfo === null) {
                $add('error', "{$fieldName}.tool_info", 'tool must define tool_info metadata', $component);
                continue;
            }
            foreach (ToolRegistry::validateToolInfo($toolInfo) as $item) {
                $add($item['level'], "{$fieldName}.{$item['field']}", $item['message'], $component);
            }
            $toolName = is_array($toolInfo) ? ($toolInfo['tool_name'] ?? null) : null;
            if (is_string($toolName) && $toolName !== '') {
                $names[$toolName] = ($names[$toolName] ?? 0) + 1;
            }
        }
        foreach ($names as $toolName => $count) {
            if ($count > 1) {
                $add('error', 'tools', "duplicate tool name `{$toolName}` appears {$count} times", $toolName);
            }
        }
    }

    private function validateSkills(ConnectorManifest $manifest, \Closure $add): void
    {
        foreach (array_values($manifest->skills) as $index => $skill) {
            $fieldName = "skills[{$index}]";
            if ($skill instanceof Skill) {
                if (trim($skill->name) === '' || trim($skill->description) === '') {
                    $add('error', $fieldName, 'Skill name and description must not be empty', $skill->name ?: null);
                }
                $path = $skill->path;
            } elseif (is_string($skill) && trim($skill) !== '') {
                $path = $skill;
            } else {
                $add('error', $fieldName, 'skill must be a Skill instance or local path');
                continue;
            }
            $resolved = $path;
            if (!self::isAbsolutePath($resolved)) {
                $resolved = $this->basePath . DIRECTORY_SEPARATOR . ltrim($resolved, '/\\');
            }
            $skillFile = str_ends_with($resolved, 'SKILL.md') ? $resolved : $resolved . DIRECTORY_SEPARATOR . 'SKILL.md';
            if (!is_file($skillFile)) {
                $add('error', $fieldName, "SKILL.md not found at {$skillFile}", $skill instanceof Skill ? $skill->name : null);
            }
        }
    }

    private static function validateMcpServers(ConnectorManifest $manifest, \Closure $add): void
    {
        foreach ($manifest->mcpServers as $serverName => $config) {
            $fieldName = "mcp_servers.{$serverName}";
            if (!is_string($serverName) || trim($serverName) === '') {
                $add('error', $fieldName, 'MCP server name must be a non-empty string');
            }
            if (!is_array($config)) {
                $add('error', $fieldName, 'MCP server config must be a mapping');
                continue;
            }
            $hasUrl = isset($config['url']) && is_string($config['url']) && $config['url'] !== '';
            $hasCommand = isset($config['command']) && is_string($config['command']) && $config['command'] !== '';
            if ($hasUrl === $hasCommand) {
                $add('error', $fieldName, 'MCP config must define exactly one of `url` or `command`');
            }
            $args = $config['args'] ?? [];
            if ($hasCommand && (is_string($args) || !array_is_list((array) $args))) {
                $add('error', "{$fieldName}.args", 'stdio MCP args must be a sequence');
            }
            self::scanSecrets($config, $fieldName, $add);
        }
    }

    /**
     * Scan a configuration mapping for plaintext credentials.
     *
     * Two shapes are covered:
     *  - the ARRAY form `['Authorization' => 'Bearer sk-live-…']`, keyed by
     *    the credential-like key;
     *  - the RAW header-line form `['Authorization: Bearer sk-live-…']`
     *    (int-keyed), which `HttpTransport::normalizeHeaders()` accepts
     *    and which used to ship unnoticed (V5-29).
     *
     * M-38 / V5-39: a plaintext credential in a credential-like key is an
     * ERROR, not a warning. A warning left `isValid()` at `true`, so a
     * connector that ships a real secret still validated.
     *
     * @param mixed $value
     */
    private static function scanSecrets(mixed $value, string $fieldName, \Closure $add): void
    {
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $item) {
            $nestedField = "{$fieldName}.{$key}";
            if (is_array($item)) {
                self::scanSecrets($item, $nestedField, $add);
                continue;
            }
            if (!is_string($item)) {
                continue;
            }
            if (is_int($key)) {
                // A raw header line: "Authorization: Bearer sk-live-…".
                self::scanRawHeaderLine($item, $fieldName, $add);
                continue;
            }
            if (
                self::isCredentialLikeKey((string) $key)
                && trim($item) !== ''
                && self::looksLikePlaceholder($item) === false
            ) {
                $add('error', $nestedField, 'plaintext credential in a credential-like key; use an environment/config placeholder');
            }
        }
    }

    /**
     * Scan a raw header line (`Name: value`) for a plaintext credential.
     */
    private static function scanRawHeaderLine(string $line, string $fieldName, \Closure $add): void
    {
        if (preg_match('/^\s*([^:\s]+)\s*:\s*(.*)$/s', $line, $matches) !== 1) {
            return;
        }
        $name = $matches[1];
        $value = $matches[2];
        if (
            !self::isCredentialLikeKey($name)
            || trim($value) === ''
            || self::looksLikePlaceholder($value)
        ) {
            return;
        }
        $add('error', "{$fieldName}.{$name}", 'plaintext credential in a credential-like header; use an environment/config placeholder');
    }

    /**
     * True when a configuration key names a credential.
     *
     * The key is split into WORDS (separators and camelCase boundaries) and
     * matched word by word. The old substring match flagged ordinary
     * settings: `max_tokens` contains "token" and was reported as a
     * credential (M-38).
     */
    private static function isCredentialLikeKey(string $key): bool
    {
        $words = self::keyWords($key);
        if ($words === []) {
            return false;
        }
        $credentialWords = [
            'apikey', 'token', 'secret', 'password', 'passwd', 'pwd',
            'authorization', 'credential', 'credentials', 'bearer',
        ];
        foreach ($words as $word) {
            if (in_array($word, $credentialWords, true)) {
                return true;
            }
        }
        // `api_key`, `apiKey` and `x-api-key` all name the API key.
        return in_array('api', $words, true) && in_array('key', $words, true);
    }

    /**
     * Split a configuration key into lowercase words.
     *
     * @return list<string>
     */
    private static function keyWords(string $key): array
    {
        // A lower-case letter or digit followed by an upper-case letter is
        // a word boundary: `maxTokens` -> `max Tokens`.
        $spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key) ?? $key;
        $parts = preg_split('/[^A-Za-z0-9]+/', strtolower($spaced)) ?: [];
        $words = [];
        foreach ($parts as $part) {
            if ($part !== '') {
                $words[] = $part;
            }
        }
        return $words;
    }

    /**
     * True when the value looks like a placeholder instead of a secret.
     */
    private static function looksLikePlaceholder(string $value): bool
    {
        return preg_match('/(?:\$\{|\{\{|<[^>]+>|your[_ -]|replace[_ -]|env:)/i', $value) === 1;
    }

    private static function validateHooks(ConnectorManifest $manifest, \Closure $add): void
    {
        foreach (array_values($manifest->hooks) as $index => $hook) {
            if (is_callable($hook)) {
                continue;
            }
            if (is_object($hook)) {
                foreach (self::HOOK_PHASES as $phase) {
                    if (method_exists($hook, $phase)) {
                        continue 2;
                    }
                }
            }
            $add('error', "hooks[{$index}]", 'hook must be callable or implement a supported lifecycle phase');
        }
    }

    private static function validateMemoryAdapters(ConnectorManifest $manifest, \Closure $add): void
    {
        foreach ($manifest->memoryAdapters as $name => $adapter) {
            $fieldName = "memory_adapters.{$name}";
            if (!is_object($adapter) || !method_exists($adapter, 'store')) {
                $add('error', $fieldName, 'memory adapter must implement store(data, user_id)');
            }
            if (!is_object($adapter) || !method_exists($adapter, 'retrieve')) {
                $add('error', $fieldName, 'memory adapter must implement retrieve(query, user_id)');
            }
        }
    }

    private static function validateExtras(ConnectorManifest $manifest, \Closure $add): void
    {
        foreach ($manifest->extras as $extraName => $requirements) {
            $fieldName = "extras.{$extraName}";
            if (!is_string($extraName) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $extraName) !== 1) {
                $add('error', $fieldName, 'extra name contains unsupported characters');
            }
            if (is_string($requirements) || !is_array($requirements) || !array_is_list($requirements)) {
                $add('error', $fieldName, 'extra requirements must be a sequence of requirement strings');
                continue;
            }
            foreach (array_values($requirements) as $index => $requirement) {
                if (
                    !is_string($requirement)
                    || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*(?:\[[A-Za-z0-9,._-]+\])?(?:\s*[<>=!~].+)?$/', trim($requirement)) !== 1
                ) {
                    $add('error', "{$fieldName}[{$index}]", 'invalid optional dependency declaration');
                }
            }
        }
    }

    private function validateDocs(ConnectorManifest $manifest, \Closure $add): void
    {
        if ($manifest->docs === []) {
            $add('warning', 'docs', 'at least one local usage document is recommended');
            return;
        }
        foreach (array_values($manifest->docs) as $index => $doc) {
            $fieldName = "docs[{$index}]";
            if (str_starts_with($doc, 'http://') || str_starts_with($doc, 'https://')) {
                $add('warning', $fieldName, 'remote documentation cannot be verified offline');
                continue;
            }
            $path = $doc;
            if (!self::isAbsolutePath($path)) {
                $path = $this->basePath . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
            }
            if (!is_file($path)) {
                $add('error', $fieldName, "documentation file not found at {$path}");
            }
        }
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }
}
