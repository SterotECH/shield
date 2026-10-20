<?php

declare(strict_types=1);

use Stero\Shield\Engine;
use Stero\Shield\EngineConfig;
use Stero\Shield\Events\EventSink;
use Stero\Shield\Events\InMemorySink;
use Stero\Shield\Events\SecurityEvent;
use Stero\Shield\Finding;
use Stero\Shield\Http\NormalizedRequest;
use Stero\Shield\Mode;
use Stero\Shield\Rules\Phase;
use Stero\Shield\Rules\Rule;
use Stero\Shield\Rules\SensitivePathRule;
use Stero\Shield\Rules\Severity;
use Stero\Shield\Store\ArrayStore;
use Stero\Shield\Store\Store;

function engineTestRequest(string $ip = '203.0.113.9'): NormalizedRequest
{
    return new NormalizedRequest('GET', '/.env', [], [], '', false, $ip);
}

function engineTestRule(?Finding $finding, Phase $phase = Phase::Inline, bool $throws = false): Rule
{
    return new class ($finding, $phase, $throws) implements Rule {
        public int $calls = 0;
        public function __construct(private ?Finding $finding, private Phase $phase, private bool $throws) {}
        public function id(): string
        {
            return 'test';
        }
        public function phase(): Phase
        {
            return $this->phase;
        }
        public function inspect(NormalizedRequest $request): ?Finding
        {
            $this->calls++;
            if ($this->throws) {
                throw new RuntimeException('rule failed');
            }
            return $this->finding;
        }
    };
}

it('records detection without banning or blocking', function (): void {
    $store = new ArrayStore();
    $sink = new InMemorySink();
    $engine = new Engine([new SensitivePathRule()], $store, $sink, new EngineConfig());
    $decision = $engine->inspect(engineTestRequest(), Phase::Inline);

    expect($decision->wouldBlock)->toBeTrue()
        ->and($decision->blocked)->toBeFalse()
        ->and($decision->responseStatus)->toBeNull()
        ->and($store->isBanned('203.0.113.9'))->toBeFalse()
        ->and($sink->events())->toHaveCount(1)
        ->and($sink->events()[0]->mode)->toBe(Mode::Detect)
        ->and($sink->events()[0]->wouldBlock)->toBeTrue();
});

it('enforces expiring escalating bans capped at the configured maximum', function (): void {
    $now = 100;
    $store = new ArrayStore(function () use (&$now): int {
        return $now;
    });
    $sink = new InMemorySink();
    $engine = new Engine([new SensitivePathRule()], $store, $sink, new EngineConfig(Mode::Enforce, 10, 25));
    foreach ([10, 20, 25, 25] as $duration) {
        $decision = $engine->inspect(engineTestRequest(), Phase::Inline);
        expect($decision->blocked)->toBeTrue()
            ->and($decision->wouldBlock)->toBeTrue()
            ->and($decision->responseStatus)->toBe(404)
            ->and($decision->banForSeconds)->toBe($duration);
        $banned = $engine->inspect(engineTestRequest(), Phase::Inline);
        expect($banned->alreadyBanned)->toBeTrue()
            ->and($banned->blocked)->toBeTrue()
            ->and($banned->banForSeconds)->toBeNull();
        $now += $duration + 1;
    }
    expect($sink->events())->toHaveCount(8)
        ->and($sink->events()[1]->ruleId)->toBe('banned-ip');
    $now += 30 * 86400 + 1;
    expect($engine->inspect(engineTestRequest(), Phase::Inline)->banForSeconds)->toBe(10);
});

it('never blocks allowlisted clients but still records findings', function (string $ip, string $entry): void {
    $store = new ArrayStore();
    $store->ban($ip, 100);
    $sink = new InMemorySink();
    $engine = new Engine([new SensitivePathRule()], $store, $sink, new EngineConfig(mode: Mode::Enforce, allowlist: [$entry]));
    $decision = $engine->inspect(engineTestRequest($ip), Phase::Inline);

    expect($decision->blocked)->toBeFalse()
        ->and($decision->wouldBlock)->toBeTrue()
        ->and($decision->alreadyBanned)->toBeFalse()
        ->and($decision->banForSeconds)->toBeNull()
        ->and($sink->events())->toHaveCount(1);
})->with([
    ['192.0.2.10', '192.0.2.10'], ['192.0.2.10', '192.0.2.0/24'],
    ['2001:db8::123', '2001:db8::/32'], ['2001:db8::1', '2001:0db8:0:0:0:0:0:1'],
    ['192.0.2.10', '0.0.0.0/0'], ['2001:db8::1', '::/0'],
]);

