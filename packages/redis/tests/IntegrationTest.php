<?php

declare(strict_types=1);

use Stero\Shield\Redis\PhpRedisClient;
use Stero\Shield\Redis\RedisConnector;
use Stero\Shield\Redis\RedisStore;

beforeEach(function (): void {
    $endpoint = getenv('SHIELD_TEST_REDIS');
    if ($endpoint === false || $endpoint === '') {
        $this->markTestSkipped('Enable with SHIELD_TEST_REDIS=host:port composer test');
    }
    if (!mb_check_encoding($endpoint, 'UTF-8')) {
        throw new RuntimeException('Invalid Redis endpoint');
    }
    $separator = mb_strrpos($endpoint, ':', 0, 'UTF-8');
    if ($separator === false) {
        throw new RuntimeException('Expected host:port');
    }
    $this->redis = RedisConnector::connect(mb_substr($endpoint, 0, $separator, 'UTF-8'), (int) mb_substr($endpoint, $separator + 1, null, 'UTF-8'), null);
    expect($this->redis)->toBeInstanceOf(Redis::class);
    $this->prefix = 'shield-test:' . bin2hex(random_bytes(16)) . ':';
    $this->store = new RedisStore(new PhpRedisClient($this->redis), $this->prefix);
});

afterEach(function (): void {
    if (!isset($this->redis, $this->prefix)) {
        return;
    }
    try {
        $cursor = null;
        do {
            $keys = $this->redis->scan($cursor, $this->prefix . '*', 100);
            if ($keys !== false && $keys !== []) {
                $this->redis->del($keys);
            }
        } while ($cursor !== 0);
    } finally {
        $this->redis->close();
    }
});

it('counts atomically and assigns a bounded TTL', function (): void {
    expect($this->store->increment('x', 10))->toBe(1);
    expect($this->store->increment('x', 10))->toBe(2);
    expect($this->store->increment('x', 10))->toBe(3);
    expect($this->redis->ttl($this->prefix . 'c:x'))->toBeBetween(1, 10);
});

it('does not extend an existing expiry', function (): void {
    $this->store->increment('x', 30);
    $this->redis->expire($this->prefix . 'c:x', 5);
    $before = $this->redis->ttl($this->prefix . 'c:x');
    expect($this->store->increment('x', 30))->toBe(2);
    expect($this->redis->ttl($this->prefix . 'c:x'))->toBeBetween(1, $before);
});

it('self-heals a counter without expiry', function (): void {
    $this->redis->set($this->prefix . 'c:x', '8');
    expect($this->store->increment('x', 10))->toBe(9);
    expect($this->redis->ttl($this->prefix . 'c:x'))->toBeBetween(1, 10);
});

it('bans unbans and replaces ban expiry', function (): void {
    $this->store->ban('192.0.2.1', 30);
    expect($this->store->isBanned('192.0.2.1'))->toBeTrue();
    expect($this->redis->ttl($this->prefix . 'ban:192.0.2.1'))->toBeBetween(1, 30);
    $this->store->ban('192.0.2.1', 3);
    expect($this->redis->ttl($this->prefix . 'ban:192.0.2.1'))->toBeBetween(1, 3);
    $this->store->unban('192.0.2.1');
    expect($this->store->isBanned('192.0.2.1'))->toBeFalse();
});

it('counts fifty sequential increments exactly', function (): void {
    for ($i = 1; $i <= 50; $i++) {
        expect($this->store->increment('x', 30))->toBe($i);
    }
});

it('isolates prefixes', function (): void {
    $other = new RedisStore(new PhpRedisClient($this->redis), $this->prefix . 'other:');
    expect($this->store->increment('x', 30))->toBe(1);
    expect($other->increment('x', 30))->toBe(1);
    $this->store->ban('192.0.2.1', 30);
    expect($other->isBanned('192.0.2.1'))->toBeFalse();
});

it('fails open over a disconnected real client', function (): void {
    $redis = new Redis();
    try {
        $redis->connect('127.0.0.1', 1, 0.1);
    } catch (Throwable) {
    }
    $store = new RedisStore(new PhpRedisClient($redis), $this->prefix);
    expect($store->increment('x', 5))->toBe(0);
    $store->ban('192.0.2.1', 5);
    expect($store->isBanned('192.0.2.1'))->toBeFalse();
    $store->unban('192.0.2.1');
});
