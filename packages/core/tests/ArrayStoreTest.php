<?php

declare(strict_types=1);

use Stero\Shield\Store\ArrayStore;

it('counts within a fixed window and resets after expiry', function (): void {
    $now = 100;
    $store = new ArrayStore(function () use (&$now): int {
        return $now;
    });

    expect($store->increment('a', 10))->toBe(1)
        ->and($store->increment('a', 10))->toBe(2)
        ->and($store->increment('b', 10))->toBe(1);
    $now = 109;
    expect($store->increment('a', 10))->toBe(3);
    $now = 110;
    expect($store->increment('a', 10))->toBe(1);
});

it('expires and removes bans without affecting other clients', function (): void {
    $now = 100;
    $store = new ArrayStore(function () use (&$now): int {
        return $now;
    });

    expect($store->isBanned('a'))->toBeFalse();
    $store->ban('a', 10);
    $store->ban('b', 20);
    $now = 109;
    expect($store->isBanned('a'))->toBeTrue();
    $now = 110;
    expect($store->isBanned('a'))->toBeFalse()
        ->and($store->isBanned('b'))->toBeTrue();
    $store->unban('b');
    $store->unban('missing');
    expect($store->isBanned('b'))->toBeFalse();
});

it('uses the system clock by default', function (): void {
    $store = new ArrayStore();
    $store->ban('a', 10);

    expect($store->increment('a', 10))->toBe(1)
        ->and($store->isBanned('a'))->toBeTrue();
});

it('fails open when its clock throws', function (): void {
    $store = new ArrayStore(fn(): int => throw new RuntimeException('clock failed'));

    expect($store->increment('a', 10))->toBe(0)
        ->and($store->isBanned('a'))->toBeFalse();
    expect(fn() => $store->ban('a', 10))->not->toThrow(Throwable::class);
    expect(fn() => $store->unban('a'))->not->toThrow(Throwable::class);
});

it('replaces an earlier ban expiry', function (): void {
    $now = 100;
    $store = new ArrayStore(function () use (&$now): int {
        return $now;
    });

    $store->ban('a', 100);
    $store->ban('a', 5);
    $now = 106;
    expect($store->isBanned('a'))->toBeFalse();
});
