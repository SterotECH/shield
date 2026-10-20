<?php

declare(strict_types=1);

use Stero\Shield\Events\InMemorySink;
use Stero\Shield\Events\SecurityEvent;
use Stero\Shield\Mode;
use Stero\Shield\Rules\Severity;

it('keeps events in insertion order in its own instance', function (): void {
    $sink = new InMemorySink();
    $event = new SecurityEvent(new DateTimeImmutable(), null, null, '203.0.113.9', 'GET', '/.env', 'sensitive-path', Severity::High, true, false, Mode::Detect, 'Probe', '/.env');

    expect($sink->events())->toBe([]);
    $sink->record($event);
    $sink->record($event);
    expect($sink->events())->toBe([$event, $event])
        ->and((new InMemorySink())->events())->toBe([]);
});

it('resets its events and can record again', function (): void {
    $sink = new InMemorySink();
    $event = new SecurityEvent(new DateTimeImmutable(), null, null, '203.0.113.9', 'GET', '/.env', 'sensitive-path', Severity::High, true, false, Mode::Detect, 'Probe', '/.env');
    $sink->record($event);
    $sink->reset();
    expect($sink->events())->toBe([]);
    $sink->reset();
    $sink->record($event);
    expect($sink->events())->toBe([$event]);
});
