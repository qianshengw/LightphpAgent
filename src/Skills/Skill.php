<?php

declare(strict_types=1);

namespace LightAgent\Skills;

/**
 * Skill data object (skills.py Skill dataclass).
 */
final class Skill
{
    public string $instructions = '';
    public ?string $sourceDirectory = null;
    public int $precedence = 0;

    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $path,
        public readonly bool $hasScripts = false,
        public readonly bool $hasReferences = false,
        public readonly bool $hasAssets = false,
    ) {
    }
}
