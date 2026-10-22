<?php

declare(strict_types=1);

use Stero\Shield\Runtime\Events\EventFilter;
use Stero\Shield\Runtime\Events\EventReader;
use Stero\Shield\Runtime\Events\Facets;
use Stero\Shield\Runtime\Events\IncidentGrouper;
use Stero\Shield\Runtime\Events\Pager;
use Stero\Shield\Runtime\Events\PathFormatter;

function logEvent(array $changes = []): array
{
    return array_replace(['ts' => '2026-10-22T12:00:00+00:00', 'ip' => '192.0.2.1', 'rule' => 'sensitive-path', 'severity' => 'high', 'tenant' => null, 'path' => '/.env', 'method' => 'GET', 'blocked' => false, 'would_block' => true, 'mode' => 'detect', 'evidence' => '/.env', 'message' => 'Probe', 'user' => null], $changes);
}

it('reads only the final physical lines newest first and skips malformed records', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'events');
    file_put_contents($file, json_encode(logEvent(['path' => '/old'])) . "\ninvalid\n{}\n" . json_encode(logEvent(['path' => '/new'])));
    try {
        $reader = new EventReader();
        expect(array_column($reader->read($file, 3), 'path'))->toBe(['/new'])
            ->and(array_column($reader->read($file, 4), 'path'))->toBe(['/new', '/old'])
            ->and($reader->reason)->toBeNull();
        expect($reader->read($file . '.missing'))->toBe([])->and($reader->reason)->toContain('Cannot read');
    } finally {
        unlink($file);
    }
});

it('reads across seek blocks with and without trailing newlines', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'events');
    try {
        $old = json_encode(logEvent(['path' => '/old']));
        $new = json_encode(logEvent(['path' => '/' . str_repeat('a', 18000)]));
        foreach (['', "\n"] as $ending) {
            file_put_contents($file, $old . "\n" . $new . $ending);
            expect((new EventReader())->read($file, 1))->toBe([json_decode($new, true)]);
        }
        file_put_contents($file, '');
        expect((new EventReader())->read($file))->toBe([]);
        expect((new EventReader())->read(dirname($file)))->toBe([]);
    } finally {
        unlink($file);
    }
});

it('skips bad field types and invalid timestamps without throwing', function (): void {
    foreach (['path' => [], 'blocked' => 'true', 'ts' => 'yesterday', 'severity' => 'huge', 'tenant' => [], 'ts' => '2026-02-30T12:00:00Z'] as $field => $value) {
        expect(EventReader::valid(logEvent([$field => $value])))->toBeFalse();
    }
});

it('filters every field and combines filters', function (array $options, int $count): void {
    $events = [logEvent(), logEvent(['ip' => '192.0.2.2', 'rule' => 'other', 'tenant' => 'school', 'severity' => 'low', 'blocked' => true, 'path' => '/ADMIN', 'ts' => '2026-10-20T12:00:00Z'])];
    expect((new EventFilter(...$options))->apply($events))->toHaveCount($count);
})->with([
    [['ip' => '192.0.2.1'], 1], [['rule' => 'other'], 1], [['tenant' => 'school'], 1],
    [['severity' => 'low'], 1], [['verdict' => 'blocked'], 1], [['verdict' => 'would_block'], 1],
    [['pathContains' => 'admin'], 1], [['since' => new DateTimeImmutable('2026-10-21')], 1],
    [['tenant' => ''], 1],
    [['ip' => '192.0.2.1', 'severity' => 'low'], 0], [['ip' => '192.0.2.1', 'rule' => 'sensitive-path'], 1],
]);

it('parses relative times and rejects bad values', function (): void {
    $now = new DateTimeImmutable('2026-10-22T12:00:00Z');
    foreach (['15m' => 900, '2h' => 7200, '1d' => 86400, '7d' => 604800] as $text => $seconds) {
        expect(EventFilter::parseSince($text, $now)->getTimestamp())->toBe($now->getTimestamp() - $seconds);
    }
    expect(fn() => EventFilter::parseSince('yesterday'))->toThrow(InvalidArgumentException::class, 'Invalid --since');
});

