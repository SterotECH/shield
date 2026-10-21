<?php

declare(strict_types=1);

namespace Shield\Demo;

use Stero\Shield\Store\Store;

/**
 * File-backed state for the demo only, not for production. Redis replaces it.
 * Each operation holds an exclusive lock through reading and writing.
 * Counters have fixed windows; entries expire exactly at expiresAt.
 */
final readonly class DemoFileStore implements Store
{
    /** @param (callable(): int)|null $clock */
    public function __construct(private string $path, private mixed $clock = null) {}

    public function increment(string $key, int $windowSeconds): int
    {
        return $this->update(function (array &$state, int $now) use ($key, $windowSeconds): int {
            if (!isset($state['counters'][$key]) || !($now < $state['counters'][$key]['expiresAt'])) {
                $state['counters'][$key] = ['value' => 0, 'expiresAt' => $now + $windowSeconds];
            }

            return ++$state['counters'][$key]['value'];
        }) ?? 0;
    }

    public function ban(string $ip, int $seconds): void
    {
        $this->update(function (array &$state, int $now) use ($ip, $seconds): void {
            $state['bans'][$ip] = $now + $seconds;
        });
    }

    public function isBanned(string $ip): bool
    {
        return $this->update(function (array &$state, int $now) use ($ip): bool {
            if (isset($state['bans'][$ip]) && $now < $state['bans'][$ip]) {
                return true;
            }
            unset($state['bans'][$ip]);

            return false;
        }) ?? false;
    }

    public function unban(string $ip): void
    {
        $this->update(function (array &$state) use ($ip): void {
            unset($state['bans'][$ip]);
        });
    }

    public function clear(): void
    {
        $this->update(function (array &$state): void {
            $state = ['counters' => [], 'bans' => []];
        });
    }

    /**
     * @template T
     * @param callable(array{counters: array<string, array{value: int, expiresAt: int}>, bans: array<string, int>}&, int): T $operation
     * @return T|null
     */
    private function update(callable $operation): mixed
    {
        $handle = false;
        set_error_handler(static fn(): bool => true);
        try {
            $handle = fopen($this->path, 'c+');
            if ($handle === false || !flock($handle, LOCK_EX)) {
                return null;
            }
            $contents = stream_get_contents($handle);
            $state = $contents === '' ? ['counters' => [], 'bans' => []]
                : json_decode($contents === false ? '' : $contents, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($state) || !isset($state['counters'], $state['bans'])
                || !is_array($state['counters']) || !is_array($state['bans'])) {
                return null;
            }
            $counters = [];
            foreach ($state['counters'] as $key => $counter) {
                if (!is_array($counter) || !is_int($counter['value'] ?? null) || !is_int($counter['expiresAt'] ?? null)) {
                    return null;
                }
                $counters[(string) $key] = ['value' => $counter['value'], 'expiresAt' => $counter['expiresAt']];
            }
            $bans = [];
            foreach ($state['bans'] as $ip => $expiry) {
                if (!is_int($expiry)) {
                    return null;
                }
                $bans[(string) $ip] = $expiry;
            }
            $state = ['counters' => $counters, 'bans' => $bans];
            $now = $this->clock === null ? time() : ($this->clock)();
            $result = $operation($state, $now);
            $json = json_encode($state, JSON_THROW_ON_ERROR);
            $length = mb_strlen($json, 'UTF-8');
            if (!rewind($handle) || fwrite($handle, $json) !== $length
                || !ftruncate($handle, $length) || !fflush($handle)) {
                return null;
            }

            return $result;
        } catch (\Throwable) {
            return null;
        } finally {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
            restore_error_handler();
        }
    }
}
