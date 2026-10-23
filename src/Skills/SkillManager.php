<?php

declare(strict_types=1);

namespace LightAgent\Skills;

use LightAgent\Logging\LoggerManager;
use Symfony\Component\Yaml\Yaml;

/**
 * Skill manager: discover, load, and execute skills (port of skills.py).
 */
final class SkillManager
{
    /** Default wall-clock budget for a skill script (seconds). */
    public const DEFAULT_SCRIPT_TIMEOUT = 30;

    /** Upper bound for a caller-supplied timeout (seconds). */
    public const MAX_SCRIPT_TIMEOUT = 3600;

    /** @var array<string, Skill> */
    public array $skills = [];

    /** @var list<array<string, mixed>> */
    public array $skillConflicts = [];

    /**
     * @param list<string>|null $skillsDirectories
     */
    public function __construct(
        public readonly ?array $skillsDirectories = null,
        public readonly ?LoggerManager $logger = null,
    ) {
        $this->skillsDirectories ??= ['skills'];
    }

    /**
     * Discover all available skills (metadata only).
     *
     * @return list<Skill>
     */
    public function discoverSkills(): array
    {
        // Keyed by skill name: a later skills_directories entry REPLACES the
        // shadowed skill, so only the winner may reach the returned list
        // (M13). A plain list would keep both copies and let a caller act
        // on the skill that lost the conflict check.
        /** @var array<string, Skill> $discovered */
        $discovered = [];
        $this->skills = [];
        $this->skillConflicts = [];

        foreach ($this->skillsDirectories ?? [] as $precedence => $baseDir) {
            if (!is_dir($baseDir)) {
                continue;
            }
            $items = scandir($baseDir) ?: [];
            sort($items);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $skillPath = $baseDir . DIRECTORY_SEPARATOR . $item;
                $skillFile = $skillPath . DIRECTORY_SEPARATOR . 'SKILL.md';

                if (is_dir($skillPath) && is_file($skillFile)) {
                    try {
                        $skill = $this->loadSkillMetadata($skillPath);
                        if ($skill !== null) {
                            $skill->sourceDirectory = (string) realpath($baseDir);
                            $skill->precedence = $precedence;
                            $previous = $this->skills[$skill->name] ?? null;
                            if ($previous !== null) {
                                $conflict = [
                                    'name' => $skill->name,
                                    'winner' => $skill->path,
                                    'shadowed' => $previous->path,
                                    'rule' => 'later skills_directories entry wins',
                                ];
                                $this->skillConflicts[] = $conflict;
                                $this->log('WARNING', 'skill_conflict', $conflict);
                            }
                            $this->skills[$skill->name] = $skill;
                            $discovered[$skill->name] = $skill;
                            $this->log('DEBUG', 'discover_skill', ['name' => $skill->name, 'path' => $skillPath]);
                        }
                    } catch (\Throwable $e) {
                        $this->log('ERROR', 'discover_skill_failed', ['path' => $skillPath, 'error' => $e->getMessage()]);
                    }
                }
            }
        }

        return array_values($discovered);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listConflicts(): array
    {
        return array_map(static fn (array $item): array => $item, $this->skillConflicts);
    }

    /**
     * Load project instructions from filesystem root to the working directory.
     *
     * @param array<string, mixed>|null $context
     */
    public function discoverProjectInstructions(
        ?string $startDirectory = null,
        string $filename = 'AGENTS.md',
        int $maxChars = 100_000,
    ): string {
        $current = (string) realpath($startDirectory ?? getcwd() ?: '.');
        $candidates = [$current];
        while (true) {
            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $candidates[] = $parent;
            $current = $parent;
        }
        // reversed: root first
        $candidates = array_reverse($candidates);
        $contents = [];
        $total = 0;
        foreach ($candidates as $directory) {
            $instructionFile = $directory . DIRECTORY_SEPARATOR . $filename;
            if (!is_file($instructionFile)) {
                continue;
            }
            $content = (string) file_get_contents($instructionFile);
            $total += strlen($content);
            if ($total > $maxChars) {
                throw new \InvalidArgumentException("project instructions exceed max_chars={$maxChars}");
            }
            $contents[] = "# {$instructionFile}\n" . trim($content);
        }
        return implode("\n\n", $contents);
    }

