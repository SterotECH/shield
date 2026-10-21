<?php

declare(strict_types=1);

use Stero\Shield\Engine;
use Stero\Shield\EngineConfig;
use Stero\Shield\Events\InMemorySink;
use Stero\Shield\Http\RequestFactory;
use Stero\Shield\Mode;
use Stero\Shield\Rules\Phase;
use Stero\Shield\Rules\Rule;
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

/** @return list<Rule> */
function allRules(): array
{
    return [new SensitivePathRule()];
}

/** @return array<string, array{string, string}> */
function corpusFiles(): array
{
    $cases = [];
    foreach (['attacks', 'normal'] as $kind) {
        $files = glob(__DIR__ . '/../../../tests/corpus/' . $kind . '/*.json');
        foreach ($files ?: [] as $file) {
            $cases[$kind . '/' . basename($file)] = [$kind, $file];
        }
    }

    return $cases;
}

it('GUARD has attack and normal corpus files', function (): void {
    $files = corpusFiles();
    expect($files)->not->toBeEmpty();
    $kinds = array_column($files, 0);
    expect($kinds)->toContain('attacks', 'normal');
});

dataset('request corpus', fn(): array => corpusFiles());

it('STRUCTURE validates each corpus file', function (string $kind, string $file): void {
    $case = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    expect($case)->toBeArray()->toHaveKeys(['description', 'request', 'expect']);
    expect($case['description'])->toBeString()->not->toBeEmpty();
    expect($case['request'])->toHaveKeys(['method', 'ip']);
    expect($case['request']['method'])->toBeString();
    expect($case['request']['ip'])->toBeString();
    expect(isset($case['request']['path']) || isset($case['request']['path_b64']))->toBeTrue();
    foreach (['path', 'path_b64'] as $field) {
        if (isset($case['request'][$field])) {
            expect($case['request'][$field])->toBeString();
        }
    }
    if (isset($case['request']['path_b64'])) {
        expect(base64_decode($case['request']['path_b64'], true))->not->toBeFalse();
    }
    expect($case['expect']['rules'])->toBeArray();
    foreach ($case['expect']['rules'] as $id) {
        expect($id)->toBeString()->not->toBeEmpty();
    }
    if (array_key_exists('would_block', $case['expect'])) {
        expect($case['expect']['would_block'])->toBeBool();
    }
})->with('request corpus');

it('COVERAGE names every installed rule in an attack', function (): void {
    $covered = [];
    foreach (corpusFiles() as [$kind, $file]) {
        if ($kind === 'attacks') {
            $case = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            array_push($covered, ...$case['expect']['rules']);
        }
    }
    foreach (allRules() as $rule) {
        expect($covered)->toContain($rule->id());
    }
    $installed = [];
    foreach (glob(__DIR__ . '/../src/Rules/*.php') ?: [] as $file) {
        $class = 'Stero\\Shield\\Rules\\' . basename($file, '.php');
        if (is_subclass_of($class, Rule::class)) {
            $installed[] = $class;
        }
    }
    expect(array_map(get_class(...), allRules()))->toEqualCanonicalizing($installed);
});

it('matches the saved request expectations in both phases', function (string $kind, string $file): void {
    $case = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $input = $case['request'];
    $path = isset($input['path_b64']) ? base64_decode($input['path_b64'], true) : ($input['path'] ?? '/');
    $query = $input['query'] ?? [];
    $server = [
        'REQUEST_METHOD' => $input['method'],
        'REQUEST_URI' => $path . ($query === [] ? '' : '?' . http_build_query($query)),
        'REMOTE_ADDR' => $input['ip'],
    ];
    foreach ($input['headers'] ?? [] as $name => $value) {
        expect(mb_check_encoding($name, 'UTF-8'))->toBeTrue();
        $key = mb_strtoupper(str_replace('-', '_', $name), 'UTF-8');
        $server['HTTP_' . $key] = $value;
    }
    $request = RequestFactory::fromGlobals($server, $query, $input['body'] ?? '');
    if (isset($input['path_b64']) && !mb_check_encoding(base64_decode($input['path_b64'], true), 'UTF-8')) {
        expect(mb_check_encoding($request->path, 'UTF-8'))->toBeFalse();
    }
    $store = new ArrayStore();
    $sink = new InMemorySink();
    $engine = new Engine(allRules(), $store, $sink, new EngineConfig(Mode::Detect));
    $findings = [];
    $wouldBlock = false;
    foreach ([Phase::Inline, Phase::Deferred] as $phase) {
        $decision = $engine->inspect($request, $phase);
        expect($decision->blocked)->toBeFalse();
        array_push($findings, ...$decision->findings);
        $wouldBlock = $wouldBlock || $decision->wouldBlock;
    }
    if (array_key_exists('would_block', $case['expect'])) {
        expect($wouldBlock)->toBe($case['expect']['would_block']);
    }
    $ruleIds = array_map(fn($finding): string => $finding->ruleId, $findings);
    if ($kind === 'attacks') {
        expect($case['expect']['rules'])->not->toBeEmpty();
        foreach ($case['expect']['rules'] as $id) {
            $this->assertContains($id, $ruleIds, $file);
        }
    } else {
        expect($case['expect']['rules'])->toBe([])
            ->and($findings)->toBe([]);
    }
    expect($sink->events())->toHaveCount(count($findings))
        ->and($store->isBanned($request->clientIp))->toBeFalse();
})->with('request corpus');
