<?php

declare(strict_types=1);

use Stero\Shield\Decision;
use Stero\Shield\Events\JsonLinesSink;
use Stero\Shield\Events\SecurityEvent;
use Stero\Shield\Finding;
use Stero\Shield\Http\NormalizedRequest;
use Stero\Shield\Mode;
use Stero\Shield\Rules\Severity;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir() . '/shield-' . bin2hex(random_bytes(8));
    $this->path = $this->directory . '/events.jsonl';
});

afterEach(function (): void {
    if (is_file($this->path)) {
        unlink($this->path);
    }
    if (is_dir($this->directory)) {
        rmdir($this->directory);
    }
});

it('writes exactly one valid JSON line with a final newline', function (): void {
    $event = new SecurityEvent(new DateTimeImmutable(), null, null, '203.0.113.9', 'GET', '/é/.env', 'sensitive-path', Severity::High, true, false, Mode::Detect, 'Probe', '/é/.env');
    (new JsonLinesSink($this->path))->record($event);
    $contents = file_get_contents($this->path);

    expect(mb_check_encoding($contents, 'UTF-8'))->toBeTrue()
        ->and(mb_substr($contents, -1, null, 'UTF-8'))->toBe("\n")
        ->and(mb_substr_count($contents, "\n", 'UTF-8'))->toBe(1)
        ->and(json_decode($contents, true, 512, JSON_THROW_ON_ERROR))->toBe($event->toArray())
        ->and($contents)->toContain('/é/.env');
});

it('records two distinct events in order', function (): void {
    $sink = new JsonLinesSink($this->path);
    $first = new SecurityEvent(new DateTimeImmutable(), null, null, '203.0.113.9', 'GET', '/.env', 'sensitive-path', Severity::High, true, false, Mode::Detect, 'First', '/.env');
    $second = new SecurityEvent(new DateTimeImmutable(), null, null, '203.0.113.10', 'POST', '/.git', 'sensitive-path', Severity::High, true, false, Mode::Detect, 'Second', '/.git');
    $sink->record($first);
    $sink->record($second);
    $lines = file($this->path, FILE_IGNORE_NEW_LINES);

    expect($lines)->toHaveCount(2)
        ->and(json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR))->toBe($first->toArray())
        ->and(json_decode($lines[1], true, 512, JSON_THROW_ON_ERROR))->toBe($second->toArray());
});

it('substitutes invalid UTF-8 in an event path and still writes JSON', function (): void {
    $event = new SecurityEvent(new DateTimeImmutable(), null, null, '203.0.113.9', 'GET', "/bad\xff/path", 'sensitive-path', Severity::High, true, false, Mode::Detect, 'Probe', '/.env');
    (new JsonLinesSink($this->path))->record($event);

    expect(is_file($this->path))->toBeTrue();
    $contents = file_get_contents($this->path);
    expect(mb_check_encoding($contents, 'UTF-8'))->toBeTrue()
        ->and(mb_substr_count($contents, "\n", 'UTF-8'))->toBe(1)
        ->and(json_decode($contents, true, 512, JSON_THROW_ON_ERROR)['path'])->toBe('/bad�/path');
});

it('never writes the request body, cookie or Authorization value', function (): void {
    $request = new NormalizedRequest('GET', '/.env', [], [
        'cookie' => 'cookie-private-174',
        'authorization' => 'Bearer auth-private-285',
    ], 'body-private-396', false, '203.0.113.9');
    $finding = new Finding('sensitive-path', Severity::High, 'Probe', '/.env', true);
    $event = SecurityEvent::fromFinding($request, $finding, new Decision([$finding], true, false), Mode::Detect);
    (new JsonLinesSink($this->path))->record($event);
    $contents = file_get_contents($this->path);

    expect(mb_check_encoding($contents, 'UTF-8'))->toBeTrue()
        ->and($contents)->not->toContain('body-private-396', 'cookie-private-174', 'Bearer auth-private-285')
        ->and(json_decode($contents, true, 512, JSON_THROW_ON_ERROR))->toBe($event->toArray());
});

it('creates its directory and appends one JSON row per event', function (): void {
    $root = sys_get_temp_dir() . '/shield-' . bin2hex(random_bytes(8));
    $path = $root . '/events.jsonl';
    $event = new SecurityEvent(new DateTimeImmutable('2026-10-20T00:00:00Z'), null, null, '203.0.113.9', 'GET', '/.env', 'sensitive-path', Severity::High, true, false, Mode::Detect, 'Probe', '/.env');
    try {
        $sink = new JsonLinesSink($path);
        $sink->record($event);
        $sink->record($event);
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        expect($lines)->toHaveCount(2);
        foreach ($lines as $line) {
            expect(json_decode($line, true, 512, JSON_THROW_ON_ERROR))->toBe($event->toArray());
        }
        expect(fn() => (new JsonLinesSink($path . '/impossible'))->record($event))->not->toThrow(Throwable::class);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
        if (is_dir($root)) {
            rmdir($root);
        }
    }
});
