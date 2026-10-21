<?php

declare(strict_types=1);

use Stero\Shield\CLI\TailFollower;

beforeEach(function (): void {
    $this->file = tempnam(sys_get_temp_dir(), 'shield-follow-');
});
afterEach(function (): void {
    if (file_exists($this->file)) {
        unlink($this->file);
    }
    if (file_exists($this->file . '.old')) {
        unlink($this->file . '.old');
    }
});

it('emits initial last N events then only newly completed lines', function (): void {
    file_put_contents($this->file, "{\"rule\":\"old\"}\n{\"rule\":\"last\"}\n");
    $sleeps = [];
    $follower = new TailFollower($this->file, 1, function (int $microseconds) use (&$sleeps): void {
        $sleeps[] = $microseconds;
    });
    expect(array_column(array_column($follower->poll(), 'event'), 'rule'))->toBe(['last']);
    file_put_contents($this->file, '{"rule":"new"}', FILE_APPEND);
    expect($follower->poll())->toBe([]);
    file_put_contents($this->file, "\n", FILE_APPEND);
    expect(array_column(array_column($follower->poll(), 'event'), 'rule'))->toBe(['new']);
    expect($follower->poll())->toBe([]);
    $follower->pause();
    expect($sleeps)->toBe([500_000]);
});

it('reopens on truncation during rotation', function (): void {
    file_put_contents($this->file, json_encode(['rule' => str_repeat('long', 50)]) . "\n");
    $follower = new TailFollower($this->file, 20);
    expect($follower->poll())->toHaveCount(1);
    file_put_contents($this->file, "{\"rule\":\"fresh\"}\n");
    expect(array_column(array_column($follower->poll(), 'event'), 'rule'))->toBe(['fresh']);
});

it('detects shrinkage even when the new size is beyond the last complete offset', function (): void {
    file_put_contents($this->file, "{\"rule\":\"old\"}\n" . str_repeat('x', 200));
    $follower = new TailFollower($this->file, 20);
    $follower->poll();
    file_put_contents($this->file, json_encode(['rule' => str_repeat('fresh', 15)]) . "\n");
    expect(array_column(array_column($follower->poll(), 'event'), 'rule'))->toBe([str_repeat('fresh', 15)]);
});

it('reopens a changed inode from the start rather than applying the initial limit', function (): void {
    file_put_contents($this->file, "{\"rule\":\"old\"}\n");
    $follower = new TailFollower($this->file, 1);
    $follower->poll();
    rename($this->file, $this->file . '.old');
    file_put_contents($this->file, "{\"rule\":\"first\"}\n{\"rule\":\"second\"}\n");
    expect(array_column(array_column($follower->poll(), 'event'), 'rule'))->toBe(['first', 'second']);
});

it('waits for a missing file and keeps an initial partial line', function (): void {
    unlink($this->file);
    $follower = new TailFollower($this->file, 20);
    expect($follower->poll())->toBe([])->and($follower->waiting)->toBeTrue();
    file_put_contents($this->file, '{"rule":"new"}');
    expect($follower->poll())->toBe([]);
    file_put_contents($this->file, "\n", FILE_APPEND);
    expect(array_column(array_column($follower->poll(), 'event'), 'rule'))->toBe(['new'])
        ->and($follower->waiting)->toBeFalse();
});

it('resumes at a byte offset after raw multibyte events without phantom skipped lines', function (): void {
    file_put_contents($this->file, json_encode(['path' => '/' . str_repeat('界é', 5000)], JSON_UNESCAPED_UNICODE) . "\n");
    $follower = new TailFollower($this->file, 20);
    expect($follower->poll())->toHaveCount(1);
    expect($follower->poll())->toBe([])->and($follower->reader->skipped)->toBe(0);
    file_put_contents($this->file, "{\"rule\":\"new\"}\n", FILE_APPEND);
    expect(array_column(array_column($follower->poll(), 'event'), 'rule'))->toBe(['new']);
});
