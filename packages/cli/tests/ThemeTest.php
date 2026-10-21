<?php

declare(strict_types=1);

use Stero\Shield\CLI\Application;
use Stero\Shield\CLI\Renderer;
use Stero\Shield\CLI\Theme;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

it('gives every named badge pair WCAG AA contrast of at least 4.5', function (): void {
    $luminance = function (string $colour): float {
        $hex = match ($colour) {
            'white' => '#ffffff', 'black' => '#000000',
            default => constant(Termwind\Enums\Color::class . '::' . mb_strtoupper(str_replace('-', '_', $colour), 'UTF-8')),
        };
        $channels = [];
        foreach ([1, 3, 5] as $offset) {
            $value = hexdec(mb_substr($hex, $offset, 2, 'UTF-8')) / 255;
            $channels[] = $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    };
    expect(Theme::PAIRS)->not->toBeEmpty();
    foreach (Theme::PAIRS as $name => $pair) {
        $fg = $luminance($pair['foreground']);
        $bg = $luminance($pair['background']);
        $ratio = (max($fg, $bg) + 0.05) / (min($fg, $bg) + 0.05);
        $this->assertGreaterThanOrEqual(4.5, $ratio, 'Theme pair ' . $name . ' must meet 4.5:1');
    }
});

it('parses COLORFGBG without terminal queries', function (string $value, string $expected): void {
    expect(Theme::resolve('auto', $value))->toBe($expected);
})->with([['15;0', 'dark'], ['0;15', 'light'], ['default;default', 'neutral'], ['', 'neutral'], ['garbage', 'neutral'], ['15;8', 'dark'], ['0;7', 'light']]);

it('keeps plain theme completely free of ESC bytes', function (): void {
    $output = new BufferedOutput(decorated: true);
    $renderer = new Renderer($output, 80, 'plain');
    $renderer->header('events.jsonl', 1, 'detect');
    $renderer->event(['blocked' => true, 'severity' => 'critical', 'path' => '/.env']);
    expect($output->fetch())->toContain('BLOCKED', 'CRITICAL')->not->toContain("\e");
});

it('lets the theme option beat the environment and rejects invalid themes', function (): void {
    $old = getenv('SHIELD_THEME');
    putenv('SHIELD_THEME=garbage');
    try {
        $output = new BufferedOutput(decorated: true);
        expect((new Application())->run(new ArrayInput(['command' => 'list', '--theme' => 'plain', '--ansi' => true]), $output))->toBe(0);
        expect($output->fetch())->not->toContain("\e");
        expect((new Application())->run(new ArrayInput(['command' => 'events']), $output))->toBe(1);
        expect($output->fetch())->toContain('Invalid --theme');
        expect((new Application())->run(new ArrayInput(['command' => 'events', '--theme' => 'wrong']), $output))->toBe(1);
        expect($output->fetch())->toContain('Invalid --theme');
    } finally {
        $old === false ? putenv('SHIELD_THEME') : putenv('SHIELD_THEME=' . $old);
    }
});

it('has no hard-coded colour classes outside Theme', function (): void {
    $pattern = '/(?:bg|text|border)-(?:\[|black\b|white\b|(?:gray|slate|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d)/';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../src', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->isFile() && $file->getFilename() !== 'Theme.php') {
            $this->assertSame(0, preg_match($pattern, file_get_contents($file->getPathname())), 'Hard-coded colour in ' . $file->getFilename());
        }
    }
});
