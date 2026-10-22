<?php

declare(strict_types=1);

namespace Stero\Shield\Runtime;

/** @param list<string> $entries */
final readonly class AllowlistResult
{
    /** @param list<string> $entries */
    public function __construct(public array $entries, public int $dropped) {}
}
