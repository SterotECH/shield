<?php

declare(strict_types=1);

use Stero\Shield\Engine;
use Stero\Shield\EngineConfig;
use Stero\Shield\Events\InMemorySink;
use Stero\Shield\Http\RequestFactory;
use Stero\Shield\Mode;
use Stero\Shield\Rules\Phase;
use Stero\Shield\Rules\SensitivePathRule;
use Stero\Shield\Store\ArrayStore;

/*
 * The most important test in the project.
 *
 * CORPUS FORMAT (one JSON file per request, see tests/corpus at the repo root):
 * {
 *   "description": "what this request is",
 *   "request": { "method": "GET", "path": "/.env", "query": {},
 *                "headers": {}, "body": "", "ip": "203.0.113.9" },
 *   "expect": { "rules": ["sensitive-path"] }
 * }
 * Files in attacks/ must trigger every rule listed in expect.rules.
 * Files in normal/ must trigger nothing (expect.rules is []).
 *
 * IMPLEMENTATION NOTES
 * - Corpus root from this file: __DIR__ . '/../../../tests/corpus'.
 * - Build the Engine once per case: all rules, ArrayStore, InMemorySink,
 *   EngineConfig with Mode::Detect. Run BOTH phases, merge the findings.
 * - Use a Pest dataset that reads the files, so each file is its own test
 *   and a failure names the file.
 * - Rule of the project: no rule is merged without at least one attack file
 *   and one normal file for it.
 */

dataset('request corpus', function (): iterable {
    foreach (['attacks', 'normal'] as $kind) {
        $files = glob(__DIR__ . '/../../../tests/corpus/' . $kind . '/*.json');
        if ($files === false || $files === []) {
            throw new RuntimeException('No corpus fixtures for ' . $kind);
        }
        foreach ($files as $file) {
            $contents = file_get_contents($file);
            if ($contents === false) {
                throw new RuntimeException('Cannot read ' . $file);
            }
            yield $kind . '/' . basename($file) => [$kind, json_decode($contents, true, 512, JSON_THROW_ON_ERROR)];
        }
    }
});

it('matches the saved request expectations in both phases', function (string $kind, array $case): void {
    $input = $case['request'];
    $server = [
        'REQUEST_METHOD' => $input['method'],
        'REQUEST_URI' => $input['path'],
        'REMOTE_ADDR' => $input['ip'],
    ];
    foreach ($input['headers'] as $name => $value) {
        $key = mb_strtoupper(str_replace('-', '_', $name), 'UTF-8');
        $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_' . $key] = $value;
    }
    $request = RequestFactory::fromGlobals($server, $input['query'], $input['body']);
    $store = new ArrayStore();
    $sink = new InMemorySink();
    $engine = new Engine([new SensitivePathRule()], $store, $sink, new EngineConfig(Mode::Detect));
    $findings = [];
    foreach (Phase::cases() as $phase) {
        $decision = $engine->inspect($request, $phase);
        expect($decision->blocked)->toBeFalse();
        array_push($findings, ...$decision->findings);
    }
    $ruleIds = array_map(fn($finding): string => $finding->ruleId, $findings);
    if ($kind === 'attacks') {
        expect($case['expect']['rules'])->not->toBeEmpty();
        foreach ($case['expect']['rules'] as $id) {
            expect($ruleIds)->toContain($id);
        }
    } else {
        expect($case['expect']['rules'])->toBe([])
            ->and($findings)->toBe([]);
    }
    expect($sink->events())->toHaveCount(count($findings))
        ->and($store->isBanned($request->clientIp))->toBeFalse();
})->with('request corpus');
