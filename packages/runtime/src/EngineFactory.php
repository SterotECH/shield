<?php

declare(strict_types=1);

namespace Shield\Runtime;

use Stero\Shield\Engine;
use Stero\Shield\EngineConfig;
use Stero\Shield\Events\EventSink;
use Stero\Shield\Events\JsonLinesSink;
use Stero\Shield\Mode;
use Stero\Shield\Redis\NullStore;
use Stero\Shield\Redis\PhpRedisClient;
use Stero\Shield\Redis\RedisConnector;
use Stero\Shield\Redis\RedisStore;
use Stero\Shield\Rules\SensitivePathRule;
use Stero\Shield\Store\Store;
use Throwable;

final class EngineFactory
{
    /**
     * @param null|callable(string, int, ?string, int, int, int): ?Store $connector
     *        Test seam for the bounded Redis connection and database selection.
     */
    public static function create(
        Settings $settings,
        ?Store $store = null,
        ?EventSink $sink = null,
        ?callable $connector = null,
    ): Engine {
        try {
            $store ??= $connector === null
                ? self::redisStore($settings)
                : self::injectedStore($settings, $connector);
            $sink ??= new JsonLinesSink($settings->eventsPath);
            $allowlist = array_values((new AllowlistLoader())->load($settings->allowlistFile, $settings->allowlistExtra)->entries);
            $mode = $settings->mode === Mode::Enforce ? Mode::Enforce : Mode::Detect;

            return new Engine(
                [new SensitivePathRule($settings->sensitivePaths ?? SensitivePathRule::DEFAULT_NEEDLES)],
                $store,
                $sink,
                new EngineConfig(mode: $mode, allowlist: $allowlist, blockStatus: 404),
            );
        } catch (Throwable) {
            return new Engine([], $store ?? new NullStore(), $sink ?? new JsonLinesSink($settings->eventsPath), new EngineConfig());
        }
    }

    private static function injectedStore(Settings $settings, callable $connector): Store
    {
        try {
            $store = $connector(
                $settings->redis['host'],
                $settings->redis['port'],
                $settings->redis['password'],
                100,
                100,
                $settings->redis['database'],
            );

            return $store instanceof Store ? $store : new NullStore();
        } catch (Throwable) {
            return new NullStore();
        }
    }

    private static function redisStore(Settings $settings): Store
    {
        try {
            $redis = RedisConnector::connect(
                $settings->redis['host'],
                $settings->redis['port'],
                $settings->redis['password'],
                100,
                100,
            );
            if ($redis === null || ! $redis->select($settings->redis['database'])) {
                return new NullStore();
            }

            return new RedisStore(new PhpRedisClient($redis), $settings->redis['prefix']);
        } catch (Throwable) {
            return new NullStore();
        }
    }
}
