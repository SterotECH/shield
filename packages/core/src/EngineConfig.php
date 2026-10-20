<?php

declare(strict_types=1);

namespace Stero\Shield;

/**
 * Plain settings for the Engine. No logic here.
 *
 * IMPLEMENTATION NOTES
 * - $allowlist holds exact IPs or CIDR ranges (for example your office, your
 *   server, school networks). An allowlisted IP is never banned or blocked,
 *   but its findings are still recorded.
 * - $banSeconds is the first ban length. Repeat offenders get double each
 *   time, capped at $maxBanSeconds (see Engine::banDuration).
 * - $blockStatus is the HTTP status the app should return to a blocked or
 *   banned client. Keep it 404 so probes learn nothing.
 */
final readonly class EngineConfig
{
    /** @param list<string> $allowlist */
    public function __construct(
        public Mode $mode = Mode::Detect,
        public int $banSeconds = 86400,
        public int $maxBanSeconds = 604800,
        public array $allowlist = [],
        public int $blockStatus = 404,
    ) {}
}
