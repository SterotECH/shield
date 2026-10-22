<?php

declare(strict_types=1);

use Stero\Shield\Decision;
use Stero\Shield\Engine;
use Stero\Shield\EngineConfig;
use Stero\Shield\Events\EventSink;
use Stero\Shield\Events\InMemorySink;
use Stero\Shield\Finding;
use Stero\Shield\Http\NormalizedRequest;
use Stero\Shield\Mode;
use Stero\Shield\Redis\NullStore;
use Stero\Shield\Redis\RedisStore;
use Stero\Shield\Rules\Phase;
use Stero\Shield\Rules\Rule;
use Stero\Shield\Rules\Severity;
use Stero\Shield\Runtime\AllowlistLoader;
use Stero\Shield\Runtime\EngineFactory;
use Stero\Shield\Runtime\Guard;
use Stero\Shield\Runtime\Inspection;
use Stero\Shield\Runtime\Settings;
use Stero\Shield\Runtime\StatusReporter;
use Stero\Shield\Runtime\TimingRecorder;
use Stero\Shield\Runtime\TimingReport;
use Stero\Shield\Store\ArrayStore;
use Stero\Shield\Store\Store;

final class RuntimeUnreadableStream
{
    /** @var resource|null */
    public $context;

    /** @return array<string, int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        return false;
    }
}

final class RuntimeCountingRule implements Rule
{
    public int $calls = 0;

    public function __construct(private readonly Phase $runPhase = Phase::Inline) {}

    public function id(): string
    {
        return 'runtime-test';
    }

    public function phase(): Phase
    {
        return $this->runPhase;
    }

    public function inspect(NormalizedRequest $request): ?Finding
    {
        $this->calls++;

        return new Finding('runtime-test', Severity::High, 'Runtime test', $request->path, true);
    }
}

final class RuntimeThrowingRule implements Rule
{
    public function id(): string
    {
        return 'runtime-throwing';
    }
    public function phase(): Phase
    {
        return Phase::Inline;
    }
    public function inspect(NormalizedRequest $request): ?Finding
    {
        throw new RuntimeException('inspection failed');
    }
}

final class RuntimeThrowingDeferredRule implements Rule
{
    public function id(): string
    {
        return 'runtime-throwing-deferred';
    }
    public function phase(): Phase
    {
        return Phase::Deferred;
    }
    public function inspect(NormalizedRequest $request): ?Finding
    {
        throw new RuntimeException('deferred inspection failed');
    }
}

final class RuntimeCaptureRule implements Rule
{
    public ?NormalizedRequest $lastRequest = null;

    public function id(): string
    {
        return 'runtime-capture';
    }
    public function phase(): Phase
    {
        return Phase::Deferred;
    }
    public function inspect(NormalizedRequest $request): ?Finding
    {
        $this->lastRequest = $request;

        return new Finding('runtime-capture', Severity::Low, 'Captured', $request->path, false);
    }
}

function runtimeRequest(string $path = '/.env'): NormalizedRequest
{
    return new NormalizedRequest('GET', $path, ['q' => 'v'], ['user-agent' => 'test'], 'body', true, '203.0.113.9', 'tenant', null, new DateTimeImmutable('2026-10-22T00:00:00Z'));
}

function runtimeEngine(Mode $mode = Mode::Detect, ?Rule $rule = null, ?Store $store = null, ?EventSink $sink = null, array $allowlist = []): array
{
    $store ??= new ArrayStore();
    $sink ??= new InMemorySink();
    $engine = new Engine([$rule ?? new Stero\Shield\Rules\SensitivePathRule()], $store, $sink, new EngineConfig(mode: $mode, allowlist: $allowlist));

    return [$engine, $store, $sink];
}

test('settings applies all defaults and defaults Redis database to five', function (): void {
    $settings = Settings::fromArray([]);

    expect($settings->enabled)->toBeFalse()
        ->and($settings->mode)->toBe(Mode::Detect)
        ->and($settings->eventsPath)->toBe('storage/shield/events.jsonl')
        ->and($settings->allowlistFile)->toBe('storage/shield/allowlist.txt')
        ->and($settings->allowlistExtra)->toBe([])
        ->and($settings->redis)->toBe(['host' => '127.0.0.1', 'port' => 6379, 'password' => null, 'database' => 5, 'prefix' => 'shield:'])
        ->and($settings->skipPrefixes)->toBe(['/up', '/build/', '/storage/'])
        ->and($settings->timing)->toBe(['enabled' => false, 'sample_every' => 20, 'path' => 'storage/shield/timing.jsonl'])
        ->and($settings->sensitivePaths)->toBeNull();
});

test('settings maps enforce and parses extra allowlist strings and lists', function (): void {
    expect(Settings::fromArray(['mode' => 'enforce'])->mode)->toBe(Mode::Enforce)
        ->and(Settings::fromArray(['mode' => 'ENFORCE'])->mode)->toBe(Mode::Detect)
        ->and(Settings::fromArray(['allowlist_extra' => '192.0.2.1, 2001:db8::1'])->allowlistExtra)->toBe(['192.0.2.1', '2001:db8::1'])
        ->and(Settings::fromArray(['allowlist_extra' => ['192.0.2.1']])->allowlistExtra)->toBe(['192.0.2.1']);
});

test('settings never throws on malformed strings and bad list element types', function (): void {
    $settings = Settings::fromArray(['allowlist_extra' => "\xFF,192.0.2.1", 'sensitive_paths' => [1]]);

    expect($settings->allowlistExtra)->toHaveCount(2)->and($settings->sensitivePaths)->toBeNull();
});

test('settings discards bad types and invalid skip prefixes and normalises timing sample count', function (): void {
    $settings = Settings::fromArray([
        'enabled' => 'yes', 'events_path' => [], 'allowlist_file' => false, 'mode' => [],
        'allowlist_extra' => 12, 'redis' => ['host' => [], 'port' => '6379', 'database' => '0', 'prefix' => false],
        'skip_prefixes' => ['up', '/ready', 4, ''],
        'timing' => ['enabled' => 1, 'sample_every' => 0, 'path' => []],
        'sensitive_paths' => 'not-a-list',
    ]);

    expect($settings->enabled)->toBeFalse()
        ->and($settings->eventsPath)->toBe('storage/shield/events.jsonl')
        ->and($settings->allowlistFile)->toBe('storage/shield/allowlist.txt')
        ->and($settings->allowlistExtra)->toBe([])
        ->and($settings->redis)->toBe(['host' => '127.0.0.1', 'port' => 6379, 'password' => null, 'database' => 5, 'prefix' => 'shield:'])
        ->and($settings->skipPrefixes)->toBe(['/ready'])
        ->and($settings->timing)->toBe(['enabled' => false, 'sample_every' => 20, 'path' => 'storage/shield/timing.jsonl'])
        ->and($settings->sensitivePaths)->toBeNull();
});

test('allowlist loader validates IPv4 IPv6 CIDR entries and counts invalid values', function (): void {
    $path = sys_get_temp_dir() . '/shield-allowlist-' . bin2hex(random_bytes(5));
    file_put_contents($path, "# comment\n\n192.0.2.1\n2001:db8::1\n2001:db8::/32\n203.0.113.0/24\ninvalid\n192.0.2.1\n");
    try {
        $result = (new AllowlistLoader())->load($path, ['198.51.100.1', 'bad']);
        expect($result->entries)->toBe(['192.0.2.1', '2001:db8::1', '2001:db8::/32', '203.0.113.0/24', '198.51.100.1'])
            ->and($result->dropped)->toBe(2);
    } finally {
        unlink($path);
    }
});

test('allowlist loader tolerates missing and unreadable files and merges extra entries', function (): void {
    $result = (new AllowlistLoader())->load(sys_get_temp_dir() . '/shield-missing-' . bin2hex(random_bytes(5)), ['192.0.2.1']);
    stream_wrapper_register('shield-runtime-unreadable', RuntimeUnreadableStream::class);
    try {
        $unreadable = (new AllowlistLoader())->load('shield-runtime-unreadable://file', ['192.0.2.1']);
        expect($result->entries)->toBe(['192.0.2.1'])->and($result->dropped)->toBe(0)
            ->and($unreadable->entries)->toBe(['192.0.2.1'])->and($unreadable->dropped)->toBe(0);
    } finally {
        stream_wrapper_unregister('shield-runtime-unreadable');
    }
});

test('engine factory defaults to sensitive paths, maps modes and applies allowlist', function (): void {
    $detect = EngineFactory::create(Settings::fromArray([]), new ArrayStore(), new InMemorySink());
    expect($detect->inspect(runtimeRequest(), Phase::Inline)->wouldBlock)->toBeTrue()
        ->and($detect->inspect(runtimeRequest(), Phase::Inline)->blocked)->toBeFalse();

    $sink = new InMemorySink();
    $enforce = EngineFactory::create(Settings::fromArray(['mode' => 'enforce', 'allowlist_extra' => '203.0.113.9']), new ArrayStore(), $sink);
    $decision = $enforce->inspect(runtimeRequest(), Phase::Inline);
    expect($decision->wouldBlock)->toBeTrue()->and($decision->blocked)->toBeFalse()
        ->and($sink->events())->toHaveCount(1);
    $enforcingEngine = EngineFactory::create(Settings::fromArray(['mode' => 'enforce']), new ArrayStore(), new InMemorySink());
    expect($enforcingEngine->inspect(runtimeRequest(), Phase::Inline)->blocked)->toBeTrue();

    $custom = EngineFactory::create(Settings::fromArray(['sensitive_paths' => ['private.txt']]), new ArrayStore(), new InMemorySink());
    expect($custom->inspect(runtimeRequest('/private.txt'), Phase::Inline)->wouldBlock)->toBeTrue()
        ->and($custom->inspect(runtimeRequest('/.env'), Phase::Inline)->wouldBlock)->toBeFalse();
});

test('engine factory honors explicit store and sink and passes Redis settings to connector', function (): void {
    $store = new ArrayStore();
    $sink = new InMemorySink();
    $engine = EngineFactory::create(Settings::fromArray([]), $store, $sink);
    $engine->inspect(runtimeRequest(), Phase::Inline);
    $storeProperty = new ReflectionProperty(Engine::class, 'store');
    expect($sink->events())->toHaveCount(1)
        ->and($storeProperty->getValue($engine))->toBe($store)
        ->and($store->isBanned('203.0.113.9'))->toBeFalse();

    $seen = null;
    $fallback = EngineFactory::create(Settings::fromArray(['redis' => ['database' => 7]]), connector: static function (...$arguments) use (&$seen): ?Store {
        $seen = $arguments;

        return null;
    });
    expect($seen)->toBe(['127.0.0.1', 6379, null, 100, 100, 7])
        ->and($fallback)->toBeInstanceOf(Engine::class);
    $storeProperty = new ReflectionProperty(Engine::class, 'store');
    expect($storeProperty->getValue($fallback))->toBeInstanceOf(NullStore::class);
    $throwingFallback = EngineFactory::create(Settings::fromArray([]), connector: static function (): never {
        throw new RuntimeException('connection failed');
    });
    expect($storeProperty->getValue($throwingFallback))->toBeInstanceOf(NullStore::class);
});

test('inspection allow is marked skipped', function (): void {
    $inspection = Inspection::allow();
    expect($inspection->decision)->toEqual(Decision::allow())
        ->and($inspection->inlineMicros)->toBe(0)
        ->and($inspection->skipped)->toBeTrue();
});

test('guard does not inspect when disabled or skipped', function (): void {
    $rule = new RuntimeCountingRule();
    [$engine] = runtimeEngine(rule: $rule);
    $disabled = new Guard(Settings::fromArray([]), $engine);
    $skipped = new Guard(Settings::fromArray(['enabled' => true]), $engine);

    expect($disabled->isEnabled())->toBeFalse()
        ->and($disabled->before(runtimeRequest())->skipped)->toBeTrue()
        ->and($skipped->shouldSkip('/up'))->toBeTrue()
        ->and($skipped->shouldSkip('/UP'))->toBeFalse()
        ->and($skipped->shouldSkip('/x/up'))->toBeFalse()
        ->and($skipped->before(runtimeRequest('/build/chunk.js'))->skipped)->toBeTrue()
        ->and($rule->calls)->toBe(0);
});

test('guard detect records findings but does not block', function (): void {
    [$engine] = runtimeEngine();
    $inspection = (new Guard(Settings::fromArray(['enabled' => true]), $engine))->before(runtimeRequest());

    expect($inspection->decision->wouldBlock)->toBeTrue()
        ->and($inspection->decision->blocked)->toBeFalse()
        ->and($inspection->inlineMicros)->toBeGreaterThanOrEqual(0)
        ->and($inspection->skipped)->toBeFalse();
});

test('guard enforce blocks with 404 and fixed no-store response headers', function (): void {
    [$engine] = runtimeEngine(Mode::Enforce);
    $inspection = (new Guard(Settings::fromArray(['enabled' => true, 'mode' => 'enforce']), $engine))->before(runtimeRequest());

    expect($inspection->decision->blocked)->toBeTrue()
        ->and($inspection->decision->responseStatus)->toBe(404)
        ->and(Guard::BLOCK_BODY)->toBe('Not Found')
        ->and(Guard::blockHeaders())->toBe(['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
});

test('guard fails open when a rule fails during engine inspection', function (): void {
    [$engine] = runtimeEngine(rule: new RuntimeThrowingRule());
    $inspection = (new Guard(Settings::fromArray(['enabled' => true]), $engine))->before(runtimeRequest());

    expect($inspection->decision)->toEqual(Decision::allow())->and($inspection->skipped)->toBeFalse();
});

test('guard deferred inspection copies request fields and adds user id to recorded event', function (): void {
    $sink = new InMemorySink();
    $rule = new RuntimeCaptureRule();
    [$engine] = runtimeEngine(rule: $rule, sink: $sink);
    $settings = Settings::fromArray(['enabled' => true]);
    $guard = new Guard($settings, $engine);
    $inspection = $guard->before(runtimeRequest('/normal'));
    $guard->after(runtimeRequest('/normal'), 'user-42', $inspection);
    $event = $sink->events()[0]->toArray();

    expect($event['user'])->toBe('user-42')
        ->and($event['tenant'])->toBe('tenant')
        ->and($event['ip'])->toBe('203.0.113.9')
        ->and($event['path'])->toBe('/normal')
        ->and($rule->lastRequest)->toEqual(new NormalizedRequest(
            'GET',
            '/normal',
            ['q' => 'v'],
            ['user-agent' => 'test'],
            'body',
            true,
            '203.0.113.9',
            'tenant',
            'user-42',
            new DateTimeImmutable('2026-10-22T00:00:00Z'),
        ));
});

test('guard does nothing after a skipped inspection and swallows deferred failures', function (): void {
    $rule = new RuntimeCountingRule(Phase::Deferred);
    [$engine] = runtimeEngine(rule: $rule);
    $guard = new Guard(Settings::fromArray(['enabled' => true]), $engine);
    $guard->after(runtimeRequest(), 'user', Inspection::allow());
    expect($rule->calls)->toBe(0);

    [$throwing] = runtimeEngine(rule: new RuntimeThrowingDeferredRule());
    $guard = new Guard(Settings::fromArray(['enabled' => true]), $throwing);
    $inspection = new Inspection(Decision::allow(), 1, false);
    expect(fn() => $guard->after(runtimeRequest(), null, $inspection))->not->toThrow(Throwable::class);
});

test('timing recorder samples, creates folders and appends expected JSON lines', function (): void {
    $path = sys_get_temp_dir() . '/shield-timing-' . bin2hex(random_bytes(5)) . '/nested/timing.jsonl';
    $calls = 0;
    $recorder = new TimingRecorder($path, 3, static function (int $limit) use (&$calls): int {
        $calls++;

        return $calls === 3 ? 1 : 2;
    });
    $recorder->record(10.5, 20.5);
    $recorder->record(11, 21);
    $recorder->record(12, 22);
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    assert(is_array($lines));
    $row = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);

    expect($calls)->toBe(3)->and($lines)->toHaveCount(1)
        ->and(array_keys($row))->toBe(['ts', 'inline_us', 'deferred_us'])
        ->and($row['inline_us'])->toBe(12)
        ->and($row['deferred_us'])->toBe(22);
    unlink($path);
    rmdir(dirname($path));
    rmdir(dirname(dirname($path)));
});

test('timing recorder never throws for an unwritable path', function (): void {
    $path = sys_get_temp_dir() . '/shield-not-a-folder-' . bin2hex(random_bytes(5));
    file_put_contents($path, 'x');
    $recorder = new TimingRecorder($path . '/timing.jsonl', 1);
    expect(fn() => $recorder->record(1, 2))->not->toThrow(Throwable::class);
    unlink($path);
});

test('timing report computes nearest-rank percentiles in milliseconds and skips bad rows', function (): void {
    $path = sys_get_temp_dir() . '/shield-report-' . bin2hex(random_bytes(5));
    $rows = [];
    for ($value = 1; $value <= 100; $value++) {
        $rows[] = json_encode(['ts' => 'now', 'inline_us' => $value * 1000, 'deferred_us' => $value * 2000], JSON_THROW_ON_ERROR);
    }
    file_put_contents($path, "bad json\n" . implode("\n", $rows) . "\n{\"inline_us\":\"bad\"}\n");
    $report = TimingReport::fromFile($path);
    unlink($path);

    expect($report->count)->toBe(100)
        ->and($report->inlineP50Ms)->toBe(50.0)
        ->and($report->inlineP95Ms)->toBe(95.0)
        ->and($report->inlineP99Ms)->toBe(99.0)
        ->and($report->deferredP50Ms)->toBe(100.0)
        ->and($report->deferredP95Ms)->toBe(190.0)
        ->and($report->deferredP99Ms)->toBe(198.0)
        ->and($report->passesBudget())->toBeFalse()
        ->and((new TimingReport(1, 1, 4, 5, 1, 2, 4))->passesBudget())->toBeTrue();
});

test('timing report handles missing and empty files', function (): void {
    $path = sys_get_temp_dir() . '/shield-empty-report-' . bin2hex(random_bytes(5));
    file_put_contents($path, '');
    $report = TimingReport::fromFile($path);
    unlink($path);

    expect($report->count)->toBe(0)->and($report->inlineP50Ms)->toBe(0.0)
        ->and(TimingReport::fromFile($path)->count)->toBe(0);
});

test('status reporter reports connected healthy, null store, unwritable events and disabled states', function (): void {
    $directory = sys_get_temp_dir() . '/shield-status-' . bin2hex(random_bytes(5));
    mkdir($directory);
    file_put_contents($directory . '/allowlist.txt', "192.0.2.1\n# comment\n");
    $settings = Settings::fromArray(['enabled' => true, 'events_path' => $directory . '/events/events.jsonl', 'allowlist_file' => $directory . '/allowlist.txt']);
    $connection = new class {
        public function select(int $database): bool
        {
            return $database === 5;
        }
        public function ping(): bool
        {
            return true;
        }
    };
    $report = StatusReporter::report($settings, static fn(...$arguments): object => $connection);
    expect($report->enabled)->toBeTrue()->and($report->healthy)->toBeTrue()
        ->and($report->redisConnected)->toBeTrue()->and($report->redisPing)->toBeTrue()
        ->and($report->allowlistFileExists)->toBeTrue()
        ->and($report->allowlistFileCount)->toBe(1)
        ->and($report->storeClass)->toBe(RedisStore::class);

    $null = StatusReporter::report($settings, static fn(): null => null);
    expect($null->healthy)->toBeFalse()->and($null->storeClass)->toBe(NullStore::class);

    $blocked = StatusReporter::report(Settings::fromArray(['enabled' => true, 'events_path' => '/proc/shield/events.jsonl']), static fn(): null => null);
    expect($blocked->eventsWritable)->toBeFalse()->and($blocked->healthy)->toBeFalse();

    $disabled = StatusReporter::report(Settings::fromArray(['enabled' => false]), static fn(): null => null);
    expect($disabled->healthy)->toBeTrue();
    unlink($directory . '/allowlist.txt');
    rmdir($directory);
});

test('status reporter counts extra allowlist entries and warns on Redis database zero', function (): void {
    $settings = Settings::fromArray(['enabled' => true, 'allowlist_extra' => '192.0.2.1,198.51.100.0/24', 'redis' => ['database' => 0]]);
    $connection = new class {
        public function select(int $database): bool
        {
            return $database === 0;
        }
        public function ping(): bool
        {
            return true;
        }
    };
    $report = StatusReporter::report($settings, static fn(...$arguments): object => $connection);

    expect($report->allowlistExtraCount)->toBe(2)
        ->and($report->warnings)->toContain('Redis database 0 shares a database with the default; a cache flush can erase bans');
});

test('status reporter passes configured Redis database and bounded timeout to its connector', function (): void {
    $settings = Settings::fromArray(['redis' => ['database' => 9]]);
    $seen = null;
    $connection = new class {
        public function select(int $database): bool
        {
            return $database === 9;
        }
        public function ping(): bool
        {
            return true;
        }
    };
    StatusReporter::report($settings, static function (...$arguments) use (&$seen, $connection): object {
        $seen = $arguments;

        return $connection;
    });

    expect($seen)->toBe(['127.0.0.1', 6379, null, 100, 100, 9]);
});

test('status reporter fails open when its Redis connector throws', function (): void {
    $report = StatusReporter::report(Settings::fromArray(['enabled' => true]), static function (): never {
        throw new RuntimeException('Redis unavailable');
    });

    expect($report->redisConnected)->toBeFalse()
        ->and($report->redisPing)->toBeFalse()
        ->and($report->storeClass)->toBe(NullStore::class)
        ->and($report->healthy)->toBeFalse();
});
