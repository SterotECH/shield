<?php

declare(strict_types=1);

namespace Shield\Runtime;

use Stero\Shield\Decision;

final readonly class Inspection
{
    public function __construct(
        public Decision $decision,
        public int $inlineMicros,
        public bool $skipped,
    ) {}

    public static function allow(): self
    {
        return new self(Decision::allow(), 0, true);
    }
}
