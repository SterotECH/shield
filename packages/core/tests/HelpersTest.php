<?php

declare(strict_types=1);

use Stero\Shield\Decision;
use Stero\Shield\Finding;
use Stero\Shield\Http\NormalizedRequest;
use Stero\Shield\Rules\Severity;

it('ranks each severity', function (Severity $severity, int $rank): void {
    expect($severity->rank())->toBe($rank);
})->with([
    [Severity::Low, 1],
    [Severity::Medium, 2],
    [Severity::High, 3],
    [Severity::Critical, 4],
]);

it('has no findings or highest severity when allowed', function (): void {
    $decision = Decision::allow();

    expect($decision->highestSeverity())->toBeNull()
        ->and($decision->hasFindings())->toBeFalse();
});

it('finds the highest severity regardless of finding order', function (array $severities, Severity $highest): void {
    $findings = array_map(
        fn(Severity $severity): Finding => new Finding('test', $severity, 'Test finding'),
        $severities,
    );
    $decision = new Decision($findings, false, false);

    expect($decision->highestSeverity())->toBe($highest)
        ->and($decision->hasFindings())->toBeTrue();
})->with([
    [[Severity::Low], Severity::Low],
    [[Severity::Medium, Severity::High, Severity::Low], Severity::High],
    [[Severity::Critical, Severity::Low, Severity::Critical], Severity::Critical],
]);

it('looks up headers without regard to name casing', function (): void {
    $request = new NormalizedRequest('GET', '/', [], ['user-agent' => 'Scanner', 'x-empty' => ''], '', false, '203.0.113.9');

    expect($request->header('user-agent'))->toBe('Scanner')
        ->and($request->header('UsEr-AgEnT'))->toBe('Scanner')
        ->and($request->header('missing'))->toBeNull()
        ->and($request->header('X-Empty'))->toBe('');
});

it('flattens query parameters for scanning', function (array $query, string $expected): void {
    $request = new NormalizedRequest('GET', '/', $query, [], '', false, '203.0.113.9');

    expect($request->queryString())->toBe($expected);
})->with([
    [[], ''],
    [['a' => '1', 'b' => 'x'], 'a=1&b=x'],
    [['a' => ['b' => '1']], 'a[b]=1'],
    [['a' => ['b' => ['1', '2']], 'empty' => [], 'q' => 'a b'], 'a[b][0]=1&a[b][1]=2&q=a b'],
]);
