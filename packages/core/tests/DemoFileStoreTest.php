<?php

declare(strict_types=1);

use Shield\Demo\DemoFileStore;

beforeEach(function (): void {
    $this->storePath = tempnam(sys_get_temp_dir(), 'shield-demo-');
});

afterEach(function (): void {
    unlink($this->storePath);
});

it('persists bans across instances and supports unban', function (): void {
    $first = new DemoFileStore($this->storePath);
    $first->ban('203.0.113.9', 60);
    $second = new DemoFileStore($this->storePath);
    expect($second->isBanned('203.0.113.9'))->toBeTrue();
    $second->unban('203.0.113.9');
    expect($first->isBanned('203.0.113.9'))->toBeFalse();
});

it('expires bans exactly at the boundary', function (): void {
    $now = 100;
    $store = new DemoFileStore($this->storePath, function () use (&$now): int {
        return $now;
    });
    $store->ban('203.0.113.9', 10);
    $now = 109;
    expect($store->isBanned('203.0.113.9'))->toBeTrue();
    $now = 110;
    expect($store->isBanned('203.0.113.9'))->toBeFalse();
});

it('persists fixed window counters without extending expiry', function (): void {
    $now = 100;
    $clock = function () use (&$now): int {
        return $now;
    };
    $store = new DemoFileStore($this->storePath, $clock);
    expect($store->increment('login', 10))->toBe(1);
    $now = 109;
    expect((new DemoFileStore($this->storePath, $clock))->increment('login', 10))->toBe(2);
    $now = 110;
    expect($store->increment('login', 10))->toBe(1);
});

it('clears bans and counters', function (): void {
    $store = new DemoFileStore($this->storePath);
    $store->ban('203.0.113.9', 60);
    $store->increment('login', 60);
    $store->clear();
    expect((new DemoFileStore($this->storePath))->isBanned('203.0.113.9'))->toBeFalse()
        ->and($store->increment('login', 60))->toBe(1);
});

it('fails open on an unwritable path', function (): void {
    $store = new DemoFileStore($this->storePath . '/missing/store.json');
    $store->ban('203.0.113.9', 60);
    $store->unban('203.0.113.9');
    $store->clear();
    expect($store->increment('login', 60))->toBe(0)
        ->and($store->isBanned('203.0.113.9'))->toBeFalse();
});
