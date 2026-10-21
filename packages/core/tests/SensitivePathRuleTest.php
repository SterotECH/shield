<?php

declare(strict_types=1);

use Stero\Shield\Http\NormalizedRequest;
use Stero\Shield\Http\RequestFactory;
use Stero\Shield\Rules\Phase;
use Stero\Shield\Rules\SensitivePathRule;
use Stero\Shield\Rules\Severity;

it('detects sensitive path segments', function (string $path): void {
    $rule = new SensitivePathRule();
    $finding = $rule->inspect(new NormalizedRequest('GET', $path, [], [], '', false, '203.0.113.9'));

    expect($rule->id())->toBe('sensitive-path')
        ->and($rule->phase())->toBe(Phase::Inline)
        ->and($finding)->not->toBeNull()
        ->and($finding->ruleId)->toBe('sensitive-path')
        ->and($finding->severity)->toBe(Severity::High)
        ->and($finding->banWorthy)->toBeTrue()
        ->and($finding->message)->toBe('Request for a sensitive file or folder')
        ->and($finding->evidence)->toBe(mb_substr(mb_scrub($path, 'UTF-8'), 0, 200, 'UTF-8'))
        ->and(json_encode($finding->evidence, JSON_THROW_ON_ERROR))->toBeString()
        ->and(mb_strlen($finding->evidence, 'UTF-8'))->toBeLessThanOrEqual(200);
})->with([
    '/.env', '/.ENV', '/.env.local', '/.env.backup', '/app/.env', '/.env/',
    "/.env\0.jpg", '/\\.env', '/a/.env/b', '/.git/config', '/.git/HEAD',
    '/wp-login.php', '/wp-admin/setup.php', '/phpmyadmin/index.php',
    '/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php', '/storage/logs/laravel.log',
    '/backup.sql', '/dump.sql', '/.DS_Store', "/\xFF/.env", "/\xFF/.ENV",
    '/nested/.GIT/config', '/vendor/phpunit/run', '/wp-admin/',
    '/' . str_repeat('é', 200) . '/.env', '/a//vendor///phpunit/run',
]);

it('ignores normal paths', function (string $path): void {
    expect((new SensitivePathRule())->inspect(new NormalizedRequest('GET', $path, [], [], '', false, '203.0.113.9')))->toBeNull();
})->with([
    '/', '/.well-known/acme-challenge/abc', '/.well-known/security.txt',
    '/environment', '/envelope', '/.environment', '/gitlab-guide',
    '/blog/what-is-env', '/storage/logo.png', '/api/logs', '/vendor/phpunit-report',
    '/students/env', '/.github-guide', '/docs/.environment', '/docs/wp-admin-guide',
    '/mywp-admin', "/bad\xff/normal", '/storage/other/logs', '/%2eenv',
]);

it('honours custom needles', function (): void {
    $rule = new SensitivePathRule(['custom.txt']);
    expect($rule->inspect(new NormalizedRequest('GET', '/custom.txt', [], [], '', false, '203.0.113.9')))->not->toBeNull()
        ->and($rule->inspect(new NormalizedRequest('GET', '/.env', [], [], '', false, '203.0.113.9')))->toBeNull();
});

it('matches only the path regardless of other request fields', function (): void {
    $rule = new SensitivePathRule();
    expect($rule->inspect(new NormalizedRequest('POST', '/normal', ['q' => '/.env'], ['x-path' => '/.git'], '/backup.sql', false, '203.0.113.9')))->toBeNull()
        ->and($rule->inspect(new NormalizedRequest('OPTIONS', '/.env', [], [], '', false, '203.0.113.9')))->not->toBeNull();
});

it('uses byte-wise lowercasing when the path is invalid UTF-8', function (): void {
    $rule = new SensitivePathRule(['ä']);
    expect($rule->inspect(new NormalizedRequest('GET', "/\xff/Ä", [], [], '', false, '203.0.113.9')))->toBeNull();
});

it('matches this rule against the corpus', function (): void {
    $rule = new SensitivePathRule();
    foreach (['attacks', 'normal'] as $kind) {
        $files = glob(__DIR__ . '/../../../tests/corpus/' . $kind . '/*.json');
        expect($files)->not->toBeEmpty();
        foreach ($files as $file) {
            $case = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            if ($kind === 'attacks' && !in_array('sensitive-path', $case['expect']['rules'], true)) {
                continue;
            }
            $input = $case['request'];
            $request = RequestFactory::fromGlobals(['REQUEST_URI' => $input['path']], [], '');
            $finding = $rule->inspect($request);
            if ($kind === 'attacks') {
                expect($finding, $file)->not->toBeNull();
            } else {
                expect($finding, $file)->toBeNull();
            }
        }
    }
});