it('clamps pages and handles invalid sizes and empty input', function (): void {
    $rows = range(1, 50);
    expect(Pager::slice($rows, -1, 15))->toBe(['rows' => range(1, 15), 'page' => 1, 'pages' => 4, 'total' => 50])
        ->and(Pager::slice($rows, 99, 15)['rows'])->toBe(range(46, 50))
        ->and(Pager::slice($rows, 1, 0)['rows'])->toBe(range(1, 15))
        ->and(Pager::slice([], 99, 15))->toBe(['rows' => [], 'page' => 1, 'pages' => 1, 'total' => 0]);
});

it('counts facets with descending counts and alphabetical ties', function (): void {
    expect(Facets::count([logEvent(['ip' => 'b']), logEvent(['ip' => 'a']), logEvent(['ip' => 'b']), logEvent(['ip' => 'c'])], 'ip'))->toBe(['b' => 2, 'a' => 1, 'c' => 1])
        ->and(Facets::count([logEvent(), logEvent(['blocked' => true])], 'verdict'))->toBe(['blocked' => 1, 'would_block' => 1]);
});

it('groups by adjacent time gaps IP and rule with highest severity and newest ordering', function (): void {
    $events = [logEvent(), logEvent(['ts' => '2026-10-22T12:01:00Z', 'severity' => 'critical', 'blocked' => true]), logEvent(['ts' => '2026-10-22T12:02:01Z']), logEvent(['ip' => '192.0.2.2']), logEvent(['rule' => 'other']), ['ts' => 'bad']];
    $groups = IncidentGrouper::group($events);
    expect($groups)->toHaveCount(4)->and($groups[0]->lastTs)->toBe('2026-10-22T12:02:01Z')
        ->and($groups[1]->count)->toBe(2)->and($groups[1]->severity)->toBe('critical')->and($groups[1]->verdict)->toBe('blocked');
});

it('chains gaps rather than measuring the entire incident duration', function (): void {
    $groups = IncidentGrouper::group([logEvent(), logEvent(['ts' => '2026-10-22T12:01:00Z']), logEvent(['ts' => '2026-10-22T12:02:00Z'])]);
    expect($groups)->toHaveCount(1)->and($groups[0]->count)->toBe(3)
        ->and($groups[0]->firstTs)->toBe('2026-10-22T12:00:00+00:00')->and($groups[0]->lastTs)->toBe('2026-10-22T12:02:00Z');
});

it('fits paths by display width preserving the tail and sanitising before cutting', function (): void {
    $path = '/' . str_repeat('a', 50) . 'END123';
    $result = PathFormatter::fit($path, 24);
    expect(mb_strwidth($result, 'UTF-8'))->toBeLessThanOrEqual(24)->and($result)->toContain('…')->toEndWith('END123');
    expect(PathFormatter::fit(str_repeat('a', 24), 24))->toBe(str_repeat('a', 24))
        ->and(PathFormatter::fit(str_repeat('界', 12), 24))->toBe(str_repeat('界', 12))
        ->and(mb_strwidth(PathFormatter::fit(str_repeat('界', 50), 24), 'UTF-8'))->toBeLessThanOrEqual(24)
        ->and(mb_strwidth(PathFormatter::fit(str_repeat('a', 50), 1), 'UTF-8'))->toBe(12);
});

it('removes controls and bidi overrides before deciding whether to cut', function (): void {
    $dirty = '/' . str_repeat("\e\u{202E}\u{2066}", 30) . 'abcdef';
    expect(PathFormatter::fit($dirty, 12))->toBe('/abcdef');
    expect(PathFormatter::fit("/a\r\0\n\t\u{202A}\u{202B}\u{202C}\u{202D}\u{202E}\u{2066}\u{2067}\u{2068}\u{2069}b", 24))->toBe('/ab');
});
