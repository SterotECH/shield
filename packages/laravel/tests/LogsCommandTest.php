<?php

declare(strict_types=1);

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\SearchPrompt;
use Laravel\Prompts\SelectPrompt;
use Stero\Shield\Runtime\Settings;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->eventsFile = tempnam(sys_get_temp_dir(), 'shield-logs-');
    app()->instance(Settings::class, Settings::fromArray(['events_path' => $this->eventsFile]));
    $events = [];
    for ($i = 0; $i < 50; $i++) {
        $events[] = json_encode(['ts' => sprintf('2026-10-22T12:00:%02dZ', $i), 'ip' => '192.0.2.1', 'method' => 'GET', 'path' => '/.env', 'rule' => 'sensitive-path', 'tenant' => 'school', 'user' => null, 'severity' => 'high', 'blocked' => false, 'would_block' => true, 'mode' => 'detect', 'message' => 'Probe', 'evidence' => '/.env']);
    }
    file_put_contents($this->eventsFile, implode("\n", $events)."\n");
    putenv('COLUMNS=80');
});

afterEach(function (): void {
    unlink($this->eventsFile);
    putenv('COLUMNS');
    putenv('NO_COLOR');
});

it('renders fifteen rows on page one of four with constant facets in the footer', function (): void {
    expect(Artisan::call('shield:logs', ['--no-interaction' => true]))->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('page 1 of 4', 'rows 1-15', '50 events', 'rule sensitive-path', 'tenant school', 'path=any')
        ->not->toContain('RULE', 'TENANT', "\e", 'Next page')
        ->and(mb_substr_count($output, '/.env', 'UTF-8'))->toBe(15);
    $border = array_values(array_filter(explode("\n", $output), fn (string $line): bool => str_starts_with($line, '┌')))[0];
    expect(mb_strwidth($border, 'UTF-8'))->toBe(80);
});

it('groups fifty probes as one incident with a count and time bounds', function (): void {
    expect(Artisan::call('shield:logs', ['--group' => true, '--no-interaction' => true]))->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('COUNT', '50', 'first seen', 'last seen')
        ->and(mb_substr_count($output, '/.env', 'UTF-8'))->toBe(1);
});

it('exports an unpaginated JSON array preserving original event keys', function (): void {
    expect(Artisan::call('shield:logs', ['--json' => true, '--group' => true]))->toBe(0);
    $events = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($events)->toHaveCount(50)->and($events[0]['ts'])->toBe('2026-10-22T12:00:49Z')
        ->and($events[0])->toHaveKeys(['would_block', 'blocked', 'mode', 'evidence', 'message', 'user'])
        ->and(Artisan::output())->not->toContain("\e", 'Next page');
});

