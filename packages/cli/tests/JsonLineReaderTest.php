<?php

declare(strict_types=1);

use Stero\Shield\CLI\JsonLineReader;

beforeEach(function (): void {
    $this->file = tempnam(sys_get_temp_dir(), 'shield-reader-');
});
afterEach(function (): void {
    unlink($this->file);
});

it('reads the last complete events in chronological file order', function (int $size, int $count, array $expected): void {
    $handle = fopen($this->file, 'w');
    for ($i = 1; $i <= $size; $i++) {
        fwrite($handle, json_encode(['rule' => (string) $i]) . "\n");
    }
    fclose($handle);
    $rows = (new JsonLineReader())->last($this->file, $count);
    expect(array_column(array_column($rows, 'event'), 'rule'))->toBe($expected);
})->with([[2, 5, ['1', '2']], [3, 3, ['1', '2', '3']], [5, 2, ['4', '5']], [0, 20, []], [3, 0, []]]);

it('waits for a newline and counts malformed lines', function (): void {
    file_put_contents($this->file, "bad\n{\"rule\":\"ok\"}\nbroken\n{\"rule\":\"partial\"}");
    $reader = new JsonLineReader();
    expect(array_column(array_column($reader->last($this->file, 20), 'event'), 'rule'))->toBe(['ok'])
        ->and($reader->skipped)->toBe(2);
    file_put_contents($this->file, "\n", FILE_APPEND);
    expect(array_column(array_column($reader->last($this->file, 1), 'event'), 'rule'))->toBe(['partial']);
});

it('handles a file of only invalid lines', function (): void {
    file_put_contents($this->file, "bad\n{\n\xFF\n");
    $reader = new JsonLineReader();
    expect($reader->last($this->file, 20))->toBe([])->and($reader->skipped)->toBe(3);
});

it('keeps memory small for a 5 MB file', function (): void {
    $handle = fopen($this->file, 'w');
    $line = json_encode(['path' => '/' . str_repeat('x', 1000)]) . "\n";
    for ($i = 0; $i < 5200; $i++) {
        fwrite($handle, $line);
    }
    fclose($handle);
    $reader = new JsonLineReader();
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $rows = $reader->last($this->file, 20);
    $peak = memory_get_peak_usage() - $before;
    expect(filesize($this->file))->toBeGreaterThan(5_000_000)
        ->and($rows)->toHaveCount(20)
        ->and($peak)->toBeLessThan(1_000_000);
});

it('does not buffer a huge incomplete suffix', function (): void {
    $handle = fopen($this->file, 'w');
    for ($i = 0; $i < 5200; $i++) {
        fwrite($handle, str_repeat('x', 1000));
    }
    fclose($handle);
    $reader = new JsonLineReader();
    memory_reset_peak_usage();
    $before = memory_get_usage();
    expect($reader->last($this->file, 20))->toBe([]);
    expect(memory_get_peak_usage() - $before)->toBeLessThan(1_000_000);
});

it('never repairs malformed trailing UTF-8 into valid JSON', function (): void {
    file_put_contents($this->file, "{\"rule\":\"invalid\"}\xC2\n");
    $reader = new JsonLineReader();
    expect(iterator_to_array($reader->read($this->file)))->toBe([])->and($reader->skipped)->toBe(1);
});

it('streams complete valid raw lines and rejects unreadable paths', function (): void {
    file_put_contents($this->file, " {\"rule\":\"x\"} \ninvalid\n{\"rule\":\"y\"}");
    $reader = new JsonLineReader();
    expect(iterator_to_array($reader->read($this->file)))->toBe([
        ['raw' => ' {"rule":"x"} ', 'event' => ['rule' => 'x']],
    ])->and($reader->skipped)->toBe(1);
    expect(fn() => $reader->last($this->file . '/missing', 20))->toThrow(RuntimeException::class);
});
