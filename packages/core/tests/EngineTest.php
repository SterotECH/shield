<?php

declare(strict_types=1);

use ShieldTests\FakeRule;
use ShieldTests\ThrowingRule;
use ShieldTests\ThrowingSink;
use ShieldTests\ThrowingStore;
use Stero\Shield\Engine;
use Stero\Shield\EngineConfig;
use Stero\Shield\Events\InMemorySink;
use Stero\Shield\Finding;
use Stero\Shield\Http\NormalizedRequest;
use Stero\Shield\Mode;
use Stero\Shield\Rules\Phase;
use Stero\Shield\Rules\Severity;
use Stero\Shield\Store\ArrayStore;

require_once __DIR__ . '/EngineHelpers.php';

function engineTestRequest(string $ip = '203.0.113.9', string $path = '/.env'): NormalizedRequest
{
    return new NormalizedRequest('GET', $path, [], [], '', false, $ip);
}

function engineFinding(bool $banWorthy = true, string $id = 'probe'): Finding
{
    return new Finding($id, Severity::High, 'Probe', '/.env', $banWorthy);
}

beforeEach(function (): void {
    $this->now = 100;
    $this->store = new ArrayStore(fn(): int => $this->now);
    $this->sink = new InMemorySink();
});

it('allows requests with no rules or events', function (): void {
    $decision = (new Engine([], $this->store, $this->sink, new EngineConfig()))->inspect(engineTestRequest(), Phase::Inline);
    expect($decision->findings)->toBe([])->and($decision->wouldBlock)->toBeFalse()
        ->and($decision->blocked)->toBeFalse()->and($this->sink->events())->toBe([]);
});

it('records honest decisions in both modes and phases', function (Mode $mode, Phase $phase, bool $worthy): void {
    $finding = engineFinding($worthy);
    $store = new ThrowingStore(inner: $this->store);
    $decision = (new Engine([new FakeRule($finding, $phase)], $store, $this->sink, new EngineConfig($mode, 60, 120, blockStatus: 410)))
        ->inspect(engineTestRequest(), $phase);
    $bans = $mode === Mode::Enforce && $worthy;
    $blocks = $bans && $phase === Phase::Inline;
    expect($decision->findings)->toBe([$finding])->and($decision->wouldBlock)->toBe($worthy)
        ->and($decision->blocked)->toBe($blocks)->and($decision->responseStatus)->toBe($blocks ? 410 : null)
        ->and($decision->banForSeconds)->toBe($bans ? 60 : null)
        ->and($this->store->isBanned('203.0.113.9'))->toBe($bans)
        ->and($this->sink->events())->toHaveCount(1);
    $event = $this->sink->events()[0]->toArray();
    expect($event['would_block'])->toBe($worthy)->and($event['blocked'])->toBe($blocks)->and($event['mode'])->toBe($mode->value);
    if (!$bans) {
        expect($store->calls)->not->toContain('ban', 'increment');
    } else {
        $this->now += 60;
        expect($this->store->isBanned('203.0.113.9'))->toBeFalse();
    }
})->with([Mode::Detect, Mode::Enforce])->with([Phase::Inline, Phase::Deferred])->with([true, false]);

it('short circuits existing inline bans without extending expiry', function (Mode $mode): void {
    $this->store->ban('203.0.113.9', 60);
    $this->now += 20;
    $rule = new FakeRule(engineFinding());
    $path = '/' . str_repeat('é', 220);
    $decision = (new Engine([$rule], $this->store, $this->sink, new EngineConfig(mode: $mode, blockStatus: 410)))
        ->inspect(engineTestRequest(path: $path), Phase::Inline);
    expect($decision->alreadyBanned)->toBeTrue()->and($decision->wouldBlock)->toBeTrue()
        ->and($decision->blocked)->toBe($mode === Mode::Enforce)
        ->and($decision->responseStatus)->toBe($mode === Mode::Enforce ? 410 : null)
        ->and($decision->banForSeconds)->toBeNull()->and($rule->calls)->toBe(0)
        ->and($decision->findings)->toHaveCount(1)->and($this->sink->events())->toHaveCount(1);
    $finding = $decision->findings[0];
    expect($finding->ruleId)->toBe('banned-ip')->and($finding->severity)->toBe(Severity::High)
        ->and($finding->message)->toBe('Request from a banned IP')->and($finding->banWorthy)->toBeFalse()
        ->and($finding->evidence)->toBe(mb_substr($path, 0, 200, 'UTF-8'))
        ->and($this->sink->events()[0]->ruleId)->toBe('banned-ip');
    $this->now = 160;
    expect($this->store->isBanned('203.0.113.9'))->toBeFalse();
})->with([Mode::Detect, Mode::Enforce]);

