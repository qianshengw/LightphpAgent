<?php

declare(strict_types=1);

namespace LightAgent\Tests\Skills;

use LightAgent\Logging\LoggerManager;
use LightAgent\Skills\SkillManager;
use PHPUnit\Framework\TestCase;

final class SkillManagerTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/la_skills_' . bin2hex(random_bytes(4));
        mkdir($this->baseDir . '/my-skill', 0777, true);
        mkdir($this->baseDir . '/my-skill/scripts', 0777, true);
        mkdir($this->baseDir . '/my-skill/references', 0777, true);
        file_put_contents($this->baseDir . '/my-skill/SKILL.md', <<<'MD'
---
name: my-skill
description: A test skill for parsing
---

# Instructions

Do the thing carefully.
MD);
        file_put_contents($this->baseDir . '/my-skill/references/guide.md', 'reference content');
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->baseDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->baseDir);
    }

    private function makeManager(): SkillManager
    {
        return new SkillManager([$this->baseDir]);
    }

    public function test_discover_skills_parses_frontmatter(): void
    {
        $manager = $this->makeManager();
        $discovered = $manager->discoverSkills();

        self::assertCount(1, $discovered);
        self::assertSame('my-skill', $discovered[0]->name);
        self::assertSame('A test skill for parsing', $discovered[0]->description);
        self::assertTrue($discovered[0]->hasScripts);
        self::assertTrue($discovered[0]->hasReferences);
        self::assertFalse($discovered[0]->hasAssets);
        self::assertArrayHasKey('my-skill', $manager->skills);
    }

    public function test_discover_missing_directory_is_noop(): void
    {
        $manager = new SkillManager([$this->baseDir . '/nonexistent']);
        self::assertSame([], $manager->discoverSkills());
    }

    public function test_skill_missing_frontmatter_is_skipped_with_error_log(): void
    {
        mkdir($this->baseDir . '/broken', 0777, true);
        file_put_contents($this->baseDir . '/broken/SKILL.md', "no frontmatter here\n");
        $logFile = $this->baseDir . '/test.log';
        $manager = new SkillManager([$this->baseDir], new LoggerManager('t', debug: true, logLevel: 'INFO', logFile: $logFile));

        $discovered = $manager->discoverSkills();
        self::assertCount(1, $discovered); // broken one skipped
        self::assertFileExists($logFile);
        self::assertStringContainsString('discover_skill_failed', (string) file_get_contents($logFile));
    }

    public function test_activate_skill_strips_frontmatter(): void
    {
        $manager = $this->makeManager();
        $manager->discoverSkills();

        $instructions = $manager->activateSkill('my-skill');
        self::assertStringContainsString('Do the thing carefully.', $instructions);
        self::assertStringNotContainsString('frontmatter', $instructions);
        self::assertStringNotContainsString('name: my-skill', $instructions);
    }

    public function test_activate_unknown_skill_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeManager()->activateSkill('nope');
    }

    public function test_get_skills_xml(): void
    {
        $manager = $this->makeManager();
        self::assertSame('', $manager->getSkillsXml());

        $manager->discoverSkills();
        $xml = $manager->getSkillsXml();
        self::assertStringContainsString('<available_skills>', $xml);
        self::assertStringContainsString('<name>my-skill</name>', $xml);
        self::assertStringContainsString('<description>A test skill for parsing</description>', $xml);
        self::assertStringContainsString('</available_skills>', $xml);
    }

    public function test_read_reference_with_traversal_blocked(): void
    {
        $manager = $this->makeManager();
        $manager->discoverSkills();

        self::assertSame('reference content', $manager->readReference('my-skill', 'guide.md'));
        // Path traversal lands outside references/ -> security violation
        self::assertStringContainsString('Security violation', $manager->readReference('my-skill', '..' . DIRECTORY_SEPARATOR . 'outside.md'));
        self::assertStringContainsString('not found', $manager->readReference('my-skill', 'missing.md'));
    }

    public function test_conflicts_later_directory_wins(): void
    {
        $secondDir = $this->baseDir . '_second';
        mkdir($secondDir . '/my-skill', 0777, true);
        file_put_contents($secondDir . '/my-skill/SKILL.md', "---\nname: my-skill\ndescription: shadowing skill\n---\n\n# Shadow\n");
        try {
            $manager = new SkillManager([$this->baseDir, $secondDir]);
            $manager->discoverSkills();

            self::assertCount(1, $manager->skillConflicts);
            self::assertSame('my-skill', $manager->skillConflicts[0]['name']);
            self::assertSame($secondDir . DIRECTORY_SEPARATOR . 'my-skill', $manager->skillConflicts[0]['winner']);
            self::assertSame($secondDir . DIRECTORY_SEPARATOR . 'my-skill', $manager->skills['my-skill']->path);
        } finally {
            @unlink($secondDir . '/my-skill/SKILL.md');
            @rmdir($secondDir . '/my-skill');
            @rmdir($secondDir);
        }
    }
}
