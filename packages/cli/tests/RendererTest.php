<?php

declare(strict_types=1);

use Stero\Shield\CLI\Renderer;
use Stero\Shield\CLI\Statistics;
use Symfony\Component\Console\Output\BufferedOutput;

it('renders Termwind decoration only when enabled', function (bool $decorated): void {
    $output = new BufferedOutput(decorated: $decorated);
    Termwind\renderUsing($output);
    $renderer = new Renderer($output, 80);
    $renderer->header('events.jsonl', 1, 'detect');
    $renderer->event(['blocked' => true, 'path' => '/.env']);
    $renderer->footer(3);
    $text = $output->fetch();
    expect($text)->toContain('SHIELD', 'detect', 'events.jsonl', '1 event', 'BLOCKED', '3 lines skipped');
    expect(mb_strpos($text, "\e", 0, 'UTF-8') !== false)->toBe($decorated);
})->with([false, true]);

it('honours NO_COLOR even with decorated output', function (): void {
    $old = getenv('NO_COLOR');
    putenv('NO_COLOR=1');
    try {
        $output = new BufferedOutput(decorated: true);
        (new Renderer($output))->event(['blocked' => true]);
        expect($output->fetch())->toContain('BLOCKED')->not->toContain("\e");
    } finally {
        $old === false ? putenv('NO_COLOR') : putenv('NO_COLOR=' . $old);
    }
});

it('renders bordered stats sections and a friendly empty panel', function (): void {
    $output = new BufferedOutput(decorated: false);
    $renderer = new Renderer($output);
    $renderer->stats((new Statistics())->summarise([]));
    expect($output->fetch())->toContain('No events', '─');
    $renderer->stats((new Statistics())->summarise([
        ['rule' => '<b>x</b>', 'ip' => '1.2.3.4', 'path' => "/\e[2J", 'would_block' => true,
            'mode' => 'detect', 'ts' => '2026-10-21T12:00:00Z'],
    ]));
    expect($output->fetch())->toContain('Total events: 1', 'Top rules', 'Top IPs', 'Top paths', 'Events per hour', '█', '<b>x</b>', '/[2J');
});

it('closes and aligns both sides of every panel row', function (): void {
    $output = new BufferedOutput(decorated: false);
    (new Renderer($output, 60))->stats((new Statistics())->summarise([['path' => '/<error>界é</error>']]));
    $rows = 0;
    foreach (explode("\n", $output->fetch()) as $line) {
        if (mb_strpos($line, '│', 0, 'UTF-8') === 0) {
            $rows++;
            expect(mb_substr($line, -1, null, 'UTF-8'))->toBe('│');
            expect(mb_strwidth($line, 'UTF-8'))->toBe(60);
        }
    }
    expect($rows)->toBeGreaterThan(0);
});
