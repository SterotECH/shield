<?php

declare(strict_types=1);

namespace Shield\Core\Rules;

enum Severity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    /** TODO: return 1 (Low) to 4 (Critical) using match($this). Used to compare severities. */
    public function rank(): int
    {
        throw new \LogicException('Not implemented');
    }
}
