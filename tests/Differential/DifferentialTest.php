<?php

declare(strict_types=1);

namespace LightAgent\Tests\Differential;

use PHPUnit\Framework\TestCase;

/**
 * Differential harness: the Python original is used as a READ-ONLY oracle.
 *
 * The same input is fed to the PHP port and to the Python original; both
 * observed outcomes are compared and classified:
 *
 *   MATCH          both sides observed the same thing
 *   PHP_STRICTER   PHP blocked / narrowed where Python allowed  (an improvement)
 *   PHP_LAXER      PHP allowed where Python blocked              (a hole -> FAIL)
 *   DIVERGENT      the observations differ in kind             (needs review)
 *
 * The original is never modified: the runner copies it to a temp directory,
 * neutralises the package __init__ (it needs the optional `mcp` package) and
 * imports from the copy.
 *
 * Point LIGHTAGENT_PYTHON_ORIGINAL at the original tree to enable it; when
 * the tree is absent the whole suite skips.
 */
final class DifferentialTest extends TestCase
{
    private const MATCH = 'MATCH';
    private const PHP_STRICTER = 'PHP_STRICTER';
    private const PHP_LAXER = 'PHP_LAXER';
    private const KNOWN_LAXER = 'KNOWN_LAXER';
    private const DIVERGENT = 'DIVERGENT';

    /** @var list<array<string, mixed>> */
    private array $report = [];

