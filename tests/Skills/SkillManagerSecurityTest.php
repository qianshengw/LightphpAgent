<?php

declare(strict_types=1);

namespace LightAgent\Tests\Skills;

use LightAgent\Skills\SkillManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the skill-script and skill-reference hardening.
 *
 * C-1/V5-02: the arguments of `execute_skill_script` come straight from
 *   the model. The command must never be assembled as a shell string.
 * C-2: a `.php` skill script must be executable on Windows.
 * H-17/V5-07: `readReference()` must resolve the REAL path, so a
 *   junction inside `references/` cannot escape the skill directory.
 * M-11: skill names/descriptions are XML-escaped.
 * M-12: the temporary working directory must not leak.
 * M-13: only the winning skill is returned by discoverSkills().
 * M-55: a null byte must not raise an uncaught ValueError.
 * V4-23/V4-24: the timeout option must actually stop the script.
 */
final class SkillManagerSecurityTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/la_skillsec_' . bin2hex(random_bytes(4));
        mkdir($this->baseDir . '/demo/scripts', 0777, true);
        mkdir($this->baseDir . '/demo/references', 0777, true);
        file_put_contents(
            $this->baseDir . '/demo/SKILL.md',
            "---\nname: demo\ndescription: A & B <script>alert(1)</script>\n---\n\nbody",
        );
        file_put_contents($this->baseDir . '/demo/references/guide.md', 'reference content');
        file_put_contents(
            $this->baseDir . '/demo/scripts/hello.php',
            "<?php echo 'PHP-SCRIPT-OK:' . (\$argv[1] ?? 'noargs');\n",
        );
    }

    protected function tearDown(): void
    {
        self::removeTree($this->baseDir);
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    private function manager(): SkillManager
    {
        $manager = new SkillManager([$this->baseDir]);
        $manager->discoverSkills();
        return $manager;
    }

    private static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    /**
     * A script that prints its arguments, plus the argument payload that
     * tries to run a second command.
     *
     * @return array{0: string, 1: string} script name, malicious argument
     */
    private function writeEchoScript(string $marker): array
    {
        if (self::isWindows()) {
            $script = $this->baseDir . '/demo/scripts/echo.bat';
            file_put_contents($script, "@echo off\r\necho ARGS:%*\r\n");
            // Closes the quoted argument, runs a second command, comments
            // out the rest of the line.
            return ['echo.bat', 'x" & copy nul ' . $marker . ' & rem "'];
        }
        $script = $this->baseDir . '/demo/scripts/echo.sh';
        file_put_contents($script, "#!/bin/sh\necho \"ARGS:$*\"\n");
        chmod($script, 0755);
        return ['echo.sh', 'x"; touch ' . $marker . '; echo "'];
    }

    public function test_script_arguments_cannot_run_a_second_command(): void
    {
        $manager = $this->manager();
        $marker = $this->baseDir . '/INJECTED.txt';
        [$script, $payload] = $this->writeEchoScript($marker);

        $output = $manager->executeScript('demo', $script, [$payload]);

        // The marker file is the real signal: the second command must not
        // have run. The payload itself is echoed back as inert data.
        self::assertFileDoesNotExist($marker, 'the injected command must not run');
        self::assertStringContainsString('ARGS:', $output, 'the argument must be passed through as data');
    }

    public function test_php_scripts_are_executable(): void
    {
        $manager = $this->manager();

        $output = $manager->executeScript('demo', 'hello.php', ['world']);

        self::assertStringContainsString('PHP-SCRIPT-OK:world', $output);
    }

    public function test_reference_reads_stay_inside_the_reference_directory(): void
    {
        if (!self::isWindows()) {
            self::markTestSkipped('the junction escape is a Windows-specific path');
        }
        $manager = $this->manager();
        $outside = sys_get_temp_dir() . '/la_skillsec_out_' . bin2hex(random_bytes(4));
        mkdir($outside, 0777, true);
        file_put_contents($outside . '/SECRET.txt', 'TOP-SECRET-OUTSIDE');
        exec(
            'cmd /c mklink /J "' . $this->baseDir . '/demo/references/link" "' . $outside . '"',
            $output,
            $code,
        );
        if ($code !== 0) {
            self::markTestSkipped('mklink /J is not available');
        }

        try {
            $result = $manager->readReference('demo', 'link/SECRET.txt');
            self::assertStringNotContainsString('TOP-SECRET-OUTSIDE', $result);
            self::assertStringContainsString('Security violation', $result);
        } finally {
            self::removeTree($outside);
        }
    }

    public function test_reference_reads_still_work(): void
    {
        $manager = $this->manager();

        self::assertSame('reference content', $manager->readReference('demo', 'guide.md'));
        self::assertStringContainsString('not found', $manager->readReference('demo', 'missing.md'));
        self::assertStringContainsString(
            'Security violation',
            $manager->readReference('demo', '..' . DIRECTORY_SEPARATOR . 'outside.md'),
        );
    }

    public function test_null_byte_in_paths_is_rejected_without_an_exception(): void
    {
        $manager = $this->manager();

        self::assertStringContainsString('Error', $manager->readReference('demo', "a\0b.md"));
        self::assertStringContainsString('Error', $manager->executeScript('demo', "echo.bat\0.txt"));
    }

    public function test_skills_xml_is_escaped(): void
    {
        $manager = $this->manager();

        $xml = $manager->getSkillsXml();

        self::assertStringContainsString('A &amp; B', $xml);
        self::assertStringNotContainsString('<script>', $xml);
        $document = new \DOMDocument();
        self::assertTrue(
            @$document->loadXML($xml),
            'the skill catalogue must stay well-formed XML: ' . $xml,
        );
    }

    public function test_temporary_directories_do_not_leak(): void
    {
        $manager = $this->manager();
        $before = glob(sys_get_temp_dir() . '/lightagent_skill_*') ?: [];

        $manager->executeScript('demo', 'hello.php');

        $after = glob(sys_get_temp_dir() . '/lightagent_skill_*') ?: [];
        self::assertCount(count($before), $after, 'the script working directory must be removed');
    }

    public function test_timeout_stops_a_running_script(): void
    {
        $manager = $this->manager();
        file_put_contents(
            $this->baseDir . '/demo/scripts/slow.php',
            "<?php sleep(30); echo 'never';\n",
        );

        $started = microtime(true);
        $output = $manager->executeScript('demo', 'slow.php', [], 2);
        $elapsed = microtime(true) - $started;

        self::assertLessThan(20.0, $elapsed, 'the timeout must stop the script');
        self::assertStringContainsString('timeout', $output);
    }

    public function test_only_the_conflict_winner_is_discovered(): void
    {
        $second = sys_get_temp_dir() . '/la_skillsec_second_' . bin2hex(random_bytes(4));
        mkdir($second . '/demo', 0777, true);
        file_put_contents($second . '/demo/SKILL.md', "---\nname: demo\ndescription: second\n---\nbody");
        try {
            $manager = new SkillManager([$this->baseDir, $second]);
            $discovered = $manager->discoverSkills();

            self::assertCount(1, $discovered);
            self::assertSame('second', $discovered[0]->description);
            self::assertCount(1, $manager->skillConflicts);
        } finally {
            self::removeTree($second);
        }
    }
}
