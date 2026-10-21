<?php

declare(strict_types=1);

use Stero\Shield\Net\IpMatcher;

it('matches exact addresses and masked networks', function (array $entries, string $ip, bool $matches): void {
    expect((new IpMatcher($entries))->matches($ip))->toBe($matches);
})->with([
    [['192.0.2.10'], '192.0.2.10', true],
    [['192.0.2.10'], '192.0.2.11', false],
    [['192.0.2.0/24'], '192.0.2.1', true],
    [['192.0.2.0/24'], '192.0.2.255', true],
    [['192.0.2.0/24'], '192.0.3.1', false],
    [['192.0.2.10/32'], '192.0.2.10', true],
    [['192.0.2.10/32'], '192.0.2.11', false],
    [['0.0.0.0/0'], '255.255.255.255', true],
    [['0.0.0.0/0'], '2001:db8::1', false],
    [['2001:db8::/32'], '2001:db8::1', true],
    [['2001:db8::/32'], '2001:db9::1', false],
    [['2001:db8::1'], '2001:0db8:0:0:0:0:0:1', true],
    [['2001:db8::1'], '2001:db8::2', false],
    [['::/0'], '192.0.2.10', false],
    [['192.0.2.10'], '::ffff:192.0.2.10', true],
    [['::ffff:192.0.2.10'], '192.0.2.10', true],
    [['192.0.2.0/24'], '::ffff:192.0.2.255', true],
    [['::ffff:192.0.2.0/120'], '192.0.2.10', true],
    [['192.0.2.0/25'], '192.0.2.127', true],
    [['192.0.2.0/25'], '192.0.2.128', false],
    [[], '192.0.2.10', false],
    [['0.0.0.0/0'], 'unknown', false],
    [['0.0.0.0/0'], "\xff", false],
]);

it('rejects invalid configuration at construction', function (string $entry): void {
    expect(fn() => new IpMatcher([$entry]))->toThrow(InvalidArgumentException::class);
})->with(['999.1.1.1', 'abc', '192.0.2.0/33', '2001:db8::/129', '192.0.2.0/-1', '192.0.2.0/', '192.0.2.0/1/2', "\xff"]);
