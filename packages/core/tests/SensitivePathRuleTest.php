<?php

declare(strict_types=1);

use Stero\Shield\Http\NormalizedRequest;
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
        ->and(mb_strlen($finding->evidence, 'UTF-8'))->toBeLessThanOrEqual(200);
})->with(['/.env', '/nested/.GIT/config', '/vendor/phpunit/run', '/wp-admin/', '/' . str_repeat('é', 200) . '/.env']);

it('ignores normal paths and invalid UTF-8', function (string $path): void {
    expect((new SensitivePathRule())->inspect(new NormalizedRequest('GET', $path, [], [], '', false, '203.0.113.9')))->toBeNull();
})->with(['/', '/.well-known/acme-challenge/test', '/.well-known/security.txt', '/docs/.environment', '/docs/wp-admin-guide', '/mywp-admin', "/bad\xff/.env"]);

it('honours custom needles', function (): void {
    $rule = new SensitivePathRule(['private']);
    expect($rule->inspect(new NormalizedRequest('GET', '/private', [], [], '', false, '203.0.113.9')))->not->toBeNull()
        ->and($rule->inspect(new NormalizedRequest('GET', '/.env', [], [], '', false, '203.0.113.9')))->toBeNull();
});
