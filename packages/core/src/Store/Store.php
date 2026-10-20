<?php

declare(strict_types=1);

namespace Stero\Shield\Store;

/**
 * Short-lived shared state: counters and bans. PHP forgets everything
 * between requests, so this state lives outside PHP (Redis in production,
 * an array in tests).
 *
 * CONTRACT
 * - Every method must be fast (one round trip at most).
 * - Failures must not throw to the caller. On error, increment() returns 0,
 *   isBanned() returns false (fail open), ban() and unban() do nothing.
 * - Keys are opaque strings chosen by the caller. Implementations should
 *   prefix them (for example "shield:") so they never collide with app keys.
 */
interface Store
{
    /**
     * Add one to the counter and return the new value. When the key does not
     * exist, create it with expiry $windowSeconds. Later calls must NOT
     * extend the expiry (fixed window). Must be atomic.
     */
    public function increment(string $key, int $windowSeconds): int;

    /** Ban an IP for $seconds. A later ban replaces the earlier expiry. */
    public function ban(string $ip, int $seconds): void;

    public function isBanned(string $ip): bool;

    public function unban(string $ip): void;
}