it('filters phases and never checks deferred bans', function (): void {
    $this->store->ban('203.0.113.9', 60);
    $store = new ThrowingStore('isBanned', inner: $this->store);
    $inline = new FakeRule(engineFinding());
    $deferred = new FakeRule(engineFinding(false), Phase::Deferred);
    $engine = new Engine([$inline, $deferred], $store, $this->sink, new EngineConfig());
    $decision = $engine->inspect(engineTestRequest(), Phase::Deferred);
    expect($decision->alreadyBanned)->toBeFalse()->and($decision->findings)->toHaveCount(1)
        ->and($inline->calls)->toBe(0)->and($deferred->calls)->toBe(1)->and($store->calls)->toBe([]);
    $engine->inspect(engineTestRequest(), Phase::Inline);
    expect($inline->calls)->toBe(1)->and($deferred->calls)->toBe(1);
});

it('short circuits only worthy inline findings and preserves event order', function (Phase $phase, bool $firstWorthy): void {
    $first = new FakeRule(engineFinding($firstWorthy, 'first'), $phase);
    $second = new FakeRule(engineFinding(true, 'second'), $phase);
    $decision = (new Engine([$first, $second], $this->store, $this->sink, new EngineConfig()))->inspect(engineTestRequest(), $phase);
    $stops = $phase === Phase::Inline && $firstWorthy;
    expect($first->calls)->toBe(1)->and($second->calls)->toBe($stops ? 0 : 1)
        ->and(array_map(fn($event) => $event->ruleId, $this->sink->events()))->toBe($stops ? ['first'] : ['first', 'second'])
        ->and($decision->findings)->toHaveCount($stops ? 1 : 2);
})->with([Phase::Inline, Phase::Deferred])->with([true, false]);

it('escalates safely and resets the fixed 30 day window', function (): void {
    $engine = new Engine([new FakeRule(engineFinding())], $this->store, $this->sink, new EngineConfig(Mode::Enforce, maxBanSeconds: 604800));
    foreach ([86400, 172800, 345600, 604800, 604800] as $seconds) {
        expect($engine->inspect(engineTestRequest(), Phase::Inline)->banForSeconds)->toBe($seconds);
        $this->store->unban('203.0.113.9');
        $this->now++;
    }
    $this->now = 100 + 30 * 86400;
    expect($engine->inspect(engineTestRequest(), Phase::Inline)->banForSeconds)->toBe(86400);
});

it('handles failed counters and huge counts without overflow', function (int $count, int $expected): void {
    $store = new ThrowingStore(count: $count);
    $decision = (new Engine([new FakeRule(engineFinding())], $store, $this->sink, new EngineConfig(Mode::Enforce, 86400, PHP_INT_MAX)))
        ->inspect(engineTestRequest(), Phase::Inline);
    expect($decision->banForSeconds)->toBe($expected)->and($decision->blocked)->toBeTrue();
})->with([[0, 86400], [-1, 86400], [100, PHP_INT_MAX], [PHP_INT_MAX, PHP_INT_MAX]]);

it('caps the ban at the configured maximum on offence 100', function (): void {
    $decision = (new Engine([new FakeRule(engineFinding())], new ThrowingStore(count: 100), $this->sink, new EngineConfig(Mode::Enforce, 86400, 604800)))
        ->inspect(engineTestRequest(), Phase::Inline);
    expect($decision->banForSeconds)->toBeInt()->toBe(604800);
});

