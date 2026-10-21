<?php

declare(strict_types=1);

namespace Stero\Shield\Redis;

use Redis;
use RuntimeException;

/** Native phpredis adapter. Failed writes are surfaced for the store to catch. */
final class PhpRedisClient implements Client
{
    public function __construct(private readonly Redis $redis) {}

    public function eval(string $script, array $keys, array $args): mixed
    {
        return $this->redis->eval($script, [...$keys, ...$args], count($keys));
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        if ($this->redis->set($key, $value, ['ex' => $ttlSeconds]) !== true) {
            throw new RuntimeException('Redis write failed');
        }
    }

    public function exists(string $key): bool
    {
        return $this->redis->exists($key) > 0;
    }

    public function del(string $key): void
    {
        if ($this->redis->del($key) === false) {
            throw new RuntimeException('Redis delete failed');
        }
    }

    public function ttl(string $key): int
    {
        $ttl = $this->redis->ttl($key);
        if (!is_int($ttl)) {
            throw new RuntimeException('Invalid Redis TTL');
        }
        return $ttl;
    }
}
