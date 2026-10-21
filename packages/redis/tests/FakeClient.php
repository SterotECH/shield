<?php

declare(strict_types=1);

namespace Stero\Shield\Redis\Tests;

use Stero\Shield\Redis\Client;

final class FakeClient implements Client
{
    public bool $throw = false;
    public int $calls = 0;
    public array $keys = [];
    public array $args = [];

    private function record(array $keys, array $args = []): void
    {
        $this->calls++;
        $this->keys = $keys;
        $this->args = $args;
        if ($this->throw) {
            throw new \RuntimeException('Unavailable');
        }
    }

    public function eval(string $script, array $keys, array $args): mixed
    {
        $this->record($keys, $args);
        return 7;
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        $this->record([$key], [$value, $ttlSeconds]);
    }

    public function exists(string $key): bool
    {
        $this->record([$key]);
        return true;
    }

    public function del(string $key): void
    {
        $this->record([$key]);
    }

    public function ttl(string $key): int
    {
        $this->record([$key]);
        return 10;
    }
}
