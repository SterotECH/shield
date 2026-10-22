<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Stero\Shield\Engine;
use Stero\Shield\EngineConfig;
use Stero\Shield\Events\EventSink;
use Stero\Shield\Events\JsonLinesSink;
use Stero\Shield\Events\SecurityEvent;
use Stero\Shield\Finding;
use Stero\Shield\Http\NormalizedRequest;
use Stero\Shield\Laravel\ShieldMiddleware;
use Stero\Shield\Laravel\ShieldServiceProvider;
use Stero\Shield\Redis\NullStore;
use Stero\Shield\Rules\Phase;
use Stero\Shield\Rules\Rule;
use Stero\Shield\Rules\Severity;
use Stero\Shield\Runtime\Guard;
use Stero\Shield\Runtime\Settings;
use Stero\Shield\Store\ArrayStore;
use Stero\Shield\Store\Store;
use Symfony\Component\HttpFoundation\Response;

final class ShieldLaravelUnreadableStream
{
    /** @var resource|null */
    public $context;

    /** @return array<string, int> */
    public function url_stat(string $path): array
    {
        return ['mode' => 0100000];
    }
}

function shieldLaravelDirectory(): string
{
    $directory = sys_get_temp_dir().'/shield-laravel-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700, true);
    app()->setBasePath($directory);
    app()->useStoragePath($directory.'/storage');
    mkdir($directory.'/storage', 0700, true);
    Config::set([
        'shield.events_path' => $directory.'/storage/shield/events.jsonl',
        'shield.allowlist_file' => $directory.'/storage/shield/allowlist.txt',
        'shield.allowlist_extra' => '',
        'shield.redis.host' => '127.0.0.1',
        'shield.redis.port' => 6379,
        'shield.redis.password' => null,
        'shield.redis.database' => 5,
        'shield.redis.prefix' => 'shield:',
        'shield.timing.enabled' => false,
        'shield.timing.sample_every' => 1,
        'shield.timing.path' => $directory.'/storage/shield/timing.jsonl',
        'shield.skip_prefixes' => ['/up', '/build/', '/storage/'],
        'shield.auto_register_middleware' => true,
    ]);
    app()->register(ShieldServiceProvider::class, true);

    return $directory;
}

function shieldLaravelRequest(string $path = '/.env', string $ip = '203.0.113.9'): Request
{
    return Request::create('https://School.Example'.$path, 'GET', server: ['REMOTE_ADDR' => $ip]);
}

function shieldLaravelRun(Request $request, ?Response $response = null): Response
{
    $response ??= new Response('route response', 202, ['X-App' => 'kept']);
    $middleware = app(ShieldMiddleware::class);
    $response = $middleware->handle($request, static fn (): Response => $response);
    (new ShieldMiddleware)->terminate($request, $response);

    return $response;
}

