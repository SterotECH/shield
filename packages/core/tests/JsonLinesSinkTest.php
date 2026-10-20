<?php

declare(strict_types=1);

use Stero\Shield\Events\JsonLinesSink;
use Stero\Shield\Events\SecurityEvent;
use Stero\Shield\Mode;
use Stero\Shield\Rules\Severity;

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
