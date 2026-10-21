<?php

declare(strict_types=1);

use Stero\Shield\CLI\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    $this->file = tempnam(sys_get_temp_dir(), 'shield-command-');
    $this->oldEnv = getenv('SHIELD_EVENTS');
    putenv('SHIELD_EVENTS=' . $this->file);
    $this->events = [];
    for ($i = 0; $i < 60; $i++) {
        $this->events[] = ['ts' => date(DATE_ATOM), 'rule' => 'rule-' . $i,
            'ip' => '203.0.113.9', 'tenant' => 'school', 'path' => '/path-' . $i,
            'severity' => $i % 2 === 0 ? 'high' : 'low', 'mode' => 'detect', 'would_block' => true];
    }
    file_put_contents($this->file, implode("\n", array_map(json_encode(...), $this->events)) . "\n");
});
afterEach(function (): void {
    unlink($this->file);
    $this->oldEnv === false ? putenv('SHIELD_EVENTS') : putenv('SHIELD_EVENTS=' . $this->oldEnv);
});

it('shows the default last 50 matches newest last using the environment file', function (): void {
    $output = new BufferedOutput(decorated: false);
    $code = (new Application())->run(new ArrayInput(['command' => 'events']), $output);
    $text = $output->fetch();
    expect($code)->toBe(0)->and($text)->not->toContain('rule rule-9 |')
        ->and($text)->toContain('rule rule-10 |', 'rule rule-59 |')
        ->and(mb_strpos($text, 'rule rule-10 |', 0, 'UTF-8'))->toBeLessThan(mb_strpos($text, 'rule rule-59 |', 0, 'UTF-8'));
});

it('honours file override, filters and limit with exact raw JSON output', function (): void {
    putenv('SHIELD_EVENTS=/missing');
    file_put_contents($this->file, "broken\n", FILE_APPEND);
    $output = new BufferedOutput(decorated: true);
    $code = (new Application())->run(new ArrayInput(['command' => 'events', '--file' => $this->file,
        '--severity' => 'high', '--ip' => '203.0.113.9', '--tenant' => 'school', '--would-block' => true,
        '--since' => '15m', '--limit' => '2', '--json' => true]), $output);
    expect($code)->toBe(0)->and($output->fetch())->toBe(json_encode($this->events[56]) . "\n" . json_encode($this->events[58]) . "\n");
});

it('reports invalid options and unreadable files as exit 1 without traces', function (array $options, string $message): void {
    $output = new BufferedOutput(decorated: false);
    $code = (new Application())->run(new ArrayInput(['command' => 'events', ...$options]), $output);
    expect($code)->toBe(1)->and($output->fetch())->toContain($message)->not->toContain('Stack trace', '#0', "\e");
})->with([
    [['--since' => 'nonsense'], 'Invalid --since'], [['--limit' => '-1'], 'Invalid --limit'],
    [['--severity' => 'urgent'], 'Invalid --severity'], [['--file' => '/does-not-exist'], 'Cannot read event file'],
    [['--unknown-option' => true], 'does not exist'],
]);

it('prints skipped counts and respects no-ansi', function (): void {
    file_put_contents($this->file, "invalid\nbad\nnope\n", FILE_APPEND);
    $output = new BufferedOutput(decorated: true);
    expect((new Application())->run(new ArrayInput(['command' => 'events', '--no-ansi' => true, '--limit' => '1']), $output))->toBe(0);
    expect($output->fetch())->toContain('3 lines skipped')->not->toContain("\e");
});

it('uses a 24 hour stats window by default and raw matches for stats json', function (): void {
    file_put_contents($this->file, json_encode(['ts' => '2000-01-01T00:00:00Z']) . "\n", FILE_APPEND);
    $output = new BufferedOutput(decorated: false);
    expect((new Application())->run(new ArrayInput(['command' => 'stats']), $output))->toBe(0);
    expect($output->fetch())->toContain('Total events: 60');
    expect((new Application())->run(new ArrayInput(['command' => 'stats', '--json' => true]), $output))->toBe(0);
    $lines = explode("\n", mb_substr($output->fetch(), 0, -1, 'UTF-8'));
    expect($lines)->toHaveCount(60);
    foreach ($lines as $line) {
        expect(json_decode($line, true, 512, JSON_THROW_ON_ERROR))->toBeArray();
    }
});

