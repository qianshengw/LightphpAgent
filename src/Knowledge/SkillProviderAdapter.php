<?php

declare(strict_types=1);

namespace LightAgent\Knowledge;

use LightAgent\Capabilities\BaseCapabilityProvider;
use LightAgent\Capabilities\CapabilityRisk;
use LightAgent\Capabilities\CapabilitySpec;

/**
 * Expose SkillManager operations as capabilities (knowledge.py SkillProviderAdapter).
 */
final class SkillProviderAdapter extends BaseCapabilityProvider
{
    public function __construct(private readonly SkillManager $skillManager)
    {
        $this->name = 'skills';
        $this->version = '1';
        parent::__construct([
            new CapabilitySpec('skill.list', read: true),
            new CapabilitySpec('skill.activate', read: true),
            new CapabilitySpec('skill.read_reference', read: true),
            new CapabilitySpec(
                'skill.execute_script',
                execute: true,
                risk: CapabilityRisk::SENSITIVE,
                requiresSandbox: true,
                requiresApproval: true,
            ),
        ]);
    }

    public function invoke(string $capability, mixed ...$arguments): mixed
    {
        if ($capability === 'skill.list') {
            $out = [];
            foreach ($this->skillManager->skills as $skill) {
                $out[] = [
                    'name' => $skill->name,
                    'description' => $skill->description,
                    'path' => $skill->path,
                    'instructions' => $skill->instructions,
                    'has_scripts' => $skill->hasScripts,
                    'has_references' => $skill->hasReferences,
                    'has_assets' => $skill->hasAssets,
                    'source_directory' => $skill->sourceDirectory,
                    'precedence' => $skill->precedence,
                ];
            }
            return $out;
        }
        return match ($capability) {
            'skill.activate' => $this->skillManager->activateSkill((string) ($arguments['skill_name'] ?? $arguments[0] ?? '')),
            'skill.read_reference' => $this->skillManager->readReference(
                (string) ($arguments['skill_name'] ?? $arguments[0] ?? ''),
                (string) ($arguments['ref_path'] ?? $arguments[1] ?? ''),
            ),
            'skill.execute_script' => $this->skillManager->executeScript(
                (string) ($arguments['skill_name'] ?? $arguments[0] ?? ''),
                (string) ($arguments['script_name'] ?? $arguments[1] ?? ''),
                $arguments['args'] ?? null,
            ),
            default => throw new \RuntimeException("unknown capability: {$capability}"),
        };
    }
}
