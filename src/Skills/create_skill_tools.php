<?php

declare(strict_types=1);

namespace LightAgent\Skills;

use LightAgent\Support\Json;
use LightAgent\Tools\ToolFunction;

/**
 * Create tools for interacting with skills (port of skill_tools.py).
 *
 * @return list<ToolFunction>
 */
function create_skill_tools(SkillManager $skillManager): array
{
    $listSkills = new ToolFunction(
        callable: static function () use ($skillManager): string {
            $skills = [];
            foreach ($skillManager->skills as $skill) {
                $skills[] = [
                    'name' => $skill->name,
                    'description' => $skill->description,
                    'has_scripts' => $skill->hasScripts,
                    'has_references' => $skill->hasReferences,
                    'has_assets' => $skill->hasAssets,
                ];
            }
            return Json::encodeOrError(['skills' => $skills], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        },
        toolInfo: [
            'tool_name' => 'list_skills',
            'tool_title' => '列出可用技能',
            'tool_description' => '获取所有已加载技能的列表及其描述',
            'tool_params' => [],
        ],
    );

    $activateSkill = new ToolFunction(
        callable: static function (string $skillName) use ($skillManager): string {
            try {
                return $skillManager->activateSkill($skillName);
            } catch (\Throwable $e) {
                return '激活技能失败: ' . $e->getMessage();
            }
        },
        toolInfo: [
            'tool_name' => 'activate_skill',
            'tool_title' => '激活技能',
            'tool_description' => '加载指定技能的完整指令到上下文',
            'tool_params' => [
                ['name' => 'skill_name', 'description' => '要激活的技能名称', 'type' => 'string', 'required' => true],
            ],
        ],
    );

    $executeSkillScript = new ToolFunction(
        callable: static function (string $skillName, string $scriptName, ?array $args = null, int $timeout = SkillManager::DEFAULT_SCRIPT_TIMEOUT) use ($skillManager): string {
            return $skillManager->executeScript($skillName, $scriptName, $args, $timeout);
        },
        toolInfo: [
            'tool_name' => 'execute_skill_script',
            'tool_title' => '执行技能脚本',
            'tool_description' => '执行技能目录scripts/下的脚本文件',
            'tool_params' => [
                ['name' => 'skill_name', 'description' => '技能名称', 'type' => 'string', 'required' => true],
                ['name' => 'script_name', 'description' => '脚本文件名', 'type' => 'string', 'required' => true],
                ['name' => 'args', 'description' => '脚本参数列表', 'type' => 'array', 'required' => false],
                ['name' => 'timeout', 'description' => '脚本执行超时时间（秒），默认30秒', 'type' => 'integer', 'required' => false],
            ],
        ],
    );

    $readSkillReference = new ToolFunction(
        callable: static function (string $skillName, string $refPath) use ($skillManager): string {
            return $skillManager->readReference($skillName, $refPath);
        },
        toolInfo: [
            'tool_name' => 'read_skill_reference',
            'tool_title' => '读取技能参考文档',
            'tool_description' => '读取技能目录references/下的文档内容',
            'tool_params' => [
                ['name' => 'skill_name', 'description' => '技能名称', 'type' => 'string', 'required' => true],
                ['name' => 'ref_path', 'description' => '参考文档路径', 'type' => 'string', 'required' => true],
            ],
        ],
    );

    return [$listSkills, $activateSkill, $executeSkillScript, $readSkillReference];
}
