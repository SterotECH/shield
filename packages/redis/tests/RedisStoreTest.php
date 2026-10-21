<?php

declare(strict_types=1);

use Stero\Shield\Redis\NullStore;
use Stero\Shield\Redis\RedisConnector;
use Stero\Shield\Redis\RedisStore;
use Stero\Shield\Redis\Tests\FakeClient;

it('uses exact key names and clamps durations', function (): void {
    $client = new FakeClient();
    $store = new RedisStore($client);
    expect($store->increment('login', 0))->toBe(7);
    expect($client->keys)->toBe(['shield:c:login'])->and($client->args)->toBe([1]);
    $store->ban('192.0.2.1', -5);
    expect($client->keys)->toBe(['shield:ban:192.0.2.1'])->and($client->args)->toBe(['1', 1]);
    expect($store->isBanned('192.0.2.1'))->toBeTrue();
    expect($client->keys)->toBe(['shield:ban:192.0.2.1']);
    $store->unban('192.0.2.1');
    expect($client->keys)->toBe(['shield:ban:192.0.2.1']);
    (new RedisStore($client, 'custom:'))->increment('x', 9);
    expect($client->keys)->toBe(['custom:c:x'])->and($client->args)->toBe([9]);
});

it('fails open for each method and short-circuits all later calls', function (string $method): void {
    $client = new FakeClient();
    $client->throw = true;
    $store = new RedisStore($client);
    $result = match ($method) {
        'increment' => $store->increment('x', 10),
        'ban' => $store->ban('192.0.2.1', 10),
        'isBanned' => $store->isBanned('192.0.2.1'),
        'unban' => $store->unban('192.0.2.1'),
    };
    expect($result)->toBe(match ($method) {
        'increment' => 0, 'isBanned' => false, default => null
    });
    expect($store->increment('later', 2))->toBe(0);
    expect($store->isBanned('192.0.2.2'))->toBeFalse();
    $store->ban('192.0.2.2', 2);
    $store->unban('192.0.2.2');
    expect($client->calls)->toBe(1);
    (new RedisStore($client))->increment('retry', 2);
    expect($client->calls)->toBe(2);
})->with(['increment', 'ban', 'isBanned', 'unban']);

it('provides an inert null store', function (): void {
    $store = new NullStore();
    expect($store->increment('x', 5))->toBe(0);
    $store->ban('192.0.2.1', 5);
    expect($store->isBanned('192.0.2.1'))->toBeFalse();
    $store->unban('192.0.2.1');
    expect($store->isBanned('192.0.2.1'))->toBeFalse();
});

it('returns null quickly for a closed port', function (): void {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    expect($socket)->not->toBeFalse();
    $address = stream_socket_get_name($socket, false);
    $port = (int) mb_substr($address, mb_strrpos($address, ':', 0, 'UTF-8') + 1, null, 'UTF-8');
    fclose($socket);
    $start = hrtime(true);
    expect(RedisConnector::connect('127.0.0.1', $port, null))->toBeNull();
    expect((hrtime(true) - $start) / 1e6)->toBeLessThan(500);
});
