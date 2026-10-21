<?php

declare(strict_types=1);

namespace Stero\Shield\Redis;

use Stero\Shield\Store\Store;

/** Inert fallback when a Redis connection cannot be established. */
final class NullStore implements Store
{
    public function increment(string $key, int $windowSeconds): int
    {
        return 0;
    }

    public function ban(string $ip, int $seconds): void {}

    public function isBanned(string $ip): bool
    {
        return false;
    }

    public function unban(string $ip): void {}
}