it('reports empty matches with active filters', function (): void {
    expect(Artisan::call('shield:logs', ['--ip' => '203.0.113.99', '--no-interaction' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('No events match', '203.0.113.99');
});

it('keeps ordinary output within eighty display columns', function (): void {
    $event = json_decode(explode("\n", file_get_contents($this->eventsFile))[0], true);
    $event['path'] = '/'.str_repeat('界', 100).'/tail.env';
    file_put_contents($this->eventsFile, json_encode($event));
    expect(Artisan::call('shield:logs', ['--no-interaction' => true]))->toBe(0);
    $output = Artisan::output();
    foreach (explode("\n", $output) as $line) {
        expect(mb_strwidth($line, 'UTF-8'))->toBeLessThanOrEqual(80);
    }
    expect($output)->toContain('…', 'il.env');
});

it('sanitises full paths before output even when colour is forced', function (): void {
    $event = json_decode(explode("\n", file_get_contents($this->eventsFile))[0], true);
    $event['path'] = "/start\e\u{202E}\u{2066}<error>/end";
    file_put_contents($this->eventsFile, json_encode($event));
    putenv('NO_COLOR=1');
    expect(Artisan::call('shield:logs', ['--full' => true, '--ansi' => true, '--no-interaction' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('/start<error>/end')->not->toContain("\e", "\u{202E}", "\u{2066}", 'Next page');
});

it('reports invalid options without throwing', function (array $options, string $message): void {
    expect(Artisan::call('shield:logs', $options))->toBe(1)->and(Artisan::output())->toContain($message);
})->with([
    [['--since' => 'yesterday'], 'Invalid --since'], [['--page' => 'x'], 'Invalid --page'],
    [['--per-page' => '1.5'], 'Invalid --per-page'], [['--verdict' => 'maybe'], 'Invalid --verdict'],
    [['--severity' => 'huge'], 'Invalid --severity'],
]);

it('reports missing files clearly', function (): void {
    expect(Artisan::call('shield:logs', ['--file' => $this->eventsFile.'.missing']))->toBe(1)
        ->and(Artisan::output())->toContain('Cannot read event file');
});

it('retains complete long paths on a single row in full mode', function (): void {
    $event = json_decode(explode("\n", file_get_contents($this->eventsFile))[0], true);
    $event['path'] = '/'.str_repeat('long', 40).'/tail.env';
    file_put_contents($this->eventsFile, json_encode($event));
    expect(Artisan::call('shield:logs', ['--full' => true, '--no-interaction' => true]))->toBe(0)
        ->and(Artisan::output())->toContain($event['path'], '12:00')->not->toContain('METHOD', '12:00:00');
});

it('sanitises every displayed request field and preserves markup literally', function (): void {
    $event = json_decode(explode("\n", file_get_contents($this->eventsFile))[0], true);
    foreach (['ip', 'rule', 'tenant', 'method', 'mode', 'path'] as $field) {
        $event[$field] = "x\e\u{202E}\u{2069}<info>y";
    }
    file_put_contents($this->eventsFile, json_encode($event));
    expect(Artisan::call('shield:logs', ['--no-interaction' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('x<info>y')->not->toContain("\e", "\u{202E}", "\u{2069}");
});

it('shows varying rule and tenant only on wide terminals', function (): void {
    $event = json_decode(explode("\n", file_get_contents($this->eventsFile))[0], true);
    $event['rule'] = 'other';
    $event['tenant'] = 'other';
    $event['ts'] = '2026-10-22T13:00:00Z';
    file_put_contents($this->eventsFile, json_encode($event)."\n", FILE_APPEND);
    putenv('COLUMNS=140');
    Artisan::call('shield:logs', ['--no-interaction' => true]);
    expect(Artisan::output())->toContain('RULE', 'TENANT');
    putenv('COLUMNS=80');
    Artisan::call('shield:logs', ['--no-interaction' => true]);
    expect(Artisan::output())->not->toContain('RULE', 'TENANT');
});

it('applies a selected facet value rather than treating its label as a number', function (): void {
    Artisan::call('shield:logs', ['--no-interaction' => true]);
    $command = Artisan::all()['shield:logs'];
    Prompt::fallbackWhen(true);
    SelectPrompt::fallbackUsing(fn () => 'ip');
    SearchPrompt::fallbackUsing(function ($prompt) {
        $options = ($prompt->options)('');
        $key = array_keys($options)[1];

        return array_is_list($options) ? $options[$key] : $key;
    });
    $events = [json_decode(explode("\n", file_get_contents($this->eventsFile))[0], true)];
    $filters = (new ReflectionMethod($command, 'chooseFilter'))->invoke($command, [], $events);
    expect($filters)->toBe(['ip' => '192.0.2.1']);
});

it('inspects the selected first row including decoded and sanitised details', function (): void {
    Artisan::call('shield:logs', ['--no-interaction' => true]);
    $command = Artisan::all()['shield:logs'];
    $output = new BufferedOutput;
    $command->setOutput(new OutputStyle(new ArrayInput([]), $output));
    Prompt::fallbackWhen(true);
    SelectPrompt::fallbackUsing(fn ($prompt) => array_is_list($prompt->options) ? reset($prompt->options) : array_key_first($prompt->options));
    $event = json_decode(explode("\n", file_get_contents($this->eventsFile))[0], true);
    $event['path'] = '/%2eenv%1b%0a';
    $event['evidence'] = "<info>\e\u{202E}probe";
    (new ReflectionMethod($command, 'inspect'))->invoke($command, [$event], [$event], false);
    expect($output->fetch())->toContain('path: /%2eenv%1b%0a', 'decoded_path: /.env', 'UTC', 'evidence: <info>probe')
        ->not->toContain("\e", "\u{202E}");
});

it('runs the real TTY menu without ESC bytes under NO_COLOR', function (): void {
    $code = <<<'PHP'
require 'vendor/autoload.php';
final class LogsTTYCheck extends \Stero\Shield\Laravel\Tests\TestCase
{
    public function runViewer(): int
    {
        $this->setUp();
        app()->instance(\Stero\Shield\Runtime\Settings::class, \Stero\Shield\Runtime\Settings::fromArray([
            'events_path' => getenv('SHIELD_LOGS_TEST_FILE'),
        ]));
        return \Illuminate\Support\Facades\Artisan::call('shield:logs', [], new \Symfony\Component\Console\Output\ConsoleOutput);
    }
}
exit((new LogsTTYCheck('runViewer'))->runViewer());
PHP;
    $process = new Process([PHP_BINARY, '-r', $code], cwd: dirname(__DIR__),
        env: ['SHIELD_LOGS_TEST_FILE' => $this->eventsFile, 'NO_COLOR' => '1'], timeout: 15);
    $process->setPty(true);
    $process->setInput("0\n1\n2\n2\n192.0.2.1\nvalue_0\n3\nrow_0\n4\n5\n6\n");
    $process->run();
    $output = $process->getOutput().$process->getErrorOutput();
    expect($process->getExitCode())->toBe(0)
        ->and($output)->toContain('page 2 of 4', 'page 1 of 4', 'Filter ip', 'ip=192.0.2.1', 'Event details', 'COUNT', 'Quit')
        ->not->toContain("\e");
});
