<?php

declare(strict_types=1);

namespace LightAgent\Tests\Connectors;

use LightAgent\Connectors\ConnectorManifest;
use LightAgent\Connectors\ConnectorValidator;
use LightAgent\Skills\Skill;
use LightAgent\Tools\ToolFunction;
use PHPUnit\Framework\TestCase;

/**
 * Functional tests for offline connector validation (connectors.py).
 */
final class ConnectorValidatorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/la_conn_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->tmpDir);
    }

    private function validTool(): ToolFunction
    {
        return new ToolFunction(
            callable: static fn (string $q): string => $q,
            toolInfo: [
                'tool_name' => 'search_docs',
                'tool_description' => 'Search docs',
                'tool_params' => [
                    ['name' => 'q', 'type' => 'string', 'description' => 'query', 'required' => true],
                ],
            ],
        );
    }

    public function test_valid_manifest_passes(): void
    {
        // Local doc file so docs check passes
        $doc = $this->tmpDir . '/USAGE.md';
        file_put_contents($doc, 'how to use');
        $skillDir = $this->tmpDir . '/my-skill';
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir . '/SKILL.md', "---\nname: s\ndescription: d\n---\n\nbody");

        $manifest = new ConnectorManifest(
            name: 'my-connector',
            version: '1.0.0',
            description: 'test bundle',
            tools: [$this->validTool()],
            skills: [$skillDir],
            mcpServers: ['remote' => ['url' => 'http://localhost:8000/sse']],
            hooks: [static fn (object $ctx) => null],
            memoryAdapters: ['pool' => new \LightAgent\Memory\SharedMemoryPool()],
            extras: ['export' => ['ext-sockets']],
            docs: [$doc],
        );

        $report = (new ConnectorValidator($this->tmpDir))->validate($manifest);

        self::assertTrue($report->isValid(), print_r(array_map(
            static fn ($d) => "{$d->level}:{$d->field}:{$d->message}",
            $report->diagnostics,
        ), true));
        self::assertSame([], $report->errors());
    }

    public function test_identity_validation(): void
    {
        $manifest = new ConnectorManifest(name: 'bad name!', version: 'not-semver');
        $report = (new ConnectorValidator())->validate($manifest);

        $fields = array_map(static fn ($d) => $d->field, $report->errors());
        self::assertContains('name', $fields);
        self::assertContains('version', $fields);
    }

    public function test_tool_without_tool_info_is_error(): void
    {
        $manifest = new ConnectorManifest(
            name: 'c',
            version: '1.0.0',
            tools: [static fn (): string => 'no metadata'],
        );
        $report = (new ConnectorValidator())->validate($manifest);

        $fields = array_map(static fn ($d) => $d->field, $report->errors());
        self::assertContains('tools[0].tool_info', $fields);
    }

    public function test_duplicate_tool_names_are_error(): void
    {
        $manifest = new ConnectorManifest(
            name: 'c',
            version: '1.0.0',
            tools: [$this->validTool(), $this->validTool()],
        );
        $report = (new ConnectorValidator())->validate($manifest);

        $messages = array_map(static fn ($d) => $d->message, $report->errors());
        self::assertContains('duplicate tool name `search_docs` appears 2 times', $messages);
    }

    public function test_mcp_config_requires_url_xor_command(): void
    {
        $manifest = new ConnectorManifest(
            name: 'c',
            version: '1.0.0',
            mcpServers: [
                'both' => ['url' => 'http://x', 'command' => 'run'],
                'none' => [],
            ],
        );
        $report = (new ConnectorValidator())->validate($manifest);

        $fields = array_map(static fn ($d) => $d->field, $report->errors());
        self::assertContains('mcp_servers.both', $fields);
        self::assertContains('mcp_servers.none', $fields);
    }

    public function test_credential_placeholder_warning(): void
    {
        $manifest = new ConnectorManifest(
            name: 'c',
            version: '1.0.0',
            mcpServers: ['srv' => [
                'url' => 'http://x',
                'headers' => ['Authorization' => 'Bearer sk-real-secret-123'],
            ]],
        );
        $report = (new ConnectorValidator())->validate($manifest);

        // M-38 / V5-39: a plaintext credential is an ERROR - a warning left
        // `isValid()` at true, so the connector shipped the secret.
        $errors = array_map(static fn ($d) => $d->field, $report->errors());
        self::assertContains('mcp_servers.srv.headers.Authorization', $errors);
        self::assertFalse($report->isValid());
    }

    /**
     * V5-29: the RAW header-line form (`'Authorization: Bearer sk-…'`) is
     * accepted by HttpTransport::normalizeHeaders() and used to ship
     * unnoticed.
     */
    public function test_raw_header_line_credential_is_an_error(): void
    {
        $manifest = new ConnectorManifest(
            name: 'c',
            version: '1.0.0',
            mcpServers: ['srv' => [
                'url' => 'http://x',
                'headers' => ['Authorization: Bearer sk-live-SECRETVALUE'],
            ]],
        );
        $report = (new ConnectorValidator())->validate($manifest);

        $errors = array_map(static fn ($d) => $d->field, $report->errors());
        self::assertContains('mcp_servers.srv.headers.Authorization', $errors);
        self::assertFalse($report->isValid());
    }

    /**
     * M-38: `max_tokens` is an ordinary setting, not a credential.
     */
    public function test_max_tokens_is_not_flagged(): void
    {
        $manifest = new ConnectorManifest(
            name: 'c',
            version: '1.0.0',
            mcpServers: ['srv' => [
                'url' => 'http://x',
                'headers' => ['max_tokens' => '100'],
            ]],
        );
        $report = (new ConnectorValidator())->validate($manifest);

        $fields = array_merge(
            array_map(static fn ($d) => $d->field, $report->errors()),
            array_map(static fn ($d) => $d->field, $report->warnings()),
        );
        self::assertNotContains('mcp_servers.srv.headers.max_tokens', $fields);
        self::assertTrue($report->isValid());
    }

    public function test_credential_placeholder_is_not_flagged(): void
    {
        $manifest = new ConnectorManifest(
            name: 'c',
            version: '1.0.0',
            mcpServers: ['srv' => [
                'url' => 'http://x',
                'headers' => ['Authorization' => 'Bearer ${MY_TOKEN}'],
            ]],
        );
        $report = (new ConnectorValidator())->validate($manifest);

        foreach ($report->warnings() as $w) {
            self::assertStringNotContainsString('Authorization', $w->field);
        }
    }

    public function test_skill_path_missing_is_error(): void
    {
        $manifest = new ConnectorManifest(
            name: 'c',
            version: '1.0.0',
            skills: [$this->tmpDir . '/nonexistent-skill'],
        );
        $report = (new ConnectorValidator($this->tmpDir))->validate($manifest);

        $skillErrors = array_values(array_filter(
            $report->errors(),
            static fn ($d) => $d->field === 'skills[0]',
        ));
        self::assertCount(1, $skillErrors);
        self::assertStringContainsString('SKILL.md not found at', $skillErrors[0]->message);
        self::assertStringContainsString('nonexistent-skill', $skillErrors[0]->message);
    }

    public function test_hook_must_be_callable_or_phase_object(): void
    {
        $manifest = new ConnectorManifest(
            name: 'c',
            version: '1.0.0',
            hooks: ['not callable at all'],
        );
        $report = (new ConnectorValidator())->validate($manifest);

        $fields = array_map(static fn ($d) => $d->field, $report->errors());
        self::assertContains('hooks[0]', $fields);
    }

    public function test_memory_adapter_must_implement_protocol(): void
    {
        $manifest = new ConnectorManifest(
            name: 'c',
            version: '1.0.0',
            memoryAdapters: ['bad' => new \stdClass()],
        );
        $report = (new ConnectorValidator())->validate($manifest);

        $fields = array_map(static fn ($d) => $d->field, $report->errors());
        self::assertContains('memory_adapters.bad', $fields);
    }

    public function test_extras_requirement_format(): void
    {
        $manifest = new ConnectorManifest(
            name: 'c',
            version: '1.0.0',
            extras: ['good' => ['ext-sockets'], 'bad' => ['!!!invalid!!!']],
        );
        $report = (new ConnectorValidator())->validate($manifest);

        $fields = array_map(static fn ($d) => $d->field, $report->errors());
        self::assertContains('extras.bad[0]', $fields);
        self::assertNotContains('extras.good[0]', $fields);
    }

    public function test_docs_missing_is_error_remote_is_warning(): void
    {
        $manifest = new ConnectorManifest(
            name: 'c',
            version: '1.0.0',
            docs: ['https://example.com/docs'],
        );
        $report = (new ConnectorValidator())->validate($manifest);

        $warnings = array_map(static fn ($d) => $d->field, $report->warnings());
        self::assertContains('docs[0]', $warnings);
        self::assertTrue($report->isValid()); // warnings don't fail validity
    }

    public function test_empty_docs_recommended_warning(): void
    {
        $manifest = new ConnectorManifest(name: 'c', version: '1.0.0');
        $report = (new ConnectorValidator())->validate($manifest);

        self::assertContains('docs', array_map(static fn ($d) => $d->field, $report->warnings()));
    }

    public function test_validate_connector_function_entry(): void
    {
        $report = \LightAgent\Connectors\ConnectorFunctions::validateConnector(
            new ConnectorManifest(name: 'c', version: '1.0.0'),
        );
        self::assertSame('c', $report->connector);
    }

    public function test_skill_instance_validation(): void
    {
        $skillDir = $this->tmpDir . '/good-skill';
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir . '/SKILL.md', "---\nname: good\ndescription: ok\n---\n");
        $skill = new Skill(name: 'good', description: 'ok', path: $skillDir);

        $manifest = new ConnectorManifest(name: 'c', version: '1.0.0', skills: [$skill]);
        $report = (new ConnectorValidator($this->tmpDir))->validate($manifest);

        $skillErrors = array_filter(
            $report->errors(),
            static fn ($d) => str_starts_with($d->field, 'skills['),
        );
        self::assertSame([], $skillErrors);
    }
}
