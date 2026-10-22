import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync, readFileSync, rmSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';

function run(stage, failure = '') {
    const directory = mkdtempSync(join(tmpdir(), 'shield-quality-'));
    const log = join(directory, 'calls');
    writeFileSync(join(directory, 'composer'), '#!/bin/sh\nprintf "%s\\n" "$*" >> "$CALLS"\nif [ "$*" = check ] && [ "${NO_COLOR+x}" = x ]; then exit 24; fi\ncase "$*" in *"$FAIL_COMMAND"*) [ -z "$FAIL_COMMAND" ] || exit 23 ;; esac\n', { mode: 0o755 });
    try {
        const result = spawnSync('sh', ['scripts/quality.sh', stage], {
            env: { ...process.env, CI: 'true', NO_COLOR: '1', PATH: `${directory}:${process.env.PATH}`, CALLS: log, FAIL_COMMAND: failure },
            encoding: 'utf8',
        });
        return { ...result, calls: existsSync(log) ? readFileSync(log, 'utf8') : '' };
    } finally {
        rmSync(directory, { recursive: true, force: true });
    }
}

test('commit checks every manifest and formatting without running the full suite', () => {
    const result = run('commit');
    assert.equal(result.status, 0, result.stderr);
    assert.equal(result.calls.match(/validate --strict/g)?.length, 6);
    assert.equal(result.calls.match(/lint:check/g)?.length, 5);
    assert.doesNotMatch(result.calls, /audit|^check$/m);
});

test('push clears inherited NO_COLOR, runs the full suite and audits every dependency graph', () => {
    const result = run('push');
    assert.equal(result.status, 0, result.stderr);
    assert.match(result.calls, /^check$/m);
    assert.equal(result.calls.match(/audit --locked/g)?.length, 6);
});

for (const command of ['validate --strict', 'lint:check', 'check', 'audit --locked']) {
    test(`a failed ${command} blocks the hook`, () => {
        assert.equal(run(command === 'lint:check' ? 'commit' : 'push', command).status, 23);
    });
}
