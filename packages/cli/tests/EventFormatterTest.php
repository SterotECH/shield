<?php

declare(strict_types=1);

use Stero\Shield\CLI\EventFormatter;
use Stero\Shield\CLI\Theme;
use Symfony\Component\Console\Output\BufferedOutput;

it('maps each status with blocked taking priority', function (array $event, string $badge, string $colour): void {
    $html = (new EventFormatter())->format($event);
    expect($html)->toContain($badge, $colour);
    $output = new BufferedOutput(decorated: true);
    Termwind\renderUsing($output);
    Termwind\render($html);
    expect($output->fetch())->toContain($badge, "\e");
    if ($badge === 'BLOCKED') {
        expect($html)->not->toContain('WOULD BLOCK');
    }
})->with(fn(): array => [
    [['blocked' => true, 'would_block' => true], 'BLOCKED', Theme::badge(Theme::BLOCKED)],
    [['blocked' => false, 'would_block' => true], 'WOULD BLOCK', Theme::badge(Theme::WOULD_BLOCK)],
    [['blocked' => 'true', 'would_block' => 1], 'WATCH', Theme::badge(Theme::WATCH)],
]);

it('maps severity using only allowed words', function (string $severity, string $label, string $colour): void {
    $html = (new EventFormatter())->format(['severity' => $severity]);
    expect($html)->toContain($label, $colour);
    $output = new BufferedOutput(decorated: true);
    Termwind\renderUsing($output);
    Termwind\render($html);
    expect($output->fetch())->toContain($label, "\e");
})->with(fn(): array => [
    ['low', 'LOW', Theme::badge(Theme::LOW)],
    ['medium', 'MEDIUM', Theme::badge(Theme::MEDIUM)],
    ['high', 'HIGH', Theme::badge(Theme::HIGH)],
    ['critical', 'CRITICAL', Theme::badge(Theme::CRITICAL)],
    ['<b>unknown</b>', '-', ''],
]);

it('renders missing and null fields as placeholders without printing', function (): void {
    ob_start();
    $html = (new EventFormatter())->format(['ts' => null, 'path' => null]);
    $printed = ob_get_clean();
    $output = new BufferedOutput(decorated: false);
    Termwind\renderUsing($output);
    Termwind\render($html);
    expect($printed)->toBe('')
        ->and($output->fetch())->toContain('- WATCH', 'rule -', 'tenant -', 'method -');
});

it('renders attack strings as harmless visible text', function (): void {
    $output = new BufferedOutput(decorated: false);
    Termwind\renderUsing($output);
    $html = (new EventFormatter())->format([
        'path' => "/\e[2J<span>x</span>",
        'ip' => '<b>ip</b>', 'rule' => '<b>rule</b>',
        'tenant' => "tenant\r\n", 'method' => '<script>GET</script>',
    ], 160);
    expect($html)->not->toContain("\e", '<span>x</span>', '<script>GET</script>');
    Termwind\render($html);
    expect($output->fetch())->toContain('/[2J<span>x</span>', '<b>ip</b>', '<b>rule</b>');
});

it('keeps truncated paths valid UTF-8 and within terminal width', function (): void {
    $output = new BufferedOutput(decorated: false);
    Termwind\renderUsing($output);
    Termwind\render((new EventFormatter())->format(['path' => '/' . str_repeat('界é', 100)], 60));
    $text = $output->fetch();
    expect(mb_check_encoding($text, 'UTF-8'))->toBeTrue()->and($text)->toContain('…');
    foreach (explode("\n", $text) as $line) {
        expect(mb_strwidth($line, 'UTF-8'))->toBeLessThanOrEqual(60);
    }
});

it('uses local time for event timestamps', function (): void {
    $old = date_default_timezone_get();
    date_default_timezone_set('Asia/Tokyo');
    try {
        expect((new EventFormatter())->format(['ts' => '2026-10-21T00:00:00Z']))->toContain('09:00:00');
    } finally {
        date_default_timezone_set($old);
    }
});

it('escapes Symfony formatting tags after the Termwind HTML boundary', function (): void {
    $output = new BufferedOutput(decorated: false);
    Termwind\renderUsing($output);
    Termwind\render((new EventFormatter())->format(['path' => '/<error>literal</error>', 'rule' => '<info>literal</info>'], 160));
    expect($output->fetch())->toContain('/<error>literal</error>', '<info>literal</info>');
});
