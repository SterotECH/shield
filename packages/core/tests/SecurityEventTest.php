<?php

declare(strict_types=1);

use Stero\Shield\Decision;
use Stero\Shield\Events\SecurityEvent;
use Stero\Shield\Finding;
use Stero\Shield\Http\NormalizedRequest;
use Stero\Shield\Mode;
use Stero\Shield\Rules\Severity;

it('serialises only the stable audit fields in UTC', function (): void {
    $at = new DateTimeImmutable('2026-10-20T12:00:00+02:00');
    $request = new NormalizedRequest('GET', '/.env', ['token' => 'secret'], ['authorization' => 'secret'], 'private body', false, '203.0.113.9', 'school', 'person', $at);
    $finding = new Finding('sensitive-path', Severity::High, 'Probe', '/.env', true);
    $event = SecurityEvent::fromFinding($request, $finding, new Decision([$finding], true, false), Mode::Detect);

    expect($event->toArray())->toBe([
        'ts' => '2026-10-20T10:00:00+00:00',
        'tenant' => 'school', 'user' => 'person', 'ip' => '203.0.113.9',
        'method' => 'GET', 'path' => '/.env', 'rule' => 'sensitive-path',
        'severity' => 'high', 'would_block' => true, 'blocked' => false,
        'mode' => 'detect', 'message' => 'Probe', 'evidence' => '/.env',
    ]);
});

it('uses now when the request has no timestamp and limits evidence', function (): void {
    $before = time();
    $request = new NormalizedRequest('GET', '/', [], [], '', false, '203.0.113.9');
    $finding = new Finding('test', Severity::Low, 'Test', str_repeat('é', 250));
    $event = SecurityEvent::fromFinding($request, $finding, Decision::allow(), Mode::Detect);

    expect($event->occurredAt->getTimestamp())->toBeGreaterThanOrEqual($before)
        ->toBeLessThanOrEqual(time())
        ->and(mb_strlen($event->evidence, 'UTF-8'))->toBe(200);
});
