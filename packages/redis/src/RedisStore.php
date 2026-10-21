<?php

declare(strict_types=1);

namespace Stero\Shield\Redis;

use Stero\Shield\Store\Store;
use Throwable;

/** Fixed-window counters and expiring bans. Discard this instance after each request. */
final class RedisStore implements Store
{
    private bool $failed = false;

    private const string INCREMENT = <<<'LUA'
local count = redis.call('INCR', KEYS[1])
if count == 1 then
    redis.call('EXPIRE', KEYS[1], ARGV[1])
elseif redis.call('TTL', KEYS[1]) == -1 then
    redis.call('EXPIRE', KEYS[1], ARGV[1])
end
return count
LUA;

    public function __construct(private readonly Client $client, private readonly string $prefix = 'shield:') {}

    public function increment(string $key, int $windowSeconds): int
    {
        if ($this->failed) {
            return 0;
        }
        try {
            $count = $this->client->eval(self::INCREMENT, [$this->prefix . 'c:' . $key], [max(1, $windowSeconds)]);
            if (!is_int($count)) {
                throw new \UnexpectedValueException('Invalid counter response');
            }
            return $count;
        } catch (Throwable) {
            $this->failed = true;
            return 0;
        }
    }

    public function ban(string $ip, int $seconds): void
    {
        if ($this->failed) {
            return;
        }
        try {
            $this->client->set($this->prefix . 'ban:' . $ip, '1', max(1, $seconds));
        } catch (Throwable) {
            $this->failed = true;
        }
    }

    public function isBanned(string $ip): bool
    {
        if ($this->failed) {
            return false;
        }
        try {
            return $this->client->exists($this->prefix . 'ban:' . $ip);
        } catch (Throwable) {
            $this->failed = true;
            return false;
        }
    }

    public function unban(string $ip): void
    {
        if ($this->failed) {
            return;
        }
        try {
            $this->client->del($this->prefix . 'ban:' . $ip);
        } catch (Throwable) {
            $this->failed = true;
        }
    }
}