it('does not allowlist neighbouring networks or malformed entries', function (string $ip, string $entry): void {
    $engine = new Engine([new SensitivePathRule()], new ArrayStore(), new InMemorySink(), new EngineConfig(mode: Mode::Enforce, allowlist: [$entry]));
    expect($engine->inspect(engineTestRequest($ip), Phase::Inline)->blocked)->toBeTrue();
})->with([
    ['192.0.3.10', '192.0.2.0/24'], ['2001:db9::1', '2001:db8::/32'],
    ['192.0.2.10', 'bad/24'], ['192.0.2.10', '192.0.2.0/33'],
    ['192.0.2.10', '::/0'], ['192.0.2.10', '192.0.2.0/-1'],
]);

it('runs only the requested phase and never bans fuzzy findings', function (): void {
    $finding = new Finding('test', Severity::Medium, 'Fuzzy');
    $inline = engineTestRule($finding);
    $deferred = engineTestRule($finding, Phase::Deferred);
    $sink = new InMemorySink();
    $store = new ArrayStore();
    $engine = new Engine([$inline, $deferred], $store, $sink, new EngineConfig(Mode::Enforce));

    foreach (Phase::cases() as $phase) {
        $decision = $engine->inspect(engineTestRequest(), $phase);
        expect($decision->findings)->toBe([$finding])
            ->and($decision->wouldBlock)->toBeFalse()
            ->and($decision->blocked)->toBeFalse();
    }
    expect($inline->calls)->toBe(1)
        ->and($deferred->calls)->toBe(1)
        ->and($sink->events())->toHaveCount(2)
        ->and($store->isBanned('203.0.113.9'))->toBeFalse();
});

it('observes an existing ban in detect mode without running rules', function (): void {
    $store = new ArrayStore();
    $store->ban('203.0.113.9', 100);
    $rule = engineTestRule(null, throws: true);
    $sink = new InMemorySink();
    $decision = (new Engine([$rule], $store, $sink, new EngineConfig()))->inspect(engineTestRequest(), Phase::Inline);
    expect($decision->alreadyBanned)->toBeTrue()
        ->and($decision->wouldBlock)->toBeTrue()
        ->and($decision->blocked)->toBeFalse()
        ->and($rule->calls)->toBe(0)
        ->and($sink->events())->toHaveCount(1);
});

it('fails open on throwing rules', function (): void {
    $engine = new Engine([engineTestRule(null, throws: true)], new ArrayStore(), new InMemorySink(), new EngineConfig(Mode::Enforce));
    $decision = $engine->inspect(engineTestRequest(), Phase::Inline);
    expect($decision->hasFindings())->toBeFalse()->and($decision->blocked)->toBeFalse();
});

it('fails open on throwing sinks', function (): void {
    $sink = new class implements EventSink {
        public function record(SecurityEvent $event): void
        {
            throw new RuntimeException('sink failed');
        }
    };
    $decision = (new Engine([new SensitivePathRule()], new ArrayStore(), $sink, new EngineConfig()))->inspect(engineTestRequest(), Phase::Inline);
    expect($decision->hasFindings())->toBeFalse()->and($decision->blocked)->toBeFalse();
});

it('fails open on throwing stores', function (): void {
    $store = new class implements Store {
        public function increment(string $key, int $windowSeconds): int
        {
            throw new RuntimeException();
        }
        public function ban(string $ip, int $seconds): void
        {
            throw new RuntimeException();
        }
        public function isBanned(string $ip): bool
        {
            throw new RuntimeException();
        }
        public function unban(string $ip): void
        {
            throw new RuntimeException();
        }
    };
    $decision = (new Engine([new SensitivePathRule()], $store, new InMemorySink(), new EngineConfig(Mode::Enforce)))->inspect(engineTestRequest(), Phase::Inline);
    expect($decision->hasFindings())->toBeFalse()->and($decision->blocked)->toBeFalse();
});
