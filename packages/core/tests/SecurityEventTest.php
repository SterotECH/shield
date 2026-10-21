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

it('copies all fromFinding fields and uses UTC for a missing timestamp', function (): void {
    $request = new NormalizedRequest('POST', '/probe', [], [], '', false, '2001:db8::1', 'tenant', 'user');
    $finding = new Finding('rule', Severity::Medium, 'Message', 'Evidence', true);
    $before = time();
    $event = SecurityEvent::fromFinding($request, $finding, new Decision([$finding], true, true, 410), Mode::Enforce);
    expect($event->occurredAt->getTimezone()->getName())->toBe('UTC')
        ->and(abs($event->occurredAt->getTimestamp() - $before))->toBeLessThanOrEqual(2)
        ->and($event->tenantId)->toBe('tenant')->and($event->userId)->toBe('user')
        ->and($event->ip)->toBe('2001:db8::1')->and($event->method)->toBe('POST')->and($event->path)->toBe('/probe')
        ->and($event->ruleId)->toBe('rule')->and($event->severity)->toBe(Severity::Medium)
        ->and($event->message)->toBe('Message')->and($event->evidence)->toBe('Evidence')
        ->and($event->wouldBlock)->toBeTrue()->and($event->blocked)->toBeTrue()->and($event->mode)->toBe(Mode::Enforce);
});

it('limits serialised evidence without splitting UTF-8 characters', function (string $evidence, string $expected): void {
    $event = new SecurityEvent(new DateTimeImmutable(), null, null, '203.0.113.9', 'GET', '/.env', 'sensitive-path', Severity::High, true, false, Mode::Detect, 'Probe', $evidence);
    $actual = $event->toArray()['evidence'];

    expect($actual)->toBe($expected)
        ->and(mb_strlen($actual, 'UTF-8'))->toBe(200)
        ->and(mb_check_encoding($actual, 'UTF-8'))->toBeTrue();
})->with([
    'exactly 200' => [str_repeat('a', 200), str_repeat('a', 200)],
    '201 characters' => [str_repeat('a', 201), str_repeat('a', 200)],
    'accent at boundary' => [str_repeat('a', 199) . 'étail', str_repeat('a', 199) . 'é'],
    'emoji at boundary' => [str_repeat('a', 199) . '😀tail', str_repeat('a', 199) . '😀'],
]);