it('uses the relative default filename and renders empty files', function (): void {
    $cwd = getcwd();
    $dir = sys_get_temp_dir() . '/shield-default-' . bin2hex(random_bytes(6));
    mkdir($dir);
    file_put_contents($dir . '/events.jsonl', '');
    putenv('SHIELD_EVENTS');
    chdir($dir);
    try {
        $output = new BufferedOutput();
        expect((new Application())->run(new ArrayInput(['command' => 'stats']), $output))->toBe(0);
        expect($output->fetch())->toContain('No events');
    } finally {
        chdir($cwd);
        unlink($dir . '/events.jsonl');
        rmdir($dir);
    }
});

it('emits no ESC bytes when the actual binary is piped even with ansi requested', function (): void {
    $process = new Symfony\Component\Process\Process([PHP_BINARY, __DIR__ . '/../bin/shield', 'events', '--file', $this->file, '--ansi', '--limit=1']);
    $process->run();
    expect($process->getExitCode())->toBe(0)->and($process->getOutput())->toContain('SHIELD')->not->toContain("\e");
});

it('follows appended events with a normal exit on interrupt', function (): void {
    $process = new Symfony\Component\Process\Process([PHP_BINARY, __DIR__ . '/../bin/shield', 'tail', '--file', $this->file, '--lines=1', '--json'], timeout: 5);
    $process->start();
    try {
        expect($process->waitUntil(fn(string $type, string $text): bool => mb_strpos($text, 'rule-59', 0, 'UTF-8') !== false))->toBeTrue();
        file_put_contents($this->file, "{\"rule\":\"appended\"}\n", FILE_APPEND);
        expect($process->waitUntil(fn(string $type, string $text): bool => mb_strpos($text, 'appended', 0, 'UTF-8') !== false))->toBeTrue();
        $process->signal(2);
        $process->wait();
        expect($process->getExitCode())->toBe(0)->and($process->getOutput())->not->toContain("\e");
    } finally {
        $process->stop();
    }
});

it('announces a missing tail file once and then shows the first event', function (): void {
    $path = $this->file . '.missing';
    $process = new Symfony\Component\Process\Process([PHP_BINARY, __DIR__ . '/../bin/shield', 'tail', '--file', $path], timeout: 5);
    $process->start();
    try {
        expect($process->waitUntil(fn(string $type, string $text): bool => mb_strpos($text, 'Waiting for', 0, 'UTF-8') !== false))->toBeTrue();
        usleep(600_000);
        file_put_contents($path, "{\"rule\":\"arrived\"}\n");
        expect($process->waitUntil(fn(string $type, string $text): bool => mb_strpos($text, 'arrived', 0, 'UTF-8') !== false))->toBeTrue();
        $process->signal(2);
        $process->wait();
        expect(mb_substr_count($process->getOutput(), 'Waiting for', 'UTF-8'))->toBe(1);
    } finally {
        $process->stop();
        if (file_exists($path)) {
            unlink($path);
        }
    }
});

it('reports binary startup failures without a trace or silent success', function (bool $hasAutoloader, string $message): void {
    $dir = sys_get_temp_dir() . '/shield-bootstrap-' . bin2hex(random_bytes(6));
    mkdir($dir);
    mkdir($dir . '/bin');
    file_put_contents($dir . '/bin/shield', file_get_contents(__DIR__ . '/../bin/shield'));
    if ($hasAutoloader) {
        mkdir($dir . '/vendor');
        file_put_contents($dir . '/vendor/autoload.php', '<?php declare(strict_types=1); namespace Stero\\Shield\\CLI; final class Application { public function __construct() { throw new \\RuntimeException("startup failed"); } }');
    }
    try {
        $process = new Symfony\Component\Process\Process([PHP_BINARY, $dir . '/bin/shield']);
        $process->run();
        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain($message)->not->toContain('Stack trace', 'Fatal error', '#0', "\e");
    } finally {
        if ($hasAutoloader) {
            unlink($dir . '/vendor/autoload.php');
            rmdir($dir . '/vendor');
        }
        unlink($dir . '/bin/shield');
        rmdir($dir . '/bin');
        rmdir($dir);
    }
})->with([[false, 'composer install'], [true, 'Unable to start Shield']]);
