<?php

declare(strict_types=1);

namespace Stero\Shield\Store;

/**
 * In-memory Store for tests and the demo. Lives only for one PHP process.
 *
 * IMPLEMENTATION NOTES
 * - Keep two arrays: counters (key => [value, expiresAt]) and bans
 *   (ip => expiresAt).
 * - Take a clock in the constructor (a callable returning the current Unix
 *   time, default time()). Tests move time forward by changing what the
 *   clock returns, so TTL behaviour can be tested without sleeping.
 * - Treat an entry as missing at or after its expiry.
 */
final class ArrayStore implements Store
{
    /** @var array<string, array{value: int, expiresAt: int}> */
    private array $counters = [];

    /** @var array<string, int> */
    private array $bans = [];

    /** @param (callable(): int)|null $clock */
    public function __construct(private readonly mixed $clock = null) {}

    public function increment(string $key, int $windowSeconds): int
    {
        try {
            $now = $this->clock === null ? time() : ($this->clock)();
            if (!isset($this->counters[$key]) || $this->counters[$key]['expiresAt'] <= $now) {
                $this->counters[$key] = ['value' => 0, 'expiresAt' => $now + $windowSeconds];
            }

            return ++$this->counters[$key]['value'];
        } catch (\Throwable) {
            return 0;
        }
    }

    public function ban(string $ip, int $seconds): void
    {
        try {
            $now = $this->clock === null ? time() : ($this->clock)();
            $this->bans[$ip] = $now + $seconds;
        } catch (\Throwable) {
        }
    }

    public function isBanned(string $ip): bool
    {
        try {
            $now = $this->clock === null ? time() : ($this->clock)();
            if (!isset($this->bans[$ip])) {
                return false;
            }
            if ($this->bans[$ip] <= $now) {
                unset($this->bans[$ip]);

                return false;
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function unban(string $ip): void
    {
        unset($this->bans[$ip]);
    }
}
