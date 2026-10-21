<?php

declare(strict_types=1);

namespace Stero\Shield\Redis;

use Redis;
use Throwable;

/** Bounded connection and read timeouts; connection failures never escape. */
final class RedisConnector
{
    public static function connect(string $host, int $port, ?string $password, int $connectTimeoutMs = 100, int $readTimeoutMs = 100): ?Redis
    {
        try {
            $redis = new Redis();
            if (!$redis->connect($host, $port, max(1, $connectTimeoutMs) / 1000, null, 0, max(1, $readTimeoutMs) / 1000)) {
                return null;
            }
            if ($password !== null && !$redis->auth($password)) {
                return null;
            }
            return $redis;
        } catch (Throwable) {
            return null;
        }
    }
}
