<?php

declare(strict_types=1);

namespace Stero\Shield\Runtime\Agent;

use InvalidArgumentException;

/** Fixed, package-owned instruction destinations. */
enum AgentTarget: string
{
    case Claude = 'claude';
    case Agents = 'agents';
    case Cursor = 'cursor';
    case Copilot = 'copilot';
    case Shared = 'shared';

    public function path(): string
    {
        return match ($this) {
            self::Claude => '.claude/skills/shield/SKILL.md',
            self::Agents => 'AGENTS.md',
            self::Cursor => '.cursor/rules/shield.mdc',
            self::Copilot => '.github/instructions/shield.instructions.md',
            self::Shared => '.agents/skills/shield/SKILL.md',
        };
    }

    /** @return list<AgentTarget> */
    public static function fromList(string $list): array
    {
        if (! mb_check_encoding($list, 'UTF-8')) {
            throw new InvalidArgumentException('Agent list must be valid UTF-8.');
        }
        $names = array_map(static fn(string $name): string => mb_trim($name, null, 'UTF-8'), explode(',', $list));
        if ($names === ['all']) {
            return self::cases();
        }
        if ($names === ['none']) {
            return [];
        }
        $targets = [];
        foreach ($names as $name) {
            $target = self::tryFrom($name);
            if ($target === null) {
                throw new InvalidArgumentException('Invalid agent target "' . $name . '". Use all or none alone, or a comma list of agent names.');
            }
            if (! in_array($target, $targets, true)) {
                $targets[] = $target;
            }
        }

        return $targets;
    }
}
