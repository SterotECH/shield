<?php

declare(strict_types=1);

namespace Shield\Laravel;

use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Redis;
use Shield\Laravel\Commands\InstallCommand;
use Shield\Laravel\Commands\StatusCommand;
use Shield\Laravel\Commands\TimingCommand;
use Shield\Runtime\EngineFactory;
use Shield\Runtime\Guard;
use Shield\Runtime\Settings;
use Shield\Runtime\TimingRecorder;
use Stero\Shield\Events\EventSink;
use Stero\Shield\Events\JsonLinesSink;
use Stero\Shield\Redis\NullStore;
use Stero\Shield\Redis\PhpRedisClient;
use Stero\Shield\Redis\RedisConnector;
use Stero\Shield\Redis\RedisStore;
use Stero\Shield\Store\Store;
use Throwable;

/** Laravel wiring for the framework-neutral Shield runtime. */
final class ShieldServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/shield.php', 'shield');

        $this->app->singleton(Settings::class, static function (): Settings {
            $config = Config::get('shield', []);
            $settingsConfig = [];
            if (is_array($config)) {
                foreach ($config as $key => $value) {
                    if (is_string($key)) {
                        $settingsConfig[$key] = $value;
                    }
                }
            }

            return Settings::fromArray($settingsConfig);
        });
        $this->app->singleton('shield.redis.connector', static fn (): callable => static fn (
            string $host,
            int $port,
            ?string $password,
            int $connectTimeoutMs,
            int $readTimeoutMs,
            int $database,
        ) => RedisConnector::connect($host, $port, $password, $connectTimeoutMs, $readTimeoutMs));
        if (! $this->app->bound(Store::class)) {
            $this->app->singleton(Store::class, function (Application $app): Store {
                $settings = $app->make(Settings::class);
                if (! $settings instanceof Settings) {
                    return new NullStore;
                }
                try {
                    $connector = $app->make('shield.redis.connector');
                    if (! is_callable($connector)) {
                        return new NullStore;
                    }
                    $redis = $connector(
                        $settings->redis['host'],
                        $settings->redis['port'],
                        $settings->redis['password'],
                        100,
                        100,
                        $settings->redis['database'],
                    );
                    if (! $redis instanceof Redis || ! $redis->select($settings->redis['database'])) {
                        return new NullStore;
                    }

                    return new RedisStore(new PhpRedisClient($redis), $settings->redis['prefix']);
                } catch (Throwable) {
                    return new NullStore;
                }
            });
        }
        if (! $this->app->bound(EventSink::class)) {
            $this->app->singleton(EventSink::class, static function (Application $app): EventSink {
                $settings = $app->make(Settings::class);

                return new JsonLinesSink($settings instanceof Settings ? $settings->eventsPath : 'storage/shield/events.jsonl');
            });
        }
        $this->app->singleton(Guard::class, static function (Application $app): Guard {
            $settingsValue = $app->make(Settings::class);
            $settings = $settingsValue instanceof Settings ? $settingsValue : Settings::fromArray([]);
            $storeValue = $app->make(Store::class);
            $sinkValue = $app->make(EventSink::class);
            $store = $storeValue instanceof Store ? $storeValue : null;
            $sink = $sinkValue instanceof EventSink ? $sinkValue : null;
            $timing = $settings->timing['enabled']
                ? new TimingRecorder($settings->timing['path'], $settings->timing['sample_every'])
                : null;

            return new Guard(
                $settings,
                EngineFactory::create($settings, $store, $sink),
                $timing,
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/shield.php' => config_path('shield.php'),
        ], 'shield-config');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class, StatusCommand::class, TimingCommand::class]);
        }

        if (! Config::boolean('shield.enabled', false)
            || ! Config::boolean('shield.auto_register_middleware', true)) {
            return;
        }

        $this->callAfterResolving(HttpKernelContract::class, static function (Kernel $kernel): void {
            $kernel->prependMiddleware(ShieldMiddleware::class);
        });
    }
}
