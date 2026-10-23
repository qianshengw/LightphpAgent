<?php

declare(strict_types=1);

namespace LightAgent\Tests;

use LightAgent\Version;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    public function test_version_matches_python_package(): void
    {
        self::assertSame('0.11.0', Version::VERSION);
    }
}
