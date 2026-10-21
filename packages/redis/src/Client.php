<?php

declare(strict_types=1);

namespace Stero\Shield\Redis;

/** Internal Redis operations used by the store. */
interface Client
{
    /** @param list<string> $keys
     * @param list<int|string> $args
     */
    public function eval(string $script, array $keys, array $args): mixed;

    public function set(string $key, string $value, int $ttlSeconds): void;

    public function exists(string $key): bool;

    public function del(string $key): void;

    public function ttl(string $key): int;
}