/** @return list<array<string, mixed>> */
function shieldLaravelEvents(string $path): array
{
    if (! is_file($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    assert(is_array($lines));
    $events = [];
    foreach ($lines as $line) {
        $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        assert(is_array($event));
        /** @var array<string, mixed> $event */
        $events[] = $event;
    }

    return $events;
}

function shieldLaravelDeferredEngine(string $eventsPath, Store $store): void
{
    $rule = new class implements Rule
    {
        public function id(): string
        {
            return 'test-deferred';
        }

        public function phase(): Phase
        {
            return Phase::Deferred;
        }

        public function inspect(NormalizedRequest $request): ?Finding
        {
            return new Finding('test-deferred', Severity::Low, 'Deferred fixture', '/normal', false);
        }
    };
    $settings = Settings::fromArray(Config::get('shield'));
    $engine = new Engine([$rule], $store, new JsonLinesSink($eventsPath), new EngineConfig);
    app()->instance(Guard::class, new Guard($settings, $engine));
}

beforeEach(function (): void {
    $this->directory = shieldLaravelDirectory();
    $this->store = new ArrayStore;
    app()->instance(Store::class, $this->store);
    app()->instance(EventSink::class, new JsonLinesSink($this->directory.'/storage/shield/events.jsonl'));
});

afterEach(function (): void {
    if (isset($this->directory) && is_dir($this->directory)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }
});

it('is listed for Laravel auto-discovery in the root package manifest', function (): void {
    $manifest = json_decode(file_get_contents(dirname(__DIR__, 3).'/composer.json') ?: '', true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['extra']['laravel']['providers'])->toContain(ShieldServiceProvider::class)
        ->and($manifest['autoload']['psr-4']['Stero\\Shield\\Laravel\\'])->toBe('packages/laravel/src/')
        ->and(app()->getProvider(ShieldServiceProvider::class))->toBeInstanceOf(ShieldServiceProvider::class);
});

it('does not put middleware in the global stack or build a guard when disabled', function (): void {
    $builds = 0;
    app()->bind(Guard::class, function () use (&$builds): never {
        $builds++;
        throw new RuntimeException('Guard must remain lazy');
    });
    $kernel = app(HttpKernelContract::class);
    $property = new ReflectionProperty($kernel, 'middleware');
    $stack = $property->getValue($kernel);
    $response = shieldLaravelRun(shieldLaravelRequest(), new Response('unchanged', 207));
    expect($stack)->not->toContain(ShieldMiddleware::class)
        ->and($builds)->toBe(0)
        ->and($response->getStatusCode())->toBe(207)
        ->and(glob($this->directory.'/storage/*'))->toBe([]);
});

it('does not retry or swallow a downstream application exception', function (): void {
    $calls = 0;
    expect(function () use (&$calls): void {
        (new ShieldMiddleware)->handle(shieldLaravelRequest('/normal'), static function () use (&$calls): Response {
            $calls++;
            throw new DomainException('application failure');
        });
    })->toThrow(DomainException::class, 'application failure')->and($calls)->toBe(1);
});

it('prepends middleware only when enabled and auto-registration is on', function (): void {
    Config::set('shield.enabled', true);
    app()->register(ShieldServiceProvider::class, true);
    $kernel = app(HttpKernelContract::class);
    $property = new ReflectionProperty($kernel, 'middleware');
    $stack = $property->getValue($kernel);
    expect($stack[0])->toBe(ShieldMiddleware::class);

    Config::set('shield.auto_register_middleware', false);
    app()->register(ShieldServiceProvider::class, true);
    expect($property->getValue($kernel))->toBe($stack);
});

it('leaves a clean middleware stack alone when auto-registration is disabled', function (): void {
    Config::set(['shield.enabled' => true, 'shield.auto_register_middleware' => false]);
    app()->register(ShieldServiceProvider::class, true);
    $kernel = app(HttpKernelContract::class);
    expect((new ReflectionProperty($kernel, 'middleware'))->getValue($kernel))->not->toContain(ShieldMiddleware::class);
});

it('records a detect finding and returns the application response unchanged', function (): void {
    Config::set(['shield.enabled' => true, 'shield.mode' => 'detect']);
    app()->register(ShieldServiceProvider::class, true);
    $response = shieldLaravelRun(shieldLaravelRequest());
    $events = shieldLaravelEvents($this->directory.'/storage/shield/events.jsonl');
    expect($response->getStatusCode())->toBe(202)
        ->and($response->getContent())->toBe('route response')
        ->and($events)->toHaveCount(1)
        ->and($events[0])->toMatchArray(['rule' => 'sensitive-path', 'would_block' => true, 'blocked' => false, 'tenant' => 'school.example']);
});

it('normalises the host and never forwards the submitted request body', function (): void {
    Config::set('shield.enabled', true);
    app()->register(ShieldServiceProvider::class, true);
    $request = Request::create('https://School.Example/normal', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9'], content: '/.env private-value');
    shieldLaravelRun($request);
    $normalised = $request->attributes->get(ShieldMiddleware::REQUEST_ATTRIBUTE);
    expect($normalised)->toBeInstanceOf(NormalizedRequest::class)
        ->and($normalised->body)->toBe('')
        ->and($normalised->tenantId)->toBe('school.example')
        ->and(@file_get_contents($this->directory.'/storage/shield/events.jsonl') ?: '')->not->toContain('private-value');
});

it('records no event for a normal path', function (): void {
    Config::set('shield.enabled', true);
    app()->register(ShieldServiceProvider::class, true);
    expect(shieldLaravelRun(shieldLaravelRequest('/normal'))->getStatusCode())->toBe(202)
        ->and(shieldLaravelEvents($this->directory.'/storage/shield/events.jsonl'))->toBeEmpty();
});

it('runs through the Laravel HTTP kernel and preserves the route response in detect mode', function (): void {
    Config::set(['shield.enabled' => true, 'shield.mode' => 'detect']);
    app()->register(ShieldServiceProvider::class, true);
    Route::get('/.env', static fn (): Response => new Response('route body', 218, ['X-Route' => 'kept']));
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->get('https://school.example/.env')
        ->assertStatus(218)->assertContent('route body')->assertHeader('X-Route', 'kept');
    $events = shieldLaravelEvents($this->directory.'/storage/shield/events.jsonl');
    expect($events)->toHaveCount(1)
        ->and($events[0])->toMatchArray(['rule' => 'sensitive-path', 'tenant' => 'school.example', 'would_block' => true, 'blocked' => false]);
});

it('enforces 404 responses, no-store headers, and bans repeat probes', function (): void {
    Config::set(['shield.enabled' => true, 'shield.mode' => 'enforce']);
    app()->register(ShieldServiceProvider::class, true);
    $response = shieldLaravelRun(shieldLaravelRequest());
    expect($response->getStatusCode())->toBe(404)
        ->and($response->getContent())->toBe('Not Found')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(app(Store::class))->toBe($this->store)
        ->and($this->store->isBanned('203.0.113.9'))->toBeTrue()
        ->and(shieldLaravelRun(shieldLaravelRequest('/normal'))->getStatusCode())->toBe(404);
});

it('allows allowlisted addresses from file and extra config while recording findings', function (string $source, string $entry, string $ip): void {
    Config::set(['shield.enabled' => true, 'shield.mode' => 'enforce']);
    app()->register(ShieldServiceProvider::class, true);
    if ($source === 'file') {
        mkdir($this->directory.'/storage/shield', 0770, true);
        file_put_contents($this->directory.'/storage/shield/allowlist.txt', $entry."\n");
    } else {
        Config::set('shield.allowlist_extra', $entry);
    }
    $response = shieldLaravelRun(shieldLaravelRequest('/.env', $ip));
    $events = shieldLaravelEvents($this->directory.'/storage/shield/events.jsonl');
    expect($response->getStatusCode())->toBe(202)
        ->and($this->store->isBanned($ip))->toBeFalse()
        ->and($events[0])->toMatchArray(['would_block' => true, 'blocked' => false]);
})->with([
    'allowlist file' => ['file', '203.0.113.0/24', '203.0.113.9'],
    'extra config' => ['extra', '198.51.100.1', '198.51.100.1'],
]);

it('does not trust forwarded client IP headers', function (): void {
    Config::set('shield.enabled', true);
    app()->register(ShieldServiceProvider::class, true);
    $request = shieldLaravelRequest();
    $request->server->set('HTTP_X_FORWARDED_FOR', '198.51.100.1');
    $request->server->set('HTTP_CF_CONNECTING_IP', '198.51.100.2');
    shieldLaravelRun($request);
    expect(shieldLaravelEvents($this->directory.'/storage/shield/events.jsonl')[0]['ip'])->toBe('203.0.113.9');
});

it('fails open for throwing store, sink and guard bindings', function (string $failure): void {
    Config::set('shield.enabled', true);
    app()->register(ShieldServiceProvider::class, true);
    if ($failure === 'store') {
        app()->instance(Store::class, new class implements Store
        {
            public function increment(string $key, int $windowSeconds): int
            {
                throw new RuntimeException('store failure');
            }

            public function ban(string $ip, int $seconds): void
            {
                throw new RuntimeException('store failure');
            }

            public function isBanned(string $ip): bool
            {
                throw new RuntimeException('store failure');
            }

            public function unban(string $ip): void
            {
                throw new RuntimeException('store failure');
            }
        });
    } elseif ($failure === 'sink') {
        app()->instance(EventSink::class, new class implements EventSink
        {
            public function record(SecurityEvent $event): void
            {
                throw new RuntimeException('sink failure');
            }
        });
    } else {
        app()->bind(Guard::class, static function (): never {
            throw new RuntimeException('guard failure');
        });
    }
    $response = shieldLaravelRun(shieldLaravelRequest(), new Response('app response', 209));
    expect($response->getStatusCode())->toBe(209)->and($response->getContent())->toBe('app response');
})->with(['store', 'sink', 'guard']);

it('fails open when a Store or EventSink factory throws during Guard construction', function (string $service): void {
    Config::set('shield.enabled', true);
    app()->register(ShieldServiceProvider::class, true);
    app()->bind($service, static function (): never {
        throw new RuntimeException('binding failure');
    });
    $response = new Response('unchanged', 217);
    expect(shieldLaravelRun(shieldLaravelRequest(), $response))->toBe($response);
})->with([Store::class, EventSink::class]);

it('prints runtime warnings in the status command', function (): void {
    Config::set('shield.redis.database', 0);
    $this->artisan('shield:status')
        ->expectsOutputToContain('cache flush can erase bans')->assertExitCode(0);
});

it('skips configured prefixes without recording', function (string $path): void {
    Config::set('shield.enabled', true);
    app()->register(ShieldServiceProvider::class, true);
    expect(shieldLaravelRun(shieldLaravelRequest($path))->getStatusCode())->toBe(202)
        ->and(shieldLaravelEvents($this->directory.'/storage/shield/events.jsonl'))->toBeEmpty();
})->with(['/up', '/build/assets/app.js', '/storage/image.png']);

it('uses attributes so a fresh middleware instance can record the deferred user', function (string $user): void {
    Config::set('shield.enabled', true);
    app()->register(ShieldServiceProvider::class, true);
    shieldLaravelDeferredEngine($this->directory.'/storage/shield/events.jsonl', $this->store);
    $request = shieldLaravelRequest('/normal');
    $request->setUserResolver(static function () use ($user): ?Authenticatable {
        if ($user === 'throws') {
            throw new RuntimeException('auth failure');
        }
        if ($user === 'guest') {
            return null;
        }

        return new GenericUser(['id' => 42]);
    });
    $response = (new ShieldMiddleware)->handle($request, static fn (): Response => new Response('ok'));
    (new ShieldMiddleware)->terminate($request, $response);
    $events = shieldLaravelEvents($this->directory.'/storage/shield/events.jsonl');
    expect($events)->toHaveCount(1)->and($events[0]['user'])->toBe($user === 'member' ? '42' : null);
})->with(['member', 'guest', 'throws']);

it('registers the three Shield commands', function (): void {
    expect(Artisan::all())->toHaveKeys(['shield:install', 'shield:status', 'shield:timing']);
});

it('install publishes config and creates its folder without editing env', function (): void {
    $environment = $this->directory.'/.env';
    file_put_contents($environment, "SHIELD_ENABLED=false\nAPP_KEY=unchanged\n");
    $this->app->useEnvironmentPath($this->directory);
    $this->artisan('shield:install')->assertExitCode(0);
    expect(is_dir($this->directory.'/storage/shield'))->toBeTrue()
        ->and(fileperms($this->directory.'/storage/shield') & 0777)->toBe(0775)
        ->and(file_get_contents($environment))->toBe("SHIELD_ENABLED=false\nAPP_KEY=unchanged\n")
        ->and(is_file($this->directory.'/config/shield.php'))->toBeTrue();
});

it('status command reports enabled health, NullStore degradation and disabled health', function (): void {
    Config::set('shield.enabled', false);
    $this->artisan('shield:status')->assertExitCode(0);

    app()->forgetInstance(Settings::class);
    Config::set('shield.enabled', true);
    app()->instance('shield.redis.connector', static fn (): object => new class
    {
        public function select(int $database): bool
        {
            return true;
        }

        public function ping(): bool
        {
            return true;
        }
    });
    $this->artisan('shield:status')->assertExitCode(0);
    app()->instance(Store::class, new NullStore);
    app()->forgetInstance(Settings::class);
    $this->artisan('shield:status')->assertExitCode(1);
});

it('timing command reports samples, empty files and missing files', function (): void {
    $path = $this->directory.'/storage/shield/timing.jsonl';
    mkdir(dirname($path), 0770, true);
    file_put_contents($path, json_encode(['ts' => '2026-10-21T00:00:00Z', 'inline_us' => 1000, 'deferred_us' => 500], JSON_THROW_ON_ERROR)."\n");
    Config::set('shield.timing.path', $path);
    $this->artisan('shield:timing')->expectsOutputToContain('Samples: 1')->expectsOutputToContain('PASS')->assertExitCode(0);

    file_put_contents($path, json_encode(['ts' => '2026-10-21T00:00:00Z', 'inline_us' => 6000, 'deferred_us' => 500], JSON_THROW_ON_ERROR)."\n");
    $this->artisan('shield:timing')->expectsOutputToContain('FAIL')->assertExitCode(1);

    file_put_contents($path, '');
    $this->artisan('shield:timing')->expectsOutputToContain('No timing samples')->assertExitCode(0);

    unlink($path);
    $this->artisan('shield:timing')->expectsOutputToContain('Timing file does not exist')->assertExitCode(0);
});

it('returns failure when the timing file exists but cannot be read', function (): void {
    stream_wrapper_register('shield-laravel-denied', ShieldLaravelUnreadableStream::class);
    try {
        Config::set('shield.timing.path', 'shield-laravel-denied://timing.jsonl');
        expect(is_file('shield-laravel-denied://timing.jsonl'))->toBeTrue()
            ->and(is_readable('shield-laravel-denied://timing.jsonl'))->toBeFalse();
        $this->artisan('shield:timing')->expectsOutputToContain('Timing file is unreadable')->assertExitCode(1);
    } finally {
        stream_wrapper_unregister('shield-laravel-denied');
    }
});

it('loads runtime settings with the Redis database default and environment override', function (): void {
    $environment = getenv('SHIELD_REDIS_DB');
    $envValue = $_ENV['SHIELD_REDIS_DB'] ?? null;
    $serverValue = $_SERVER['SHIELD_REDIS_DB'] ?? null;
    try {
        putenv('SHIELD_REDIS_DB');
        unset($_ENV['SHIELD_REDIS_DB'], $_SERVER['SHIELD_REDIS_DB']);
        $defaults = require dirname(__DIR__).'/config/shield.php';
        expect($defaults['redis']['database'])->toBe(5);

        putenv('SHIELD_REDIS_DB=7');
        Config::set('shield', require dirname(__DIR__).'/config/shield.php');
        expect(Config::get('shield.redis.database'))->toBe(7);
    } finally {
        $environment === false ? putenv('SHIELD_REDIS_DB') : putenv('SHIELD_REDIS_DB='.$environment);
        if ($envValue === null) {
            unset($_ENV['SHIELD_REDIS_DB']);
        } else {
            $_ENV['SHIELD_REDIS_DB'] = $envValue;
        }
        if ($serverValue === null) {
            unset($_SERVER['SHIELD_REDIS_DB']);
        } else {
            $_SERVER['SHIELD_REDIS_DB'] = $serverValue;
        }
    }
});

it('maps every documented environment flag and uses the shield storage defaults', function (): void {
    $names = [
        'SHIELD_ENABLED', 'SHIELD_MODE', 'SHIELD_ALLOWLIST_FILE', 'SHIELD_ALLOWLIST_EXTRA',
        'SHIELD_REDIS_HOST', 'SHIELD_REDIS_PORT', 'SHIELD_REDIS_PASSWORD', 'SHIELD_REDIS_DB', 'SHIELD_TIMING',
    ];
    $original = [];
    foreach ($names as $name) {
        $original[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }
    try {
        putenv('SHIELD_ENABLED=true');
        putenv('SHIELD_MODE=enforce');
        putenv('SHIELD_ALLOWLIST_FILE=/tmp/shield-allowlist.txt');
        putenv('SHIELD_ALLOWLIST_EXTRA=192.0.2.1');
        putenv('SHIELD_REDIS_HOST=redis.internal');
        putenv('SHIELD_REDIS_PORT=6380');
        putenv('SHIELD_REDIS_PASSWORD=secret');
        putenv('SHIELD_REDIS_DB=9');
        putenv('SHIELD_TIMING=true');
        $config = require dirname(__DIR__).'/config/shield.php';
        expect($config)->toMatchArray([
            'enabled' => true,
            'mode' => 'enforce',
            'allowlist_file' => '/tmp/shield-allowlist.txt',
            'allowlist_extra' => '192.0.2.1',
            'redis' => ['host' => 'redis.internal', 'port' => 6380, 'password' => 'secret', 'database' => 9, 'prefix' => 'shield:'],
            'timing' => ['enabled' => true, 'sample_every' => 20, 'path' => storage_path('shield/timing.jsonl')],
            'events_path' => storage_path('shield/events.jsonl'),
            'auto_register_middleware' => true,
        ]);
    } finally {
        foreach ($original as $name => [$environment, $envValue, $serverValue]) {
            $environment === false ? putenv($name) : putenv($name.'='.$environment);
            if ($envValue === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $envValue;
            }
            if ($serverValue === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $serverValue;
            }
        }
    }
});

it('passes the configured Redis database to its connector', function (): void {
    $seen = [];
    app()->forgetInstance(Settings::class);
    app()->forgetInstance(Store::class);
    app()->instance('shield.redis.connector', static function (...$arguments) use (&$seen): null {
        $seen[] = $arguments[5] ?? null;

        return null;
    });
    expect(app(Store::class))->toBeInstanceOf(NullStore::class)->and($seen)->toBe([5]);

    Config::set('shield.redis.database', 7);
    app()->forgetInstance(Settings::class);
    app()->forgetInstance(Store::class);
    expect(app(Store::class))->toBeInstanceOf(NullStore::class)->and($seen)->toBe([5, 7]);
});

it('registers Store and EventSink interfaces for container overrides', function (): void {
    expect(app(Store::class))->toBe($this->store)
        ->and(app(EventSink::class))->toBeInstanceOf(JsonLinesSink::class);
});
