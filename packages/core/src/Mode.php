<?php

declare(strict_types=1);

namespace Stero\Shield;

/**
 * Detect: findings are recorded, nothing is ever blocked. Start here.
 * Enforce: findings that qualify are blocked and offenders banned.
 *
 * Switching mode must be a config change only. No rule or class may behave
 * differently by checking the mode, except Engine.
 */
enum Mode: string
{
    case Detect = 'detect';
    case Enforce = 'enforce';
}
