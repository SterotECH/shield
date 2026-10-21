<?php

declare(strict_types=1);

use Stero\Shield\CLI\Statistics;

it('counts modes, decisions, deterministic top five lists and hourly events', function (): void {
    $events = [];
    foreach (['z', 'b', 'a', 'd', 'c', 'e', 'z'] as $index => $key) {
        $events[] = ['mode' => $index % 2 === 0 ? 'detect' : 'enforce', 'would_block' => true,
            'blocked' => $index % 2 !== 0, 'rule' => $key, 'ip' => $key, 'path' => '/' . $key,
            'ts' => $index === 0 ? '2026-10-21T10:00:00Z' : '2026-10-21T11:00:00Z'];
    }
    $summary = (new Statistics())->summarise($events);
    expect($summary['total'])->toBe(7)
        ->and($summary['modes'])->toBe(['detect' => 4, 'enforce' => 3])
        ->and($summary['would_block'])->toBe(7)->and($summary['blocked'])->toBe(3)
        ->and($summary['rules'])->toBe(['z' => 2, 'a' => 1, 'b' => 1, 'c' => 1, 'd' => 1])
        ->and($summary['ips'])->toBe($summary['rules'])
        ->and($summary['paths'])->toBe(['/z' => 2, '/a' => 1, '/b' => 1, '/c' => 1, '/d' => 1])
        ->and(array_values($summary['hours']))->toBe([1, 6])
        ->and(Statistics::barLine([1, 4, 0]))->toBe('▂█▁');
});

it('handles empty and missing data and does not trust boolean or mode lookalikes', function (): void {
    $stats = new Statistics();
    expect($stats->summarise([])['total'])->toBe(0)->and(Statistics::barLine([]))->toBe('');
    $summary = $stats->summarise([[], ['mode' => '<x>', 'blocked' => 'true', 'would_block' => 1]]);
    expect($summary['total'])->toBe(2)->and($summary['modes'])->toBe(['-' => 2])
        ->and($summary['blocked'])->toBe(0)->and($summary['would_block'])->toBe(0)
        ->and($summary['paths'])->toBe(['-' => 2]);
});
