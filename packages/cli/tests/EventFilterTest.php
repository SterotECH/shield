<?php

declare(strict_types=1);

use Stero\Shield\CLI\EventFilter;

it('applies each filter alone and in combination', function (array $options): void {
    $filter = new EventFilter($options, new DateTimeImmutable('2026-10-21T12:00:00Z'));
    $event = ['rule' => 'sensitive-path', 'ip' => '203.0.113.9', 'tenant' => 'school',
        'severity' => 'high', 'would_block' => true, 'ts' => '2026-10-21T11:59:00Z'];
    expect($filter->matches($event))->toBeTrue();
    foreach ($options as $key => $value) {
        $changed = $event;
        $changed[match ($key) {
            'since' => 'ts', 'would-block' => 'would_block', default => $key,
        }] = match ($key) {
            'since' => '2020-01-01T00:00:00Z', 'would-block' => false, 'severity' => 'low', default => 'other',
        };
        expect($filter->matches($changed))->toBeFalse();
    }
})->with([
    [['rule' => 'sensitive-path']], [['ip' => '203.0.113.9']], [['tenant' => 'school']],
    [['severity' => 'medium']], [['would-block' => true]], [['since' => '15m']],
    [['rule' => 'sensitive-path', 'ip' => '203.0.113.9', 'tenant' => 'school', 'severity' => 'high', 'would-block' => true, 'since' => '2h']],
]);

it('parses relative and ISO since times inclusively', function (string $since, string $boundary): void {
    $filter = new EventFilter(['since' => $since], new DateTimeImmutable('2026-10-21T12:00:00Z'));
    expect($filter->matches(['ts' => $boundary]))->toBeTrue()
        ->and($filter->matches(['ts' => (new DateTimeImmutable($boundary))->modify('-1 second')->format(DATE_ATOM)]))->toBeFalse()
        ->and($filter->matches(['ts' => null]))->toBeFalse();
})->with([
    ['15m', '2026-10-21T11:45:00Z'], ['2h', '2026-10-21T10:00:00Z'],
    ['1d', '2026-10-20T12:00:00Z'], ['2026-10-21T11:00:00+01:00', '2026-10-21T10:00:00Z'],
]);

it('rejects invalid since or severity instead of silently ignoring it', function (array $options): void {
    expect(fn() => new EventFilter($options))->toThrow(InvalidArgumentException::class);
})->with([[['since' => 'yesterday-ish']], [['since' => '2026-02-30T10:00:00Z']], [['since' => '0m']], [['severity' => 'urgent']]]);

it('treats severity as a minimum and handles missing fields', function (): void {
    $filter = new EventFilter(['severity' => 'high']);
    expect($filter->matches(['severity' => 'critical']))->toBeTrue()
        ->and($filter->matches(['severity' => null]))->toBeFalse()
        ->and((new EventFilter())->matches([]))->toBeTrue()
        ->and((new EventFilter(['would-block' => true]))->matches(['would_block' => 'true']))->toBeFalse();
});
