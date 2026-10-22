<?php

declare(strict_types=1);

namespace Stero\Shield\Runtime;

use Stero\Shield\Mode;

/** @param list<string> $warnings */
final readonly class StatusReport
{
    /** @param list<string> $warnings */
    public function __construct(
        public bool $enabled,
        public Mode $mode,
        public string $eventsPath,
        public bool $eventsWritable,
        public bool $allowlistFileExists,
        public int $allowlistFileCount,
        public int $allowlistExtraCount,
        public string $redisHost,
        public int $redisDatabase,
        public bool $redisConnected,
        public bool $redisPing,
        public string $storeClass,
        public array $warnings,
        public bool $healthy,
    ) {}
}
