<?php

declare(strict_types=1);

namespace Stero\Shield\Runtime\Events;

/** One chronological run of events for an IP and rule. */
final readonly class Incident
{
    public function __construct(
        public string $ip,
        public string $rule,
        public string $severity,
        public ?string $tenant,
        public int $count,
        public string $firstTs,
        public string $lastTs,
        public string $verdict,
        public string $samplePath,
    ) {}
}
