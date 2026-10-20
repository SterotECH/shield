<?php

declare(strict_types=1);

namespace Stero\Shield\Rules;

enum Severity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    /** Return 1 (Low) to 4 (Critical). Used to compare severities. */
    public function rank(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::Critical => 4,
        };
    }
}
