<?php

declare(strict_types=1);

namespace LightAgent\Tests\Mcp;

use PHPUnit\Framework\TestCase;

/**
 * Documents the host-level proc_open stdout pipe condition.
 * On affected Windows hosts (EDR/AV hooking anonymous pipe inheritance)
 * this test records a skip with an explicit receipt instead of failing.
 */
final class ProcOpenIsolationTest extends TestCase
{
    public function test_plain_proc_open_in_phpunit(): void
    {
        $childScript = tempnam(sys_get_temp_dir(), 'mcp_iso_') . '.php';
        file_put_contents($childScript, '<?php echo "hi";');
        $descriptors = [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open([PHP_BINARY, $childScript], $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        usleep(400000);
        $status = proc_get_status($proc);
        stream_set_blocking($pipes[1], false);
        $out = stream_get_contents($pipes[1]);
        foreach ($pipes as $p) {
            fclose($p);
        }
        proc_close($proc);
        unlink($childScript);

        if ($out !== 'hi' && !$status['running']) {
            self::markTestSkipped(
                'Host breaks proc_open stdout pipe inheritance (child runs but stdout is lost). '
                . 'All pipe-transport integration tests skip on this host.',
            );
        }
        self::assertSame('hi', $out);
    }
}
