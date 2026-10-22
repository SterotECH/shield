import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';

// Execute the workflow's actual shell gates without contacting GitHub.
function runStep(name, overrides = {}) {
    const workflow = readFileSync('.github/workflows/release.yml', 'utf8');
    const section = workflow.split(`      - name: ${name}\n`)[1]?.split('\n      - ')[0];
    assert.ok(section, `Missing release gate: ${name}`);
    const script = section.split('        run: |\n')[1]?.replace(/^          /gm, '');
    assert.ok(script, `Missing shell command: ${name}`);
    const directory = mkdtempSync(join(tmpdir(), 'shield-release-'));
    writeFileSync(join(directory, 'gh'), `#!/bin/sh
case "$2" in
  repos/stero/shield/commits/main) echo checked-head ;;
  'repos/stero/shield/actions/workflows/ci.yml/runs?branch=main&event=push&head_sha=checked-head&status=success')
    [ "\${API_FAILURE:-0}" = 0 ] || exit 23
    echo "\${SUCCESSFUL_RUNS:-1}" ;;
  *) exit 24 ;;
esac
`, { mode: 0o755 });
    try {
        return spawnSync('sh', ['-eu', '-c', script], {
            encoding: 'utf8',
            env: { ...process.env, PATH: `${directory}:${process.env.PATH}`, GITHUB_REPOSITORY: 'stero/shield', RELEASE_PLEASE_TOKEN: '', ...overrides },
        });
    } finally {
        rmSync(directory, { recursive: true, force: true });
    }
}

test('release refuses missing credentials', () => {
    assert.notEqual(runStep('Require release automation credentials').status, 0);
});

test('release accepts credentials without printing them', () => {
    const result = runStep('Require release automation credentials', { RELEASE_PLEASE_TOKEN: 'test-value' });
    assert.equal(result.status, 0, result.stderr);
    assert.doesNotMatch(result.stdout + result.stderr, /test-value/);
});

test('release accepts successful push CI for the current main commit', () => {
    const result = runStep('Require successful CI for current main');
    assert.equal(result.status, 0, result.stderr);
});

test('release refuses a main commit without successful CI', () => {
    assert.notEqual(runStep('Require successful CI for current main', { SUCCESSFUL_RUNS: '0' }).status, 0);
});

test('release fails closed when GitHub cannot verify CI', () => {
    assert.equal(runStep('Require successful CI for current main', { API_FAILURE: '1' }).status, 23);
});
