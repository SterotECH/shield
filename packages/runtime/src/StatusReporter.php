<?php

declare(strict_types=1);

namespace Stero\Shield\Runtime;

use Stero\Shield\Mode;
use Stero\Shield\Redis\NullStore;
use Stero\Shield\Redis\RedisConnector;
use Stero\Shield\Redis\RedisStore;
use Throwable;

final class StatusReporter
{
    /** @param null|callable(string, int, ?string, int, int, int): ?object $connector */
    public static function report(Settings $settings, ?callable $connector = null): StatusReport
    {
        $fileEntries = new AllowlistLoader()->load($settings->allowlistFile, []);
        $extraEntries = new AllowlistLoader()->load(null, $settings->allowlistExtra);
        $connection = null;
        $connected = false;
        $ping = false;
        try {
            if ($connector === null) {
                $connection = RedisConnector::connect(
                    $settings->redis['host'],
                    $settings->redis['port'],
                    $settings->redis['password'],
                    100,
                    100,
                );
            } else {
                $connection = $connector(
                    $settings->redis['host'],
                    $settings->redis['port'],
                    $settings->redis['password'],
                    100,
                    100,
                    $settings->redis['database'],
                );
            }
            if (is_object($connection) && method_exists($connection, 'select')) {
                $connected = $connection->select($settings->redis['database']) === true;
                if ($connected && method_exists($connection, 'ping')) {
                    $response = $connection->ping();
                    $ping = $response === true || $response === '+PONG' || $response === 'PONG';
                }
            }
        } catch (Throwable) {
            $connected = false;
            $ping = false;
        }
        $writable = self::eventsWritable($settings->eventsPath);
        $warnings = [];
        if ($settings->redis['database'] === 0) {
            $warnings[] = 'Redis database 0 shares a database with the default; a cache flush can erase bans';
        }
        $healthy = ! $settings->enabled || ($connected && $ping && $writable);

        return new StatusReport(
            $settings->enabled,
            $settings->mode === Mode::Enforce ? Mode::Enforce : Mode::Detect,
            $settings->eventsPath,
            $writable,
            is_file($settings->allowlistFile),
            count($fileEntries->entries),
            count($extraEntries->entries),
            $settings->redis['host'],
            $settings->redis['database'],
            $connected,
            $ping,
            $connected ? RedisStore::class : NullStore::class,
            $warnings,
            $healthy,
        );
    }

    private static function eventsWritable(string $path): bool
    {
        if (is_file($path)) {
            return is_writable($path);
        }
        $directory = dirname($path);
        while (! is_dir($directory)) {
            $parent = dirname($directory);
            if ($parent === $directory) {
                return false;
            }
            $directory = $parent;
        }

        return is_writable($directory);
    }
}
