<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Shield\Runtime\Settings;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->eventsFile = tempnam(sys_get_temp_dir(), 'shield-logs-');
    app()->instance(Settings::class, Settings::fromArray(['events_path' => $this->eventsFile]));
    file_put_contents($this->eventsFile, "{\"rule\":\"older\",\"severity\":\"low\"}\n{\"rule\":\"sensitive-path\",\"severity\":\"high\",\"path\":\"/.env\"}\n");
});

afterEach(function (): void {
    unlink($this->eventsFile);
});

it('reads the configured file through the shared viewer and forwards filters', function (): void {
    expect(Artisan::call('shield:logs', ['--json' => true, '--severity' => 'high', '--limit' => '2']))->toBe(0)
        ->and(Artisan::output())->toBe("{\"rule\":\"sensitive-path\",\"severity\":\"high\",\"path\":\"/.env\"}\n");
});

it('reports viewer errors with a failing exit status', function (): void {
    expect(Artisan::call('shield:logs', ['--limit' => '-1']))->toBe(1)
        ->and(Artisan::output())->toContain('Invalid --limit');
});

it('renders the existing terminal view by default', function (): void {
    expect(Artisan::call('shield:logs'))->toBe(0)
        ->and(Artisan::output())->toContain('SHIELD', 'sensitive-path');
});

it('refuses filters that cannot be applied when following', function (): void {
    expect(Artisan::call('shield:logs', ['--follow' => true, '--severity' => 'high']))->toBe(1)
        ->and(Artisan::output())->toContain('Filters cannot be used with --follow');
});

it('uses the tail viewer and forwards the initial count when following', function (): void {
    expect(Artisan::call('shield:logs', ['--follow' => true, '--limit' => '-1']))->toBe(1)
        ->and(Artisan::output())->toContain('Invalid --lines');
});

it('reports a missing configured event file', function (): void {
    app()->instance(Settings::class, Settings::fromArray(['events_path' => $this->eventsFile.'.missing']));
    expect(Artisan::call('shield:logs'))->toBe(1)
        ->and(Artisan::output())->toContain('Cannot read event file');
});

it('follows appended events through Artisan and exits cleanly on interrupt', function (): void {
    $code = <<<'PHP'
require 'vendor/autoload.php';
final class LogsBoot extends \Shield\Laravel\Tests\TestCase
{
    public function runViewer(): never
    {
        $this->setUp();
        app()->instance(\Shield\Runtime\Settings::class, \Shield\Runtime\Settings::fromArray([
            'events_path' => getenv('SHIELD_LOGS_TEST_FILE'),
        ]));
        exit(\Illuminate\Support\Facades\Artisan::call('shield:logs', [
            '--follow' => true, '--limit' => '1', '--json' => true,
        ], new \Symfony\Component\Console\Output\ConsoleOutput));
    }
}
(new LogsBoot('runViewer'))->runViewer();
PHP;
    $process = new Process([PHP_BINARY, '-r', $code],
        cwd: dirname(__DIR__), env: ['SHIELD_LOGS_TEST_FILE' => $this->eventsFile], timeout: 10);
    $process->start();
    try {
        expect($process->waitUntil(fn (string $type, string $text): bool => mb_strpos($text, 'sensitive-path', 0, 'UTF-8') !== false))->toBeTrue();
        file_put_contents($this->eventsFile, "{\"rule\":\"arrived\"}\n", FILE_APPEND);
        expect($process->waitUntil(fn (string $type, string $text): bool => mb_strpos($text, 'arrived', 0, 'UTF-8') !== false))->toBeTrue();
        $process->signal(2);
        $process->wait();
        expect($process->getExitCode())->toBe(0)
            ->and($process->getOutput())->not->toContain('older', "\e");
    } finally {
        $process->stop();
    }
});
