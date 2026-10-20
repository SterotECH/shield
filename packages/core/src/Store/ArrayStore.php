<?php

declare(strict_types=1);

namespace Shield\Core\Store;

/**
 * In-memory Store for tests and the demo. Lives only for one PHP process.
 *
 * IMPLEMENTATION NOTES
 * - Keep two arrays: counters (key => [value, expiresAt]) and bans
 *   (ip => expiresAt).
 * - Take a clock in the constructor (a callable returning the current Unix
 *   time, default time()). Tests move time forward by changing what the
 *   clock returns, so TTL behaviour can be tested without sleeping.
 * - Treat an entry as missing once its expiry is in the past.
 */
final class ArrayStore implements Store
{
    /** @param (callable(): int)|null $clock */
    public function __construct(private mixed $clock = null)
    {
    }

    public function increment(string $key, int $windowSeconds): int
    {
        throw new \LogicException('Not implemented');
    }

    public function ban(string $ip, int $seconds): void
    {
        throw new \LogicException('Not implemented');
    }

    public function isBanned(string $ip): bool
    {
        throw new \LogicException('Not implemented');
    }

    public function unban(string $ip): void
    {
        throw new \LogicException('Not implemented');
    }
}