    private function loadSkillMetadata(string $skillPath): ?Skill
    {
        $skillFile = $skillPath . DIRECTORY_SEPARATOR . 'SKILL.md';
        $content = (string) file_get_contents($skillFile);

        // Parse YAML frontmatter
        if (preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $content, $m) !== 1) {
            throw new \InvalidArgumentException("Missing frontmatter in {$skillFile}");
        }
        $frontmatter = Yaml::parse($m[1]);
        if (!is_array($frontmatter)) {
            throw new \InvalidArgumentException("Skill missing required fields (name, description)");
        }
        if (!isset($frontmatter['name'], $frontmatter['description'])) {
            throw new \InvalidArgumentException('Skill missing required fields (name, description)');
        }

        $hasScripts = is_dir($skillPath . DIRECTORY_SEPARATOR . 'scripts');
        $hasReferences = is_dir($skillPath . DIRECTORY_SEPARATOR . 'references');
        $hasAssets = is_dir($skillPath . DIRECTORY_SEPARATOR . 'assets');

        return new Skill(
            name: (string) $frontmatter['name'],
            description: (string) $frontmatter['description'],
            path: $skillPath,
            hasScripts: $hasScripts,
            hasReferences: $hasReferences,
            hasAssets: $hasAssets,
        );
    }

    public function activateSkill(string $skillName): string
    {
        if (!isset($this->skills[$skillName])) {
            throw new \InvalidArgumentException("Skill '{$skillName}' not found");
        }
        $skill = $this->skills[$skillName];
        $skillFile = $skill->path . DIRECTORY_SEPARATOR . 'SKILL.md';
        $content = (string) file_get_contents($skillFile);

        // Remove frontmatter, keep instructions only
        $content = (string) preg_replace('/^---\s*\n.*?\n---\s*\n/s', '', $content);
        $skill->instructions = trim($content);

        $this->log('INFO', 'activate_skill', ['name' => $skillName]);
        return $skill->instructions;
    }

    public function getSkillsXml(): string
    {
        if ($this->skills === []) {
            return '';
        }
        $xmlParts = ['<available_skills>'];
        foreach ($this->skills as $skill) {
            $xmlParts[] = '  <skill>';
            // M11: a skill name/description comes from a file on disk, so it
            // may contain `&`, `<` or `>` — emitting it raw produces invalid
            // XML and hands the model a prompt-injection channel. Escape it.
            $xmlParts[] = '    <name>' . self::escapeXml($skill->name) . '</name>';
            $xmlParts[] = '    <description>' . self::escapeXml($skill->description) . '</description>';
            $xmlParts[] = '    <location>' . self::escapeXml($skill->path . DIRECTORY_SEPARATOR . 'SKILL.md') . '</location>';
            $xmlParts[] = '  </skill>';
        }
        $xmlParts[] = '</available_skills>';
        return implode("\n", $xmlParts);
    }

    /**
     * Escape a value for XML character data.
     */
    private static function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Execute a skill script (sandboxed to a temp cwd, bounded runtime).
     *
     * The arguments come straight from the model, so the command is NEVER
     * assembled as a shell string (C1): the argv array is handed to the OS
     * and `&`, `|`, `;`, `"` and friends stay ordinary characters.
     *
     * @param list<string>|null $args
     */
    public function executeScript(
        string $skillName,
        string $scriptName,
        ?array $args = null,
        int $timeoutSeconds = self::DEFAULT_SCRIPT_TIMEOUT,
    ): string {
        if (!isset($this->skills[$skillName])) {
            return "Error: Skill '{$skillName}' not found";
        }
        // M55: a null byte makes realpath()/file_get_contents() raise a
        // ValueError (PHP 8) and `..` walks out of the scripts directory —
        // both are rejected BEFORE the first filesystem call, so the tool
        // answers with an error string instead of an uncaught exception.
        if (!self::isSafeRelativePath($scriptName)) {
            return 'Error: Security violation - invalid script name';
        }
        $skill = $this->skills[$skillName];
        $scriptsRoot = realpath($skill->path . DIRECTORY_SEPARATOR . 'scripts');
        if ($scriptsRoot === false) {
            return "Error: Script '{$scriptName}' not found in skill '{$skillName}'";
        }
        $scriptPath = realpath($scriptsRoot . DIRECTORY_SEPARATOR . $scriptName);

        // Boundary compare includes the trailing separator (H13): a sibling
        // directory named `scripts_evil` starts with the same prefix.
        if ($scriptPath === false || !str_starts_with($scriptPath, $scriptsRoot . DIRECTORY_SEPARATOR)) {
            return 'Error: Security violation - cannot execute outside scripts directory';
        }
        if (!is_file($scriptPath)) {
            return "Error: Script '{$scriptPath}' not found in skill '{$skillName}'";
        }

        $timeoutSeconds = max(1, min($timeoutSeconds, self::MAX_SCRIPT_TIMEOUT));

        $tmpdir = sys_get_temp_dir() . '/lightagent_skill_' . bin2hex(random_bytes(8));
        $stdoutFile = $tmpdir . '/stdout.txt';
        $stderrFile = $tmpdir . '/stderr.txt';
        try {
            if (!is_dir($tmpdir)) {
                mkdir($tmpdir, 0777, true);
            }
            try {
                // N16: output goes to FILES, not pipes. On Windows, process
                // pipe reads block even with stream_set_blocking(false), and
                // killing the child does not deliver EOF because PHP retains
                // a duplicate of the write handle — an in-loop deadline check
                // is therefore unreachable. With file redirection the poll
                // loop (status + file size) never blocks, and the deadline
                // below guarantees termination.
                [$command, $options] = self::buildCommand($scriptPath, $args ?? []);
                $proc = proc_open(
                    $command,
                    [
                        0 => ['pipe', 'r'],
                        1 => ['file', $stdoutFile, 'w'],
                        2 => ['file', $stderrFile, 'w'],
                    ],
                    $pipes,
                    $tmpdir,
                    null,
                    $options,
                );
                if (!is_resource($proc)) {
                    return 'Error executing script: proc_open failed';
                }
                // Close stdin right away: the child must see EOF instead of
                // waiting for input that never comes.
                if (isset($pipes[0]) && is_resource($pipes[0])) {
                    fclose($pipes[0]);
                }

                // V4-23/V4-24: a portable deadline. The old implementation
                // used a Windows-only `ping` watchdog (it never fired on
                // POSIX) and the timeout branch was effectively dead. The
                // deadline is measured on the monotonic clock, so a system
                // clock change cannot extend or shorten the run.
                $status = proc_get_status($proc);
                $pid = (int) ($status['pid'] ?? 0);
                $deadline = hrtime(true) + ($timeoutSeconds * 1_000_000_000);
                $timedOut = false;
                while (true) {
                    $status = proc_get_status($proc);
                    if (!$status['running']) {
                        break;
                    }
                    if (hrtime(true) >= $deadline) {
                        $timedOut = true;
                        break;
                    }
                    usleep(50_000);
                }
                if ($timedOut) {
                    self::terminateProcess($proc, $pid);
                    // Give the child a moment to die so proc_close does not
                    // block on a live process.
                    for ($i = 0; $i < 20; $i++) {
                        if (!proc_get_status($proc)['running']) {
                            break;
                        }
                        usleep(50_000);
                    }
                }
                proc_close($proc);
                $stdout = is_file($stdoutFile) ? (string) file_get_contents($stdoutFile) : '';
                $stderr = is_file($stderrFile) ? (string) file_get_contents($stderrFile) : '';

                $output = $stdout;
                if ($stderr !== '') {
                    $output .= "\nSTDERR:\n{$stderr}";
                }
                if ($timedOut) {
                    $output .= "\n[LA-SANDBOX] Script exceeded the {$timeoutSeconds}s timeout and was terminated.";
                    $this->log('WARNING', 'execute_script_timeout', [
                        'skill' => $skillName,
                        'script' => $scriptName,
                        'timeout' => $timeoutSeconds,
                    ]);
                }
                $this->log('DEBUG', 'execute_script', [
                    'skill' => $skillName,
                    'script' => $scriptName,
                    'output' => mb_substr($output, 0, 200),
                ]);
                return $output;
            } finally {
                // M12: rmdir() cannot remove a directory that still holds
                // stdout.txt/stderr.txt, and `@` hid the warning — every
                // run leaked a directory. Unlink the output files first.
                @unlink($stdoutFile);
                @unlink($stderrFile);
                @rmdir($tmpdir);
            }
        } catch (\Throwable $e) {
            return 'Error executing script: ' . $e->getMessage();
        }
    }

    /**
     * Build the command used to launch a skill script.
     *
     * Returns the proc_open() command plus its options:
     *  - an argv ARRAY for everything the OS can execute directly — no
     *    shell is involved, so the arguments can never be interpreted as
     *    syntax (C1);
     *  - a cmd.exe command line for a Windows batch file, which is the one
     *    case where the operating system itself routes the launch through
     *    cmd.exe. There the arguments are escaped for cmd.exe so that they
     *    stay a single inert token (see escapeCmdArgument).
     *
     * @param list<string> $args
     * @return array{0: list<string>|string, 1: array<string, mixed>}
     */
    private static function buildCommand(string $scriptPath, array $args): array
    {
        $args = array_values(array_map(static fn (mixed $arg): string => (string) $arg, $args));
        $extension = strtolower(pathinfo($scriptPath, PATHINFO_EXTENSION));

        // C2: CreateProcess cannot execute a .php file ("%1 is not a valid
        // Win32 application"), so PHP scripts are launched through the
        // interpreter — still without a shell.
        if ($extension === 'php') {
            return [array_merge([PHP_BINARY, '-f', $scriptPath], $args), []];
        }

        if (PHP_OS_FAMILY === 'Windows' && ($extension === 'bat' || $extension === 'cmd')) {
            return [self::buildCmdCommandLine($scriptPath, $args), ['bypass_shell' => true]];
        }

        return [array_merge([$scriptPath], $args), []];
    }

    /**
     * Assemble the `cmd.exe /c` command line for a Windows batch file.
     *
     * cmd.exe parses `/c <command line>` as a SHELL command line: `&`,
     * `|`, `<`, `>` and `^` separate commands unless they sit inside a
     * double-quoted token, and a bare `"` ends that token. Every argument
     * is therefore wrapped in double quotes and its own quotes are doubled
     * (`""` is a literal quote inside a quoted token), which keeps the
     * whole argument one inert token.
     *
     * The outer pair of quotes exists because `cmd /s /c` strips the
     * leading quote and the trailing quote of the command string.
     */
    private static function buildCmdCommandLine(string $scriptPath, array $args): string
    {
        $parts = [self::escapeCmdArgument($scriptPath)];
        foreach ($args as $arg) {
            $parts[] = self::escapeCmdArgument($arg);
        }
        $comspec = getenv('COMSPEC');
        $cmdExe = (is_string($comspec) && $comspec !== '') ? $comspec : 'cmd.exe';

        // The command line is handed to CreateProcess as a string, so the
        // executable is quoted (a Windows installation may live in a path
        // with spaces).
        return '"' . $cmdExe . '" /d /v:off /s /c ""' . implode('" "', $parts) . '""';
    }

    /**
     * Escape one argument for a cmd.exe command line.
     *
     * - `"` is doubled: inside a quoted token `""` is a literal quote and
     *   does not end the token;
     * - `%` is escaped with a caret: cmd.exe expands `%VAR%` on the
     *   command line, and the expanded value could otherwise re-open the
     *   token (`^%` is the literal form on a command line);
     * - CR/LF would end the command line and are dropped.
     *
     * The result still carries the caret in front of a literal `%`; that
     * is the price of not letting cmd.exe expand the argument.
     */
    private static function escapeCmdArgument(string $value): string
    {
        $value = str_replace(["\r", "\n", "\0"], '', $value);
        // The caret itself must be doubled before new carets are added.
        $value = str_replace('^', '^^', $value);
        $value = str_replace('"', '""', $value);
        $value = str_replace('%', '^%', $value);
        return $value;
    }

    /**
     * Terminate a running script (and, where possible, its children).
     */
    private static function terminateProcess(mixed $proc, int $pid): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // proc_terminate() only stops the direct child on Windows, so
            // the tree is killed with taskkill /T (no shell involved).
            if ($pid > 0) {
                $killer = @proc_open(
                    ['taskkill', '/F', '/T', '/PID', (string) $pid],
                    [
                        1 => ['file', 'NUL', 'w'],
                        2 => ['file', 'NUL', 'w'],
                    ],
                    $killerPipes,
                    null,
                    null,
                );
                if (is_resource($killer)) {
                    proc_close($killer);
                }
            }
            @proc_terminate($proc);
            return;
        }
        // POSIX: SIGKILL the child; the grace loop in the caller waits for
        // it to disappear.
        @proc_terminate($proc, defined('SIGKILL') ? SIGKILL : 9);
    }

    public function readReference(string $skillName, string $refPath): string
    {
        if (!isset($this->skills[$skillName])) {
            return "Error: Skill '{$skillName}' not found";
        }
        // M55: a null byte makes realpath()/file_get_contents() raise a
        // ValueError (PHP 8) and `..` walks out of the references
        // directory — both are rejected before the first filesystem call.
        if (!self::isSafeRelativePath($refPath)) {
            return 'Error: Security violation - invalid reference path';
        }
        $skill = $this->skills[$skillName];
        $referencesRoot = realpath($skill->path . DIRECTORY_SEPARATOR . 'references');
        if ($referencesRoot === false) {
            return "Error: Reference '{$refPath}' not found";
        }
        $joined = $referencesRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($refPath, '/\\'));
        // H17: resolve the REAL path. A junction/symlink inside
        // references/ passes a purely lexical check but points outside the
        // skill directory — the boundary compare below runs on the
        // resolved path, exactly like readAsset() does.
        $resolved = realpath($joined);
        if ($resolved === false) {
            // Python: (root / ref).resolve() then is_relative_to check — a
            // missing file is "not found", not a security violation.
            return "Error: Reference '{$refPath}' not found";
        }
        if (!str_starts_with($resolved, $referencesRoot . DIRECTORY_SEPARATOR)) {
            return 'Error: Security violation - invalid reference path';
        }
        try {
            return (string) file_get_contents($resolved);
        } catch (\Throwable $e) {
            return 'Error reading reference: ' . $e->getMessage();
        }
    }

    /**
     * True when the path is a plain relative path that cannot escape its
     * root directory.
     *
     * A null byte makes every path function raise a ValueError in PHP 8
     * (M55) and a `..` segment walks out of the root (H17) — both are
     * rejected before the filesystem is touched.
     */
    private static function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }
        $segments = preg_split('#[/\\\\]#', $path) ?: [];
        foreach ($segments as $segment) {
            if ($segment === '..') {
                return false;
            }
        }
        return true;
    }

    /**
     * @return string binary content
     */
    public function readAsset(string $skillName, string $assetPath): string
    {
        if (!isset($this->skills[$skillName])) {
            throw new \InvalidArgumentException("Skill '{$skillName}' not found");
        }
        $skill = $this->skills[$skillName];
        $assetsRoot = realpath($skill->path . DIRECTORY_SEPARATOR . 'assets');
        if ($assetsRoot === false) {
            throw new \InvalidArgumentException('Security violation: invalid asset path');
        }
        // M55: a null byte makes realpath() raise a ValueError (PHP 8) and
        // `..` walks out of the assets directory.
        if (!self::isSafeRelativePath($assetPath)) {
            throw new \InvalidArgumentException('Security violation: invalid asset path');
        }
        $fullPath = realpath($assetsRoot . DIRECTORY_SEPARATOR . $assetPath);
        if ($fullPath === false || !str_starts_with($fullPath, $assetsRoot . DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('Security violation: invalid asset path');
        }
        return (string) file_get_contents($fullPath);
    }

    private function log(string $level, string $action, mixed $data): void
    {
        if ($this->logger !== null) {
            $this->logger->log($level, $action, $data);
        }
    }
}
