<?php

declare(strict_types=1);

namespace Shield\Laravel\Commands;

use Illuminate\Console\Command;
use Shield\Runtime\Settings;
use Shield\Runtime\StatusReporter;
use Stero\Shield\Redis\NullStore;
use Stero\Shield\Store\Store;

/** Shows runtime health without exposing request data. */
final class StatusCommand extends Command
{
    protected $signature = 'shield:status';

    protected $description = 'Show Shield configuration and storage health';

    public function handle(Settings $settings): int
    {
        $connector = app('shield.redis.connector');
        $reporterConnector = static function (
            string $host,
            int $port,
            ?string $password,
            int $connectTimeoutMs,
            int $readTimeoutMs,
            int $database,
        ) use ($connector): ?object {
            if (! is_callable($connector)) {
                return null;
            }
            $connection = $connector($host, $port, $password, $connectTimeoutMs, $readTimeoutMs, $database);

            return is_object($connection) ? $connection : null;
        };
        $report = StatusReporter::report($settings, $reporterConnector);
        $storeValue = $settings->enabled ? app(Store::class) : null;
        $store = $storeValue instanceof Store ? $storeValue : null;
        $healthy = $report->healthy && (! $settings->enabled || ($store instanceof Store && ! $store instanceof NullStore));

        $this->line('Enabled: '.($report->enabled ? 'yes' : 'no'));
        $this->line('Mode: '.$report->mode->value);
        $this->line('Events path: '.$report->eventsPath);
        $this->line('Events folder writable: '.($report->eventsWritable ? 'yes' : 'no'));
        $this->line('Allowlist file exists: '.($report->allowlistFileExists ? 'yes' : 'no'));
        $this->line('Allowlist file entries: '.$report->allowlistFileCount);
        $this->line('Allowlist extra entries: '.$report->allowlistExtraCount);
        $this->line('Redis host: '.$report->redisHost);
        $this->line('Redis database: '.$report->redisDatabase);
        $this->line('Redis connection: '.($report->redisConnected ? 'yes' : 'no'));
        $this->line('Redis ping: '.($report->redisPing ? 'yes' : 'no'));
        $this->line('Store: '.($store instanceof Store ? $store::class : 'not initialised (disabled)'));
        foreach ($report->warnings as $warning) {
            $this->warn($warning);
        }
        if ($settings->enabled && $store instanceof NullStore) {
            $this->warn('Shield is enabled but the Redis store is unavailable.');
        }
        if ($settings->enabled && ! $report->eventsWritable) {
            $this->warn('Shield is enabled but its events folder is not writable.');
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