it('never checks or mutates bans for unbannable clients', function (string $ip, array $allowlist): void {
    $this->store->ban($ip, 60);
    $store = new ThrowingStore('isBanned', inner: $this->store);
    $decision = (new Engine([new FakeRule(engineFinding())], $store, $this->sink, new EngineConfig(mode: Mode::Enforce, allowlist: $allowlist)))
        ->inspect(engineTestRequest($ip), Phase::Inline);
    expect($decision->wouldBlock)->toBeTrue()->and($decision->blocked)->toBeFalse()->and($decision->responseStatus)->toBeNull()
        ->and($decision->alreadyBanned)->toBeFalse()->and($decision->banForSeconds)->toBeNull()
        ->and($this->sink->events())->toHaveCount(1)->and($store->calls)->toBe([]);
})->with([
    ['192.0.2.10', ['192.0.2.10']], ['192.0.2.10', ['192.0.2.0/24']],
    ['2001:db8::1', ['2001:db8::/32']], ['::ffff:192.0.2.10', ['192.0.2.0/24']],
    ['0.0.0.0', []], ['unknown', []], ["\xff", []],
]);

it('rejects invalid allowlist configuration at boot', function (): void {
    expect(fn() => new Engine([], $this->store, $this->sink, new EngineConfig(allowlist: ['abc'])))->toThrow(InvalidArgumentException::class);
});

it('skips individual throwing rules and continues', function (bool $phaseThrows): void {
    $rule = new FakeRule(engineFinding());
    $decision = (new Engine([new ThrowingRule($phaseThrows), $rule], $this->store, $this->sink, new EngineConfig()))->inspect(engineTestRequest(), Phase::Inline);
    expect($decision->wouldBlock)->toBeTrue()->and($rule->calls)->toBe(1)->and($this->sink->events())->toHaveCount(1);
})->with([true, false]);

it('treats failed ban lookups as not banned', function (): void {
    $rule = new FakeRule(engineFinding());
    $decision = (new Engine([$rule], new ThrowingStore('isBanned'), $this->sink, new EngineConfig()))->inspect(engineTestRequest(), Phase::Inline);
    expect($decision->wouldBlock)->toBeTrue()->and($decision->alreadyBanned)->toBeFalse()->and($rule->calls)->toBe(1);
});

it('preserves blocking when writing a ban throws', function (Phase $phase): void {
    $decision = (new Engine([new FakeRule(engineFinding(), $phase)], new ThrowingStore('ban'), $this->sink, new EngineConfig(Mode::Enforce)))
        ->inspect(engineTestRequest(), $phase);
    expect($decision->wouldBlock)->toBeTrue()->and($decision->blocked)->toBe($phase === Phase::Inline)
        ->and($decision->banForSeconds)->toBeNull()->and($this->sink->events())->toHaveCount(1);
})->with([Phase::Inline, Phase::Deferred]);

it('preserves decisions and attempts every event when the sink throws', function (bool $banned): void {
    $sink = new ThrowingSink();
    if ($banned) {
        $this->store->ban('203.0.113.9', 60);
    }
    $rules = [new FakeRule(engineFinding(false)), new FakeRule(engineFinding())];
    $decision = (new Engine($rules, $this->store, $sink, new EngineConfig(Mode::Enforce)))->inspect(engineTestRequest(), Phase::Inline);
    expect($decision->wouldBlock)->toBeTrue()->and($decision->blocked)->toBeTrue()
        ->and($decision->alreadyBanned)->toBe($banned)->and($sink->calls)->toBe($banned ? 1 : 2);
})->with([true, false]);

it('uses the base duration when increment throws without losing the decision or event', function (): void {
    $decision = (new Engine([new FakeRule(engineFinding())], new ThrowingStore('increment'), $this->sink, new EngineConfig(Mode::Enforce)))
        ->inspect(engineTestRequest(), Phase::Inline);
    expect($decision->findings)->toHaveCount(1)->and($decision->wouldBlock)->toBeTrue()
        ->and($decision->blocked)->toBeTrue()->and($decision->banForSeconds)->toBe(86400)
        ->and($this->sink->events())->toHaveCount(1);
});

it('materialises generator rules at construction and reuses them', function (): void {
    $iterations = 0;
    $rule = new FakeRule(engineFinding());
    $rules = (function () use (&$iterations, $rule): Generator {
        $iterations++;
        yield 'rule' => $rule;
    })();
    $engine = new Engine($rules, $this->store, $this->sink, new EngineConfig());
    expect($iterations)->toBe(1);
    for ($i = 0; $i < 2; $i++) {
        expect($engine->inspect(engineTestRequest(), Phase::Inline)->wouldBlock)->toBeTrue();
    }
    expect($rule->calls)->toBe(2)->and($iterations)->toBe(1);
});