    /**
     * Locate the Python original tree.
     *
     * Set LIGHTAGENT_PYTHON_ORIGINAL to `off` to disable the harness even when
     * a sibling checkout happens to be present.
     */
    private static function originalRoot(): ?string
    {
        $candidates = [];
        $fromEnv = getenv('LIGHTAGENT_PYTHON_ORIGINAL');
        if (is_string($fromEnv) && $fromEnv !== '') {
            if (in_array(strtolower($fromEnv), ['off', 'none', '0', 'false'], true)) {
                return null;
            }
            $candidates[] = $fromEnv;
        }
        // Sibling checkout: <workspace>/LightAgent-main/LightAgent
        $sibling = dirname(__DIR__, 3) . '/LightAgent-main/LightAgent';
        $candidates[] = $sibling;
        $candidates[] = dirname(__DIR__, 3) . '/../LightAgent-main/LightAgent';

        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if ($real !== false && is_file($real . '/protocol.py') && is_file($real . '/shared_memory.py')) {
                return $real;
            }
        }
        return null;
    }

    private static function pythonBinary(): ?string
    {
        foreach (['python', 'python3', 'py'] as $name) {
            $probe = @shell_exec(sprintf('%s -c "import sys; print(sys.executable)" 2>NUL', escapeshellarg($name)));
            if (is_string($probe) && trim($probe) !== '') {
                $bin = trim($probe);
                if (is_file($bin)) {
                    return $bin;
                }
            }
        }
        return null;
    }

    /**
     * Run one of the runners and decode its JSON observations.
     *
     * @return array<string, mixed>|null
     */
    private function runRunner(array $command): ?array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($command, $descriptors, $pipes, dirname(__DIR__, 2), null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            return null;
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($proc);

        $decoded = json_decode($stdout, true);
        if (!is_array($decoded)) {
            fwrite(STDERR, "runner produced no JSON: {$stderr}\n");
            return null;
        }
        return $decoded;
    }

    public function test_php_port_matches_the_python_original_where_it_matters(): void
    {
        $original = self::originalRoot();
        if ($original === null) {
            self::markTestSkipped(
                'the Python original tree was not found; set LIGHTAGENT_PYTHON_ORIGINAL to enable the differential harness',
            );
        }
        $python = self::pythonBinary();
        if ($python === null) {
            self::markTestSkipped('no Python interpreter is available for the differential harness');
        }

        $scenarioFile = __DIR__ . '/scenarios.json';
        self::assertFileExists($scenarioFile);

        $phpObserved = $this->runRunner([PHP_BINARY, __DIR__ . '/php_runner.php', $scenarioFile]);
        self::assertNotNull($phpObserved, 'the PHP runner produced no observations');

        $pythonObserved = $this->runRunner([$python, __DIR__ . '/python_runner.py', $scenarioFile, $original]);
        self::assertNotNull($pythonObserved, 'the Python runner produced no observations');

        $definition = json_decode((string) file_get_contents($scenarioFile), true);
        $scenarios = $definition['scenarios'] ?? [];

        $report = [];
        $laxer = [];
        $divergent = [];
        $known = self::knownDivergences();
        $knownSeen = [];
        foreach ($scenarios as $scenario) {
            $id = (string) $scenario['id'];
            $kind = (string) ($scenario['kind'] ?? 'scalar');
            $phpValue = $phpObserved[$id] ?? '<missing>';
            $pythonValue = $pythonObserved[$id] ?? '<missing>';

            if ($phpValue === $pythonValue) {
                $verdict = self::MATCH;
            } elseif ($kind === 'bool' && is_bool($phpValue) && is_bool($pythonValue)) {
                $verdict = $phpValue === false ? self::PHP_STRICTER : self::PHP_LAXER;
            } else {
                $verdict = self::DIVERGENT;
            }

            if ($verdict === self::PHP_LAXER && isset($known[$id])) {
                // Tracked, accepted-for-now divergence.
                $knownSeen[$id] = true;
                $verdict = self::KNOWN_LAXER;
            }

            $report[] = [
                'id' => $id,
                'group' => (string) ($scenario['group'] ?? ''),
                'kind' => $kind,
                'php' => $phpValue,
                'python' => $pythonValue,
                'verdict' => $verdict,
            ];
            if ($verdict === self::PHP_LAXER) {
                $laxer[] = $report[count($report) - 1];
            }
            if ($verdict === self::DIVERGENT) {
                $divergent[] = $report[count($report) - 1];
            }
        }

        // An accepted divergence that no longer reproduces must be cleaned up.
        $stale = array_values(array_diff(array_keys($known), array_keys($knownSeen)));
        foreach ($stale as $id) {
            $report[] = [
                'id' => $id,
                'group' => '(known divergence)',
                'kind' => 'bool',
                'php' => '<no longer reproduces>',
                'python' => '<no longer reproduces>',
                'verdict' => 'STALE_KNOWN_DIVERGENCE',
            ];
        }

        $this->report = $report;
        $summary = [
            'total' => count($report),
            'match' => count(array_filter($report, static fn (array $r): bool => $r['verdict'] === self::MATCH)),
            'stricter' => count(array_filter($report, static fn (array $r): bool => $r['verdict'] === self::PHP_STRICTER)),
            'known_laxer' => count(array_filter($report, static fn (array $r): bool => $r['verdict'] === self::KNOWN_LAXER)),
            'laxer' => count($laxer),
            'divergent' => count($divergent),
        ];
        fwrite(STDERR, "\n--- differential summary ---\n" . json_encode($summary, JSON_UNESCAPED_UNICODE) . "\n");
        foreach ($report as $row) {
            if (in_array($row['verdict'], [self::PHP_LAXER, self::KNOWN_LAXER, self::DIVERGENT, 'STALE_KNOWN_DIVERGENCE'], true)) {
                fwrite(STDERR, sprintf(
                    "  %-22s %s [%s] php=%s python=%s\n",
                    $row['verdict'],
                    $row['id'],
                    $row['group'],
                    var_export($row['php'], true),
                    var_export($row['python'], true),
                ));
            }
        }

        // A PHP port that is LOOSER than the original is a hole: the original
        // blocks it and the port lets it through.
        self::assertSame(
            [],
            $laxer,
            "the PHP port must never be looser than the Python original:\n"
            . json_encode($laxer, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );
        // An accepted divergence that no longer reproduces is stale bookkeeping.
        self::assertSame(
            [],
            $stale,
            "these accepted divergences no longer reproduce — remove them from known_divergences.json:\n"
            . json_encode($stale),
        );
    }

    /**
     * The accepted-for-now divergences.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function knownDivergences(): array
    {
        $file = __DIR__ . '/known_divergences.json';
        if (!is_file($file)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        $entries = [];
        foreach (($decoded['entries'] ?? []) as $entry) {
            $id = (string) ($entry['id'] ?? '');
            if ($id !== '') {
                $entries[$id] = $entry;
            }
        }
        return $entries;
    }

    /**
     * The differential observations, for reporting.
     *
     * @return list<array<string, mixed>>
     */
    public function report(): array
    {
        return $this->report;
    }
}
