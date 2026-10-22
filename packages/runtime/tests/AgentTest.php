<?php

declare(strict_types=1);

use Stero\Shield\Rules\SensitivePathRule;
use Stero\Shield\Runtime\Agent\AgentFilesPublisher;
use Stero\Shield\Runtime\Agent\AgentTarget;

function agentRemove(string $path): void
{
    if (is_link($path) || ! is_dir($path)) {
        unlink($path);
        return;
    }
    foreach (new FilesystemIterator($path) as $entry) {
        agentRemove($entry->getPathname());
    }
    rmdir($path);
}

beforeEach(function (): void {
    $this->root = sys_get_temp_dir() . '/shield-agent-' . bin2hex(random_bytes(8));
    mkdir($this->root);
    $this->source = dirname(__DIR__, 3) . '/resources/agent';
    $this->publisher = new AgentFilesPublisher();
});

afterEach(function (): void {
    agentRemove($this->root);
});

test('agent target parses lists', function (string $input, array $expected): void {
    expect(array_map(static fn(AgentTarget $target): string => $target->value, AgentTarget::fromList($input)))->toBe($expected);
})->with([
    ['all', ['claude', 'agents', 'cursor', 'copilot', 'shared']], ['none', []], ['claude', ['claude']],
    ['shared', ['shared']],
    [' claude , cursor ', ['claude', 'cursor']],
    ['claude,claude', ['claude']],
]);

test('agent target rejects invalid lists naming the value', function (string $input, string $bad): void {
    expect(fn() => AgentTarget::fromList($input))->toThrow(InvalidArgumentException::class, $bad);
})->with([['bogus', 'bogus'], ['all,claude', 'all'], ['none,claude', 'none'], ["\xFF", 'UTF-8']]);

test('creates all target formats and second run is unchanged without rewriting', function (): void {
    $results = $this->publisher->publish($this->root, $this->source, AgentTarget::cases(), false);
    expect($results)->toHaveCount(5);
    foreach ($results as $result) {
        expect($result->status)->toBe('created');
        touch($this->root . '/' . $result->path, 1000000000);
    }
    $claude = file_get_contents($this->root . '/.claude/skills/shield/SKILL.md');
    expect(file_get_contents($this->root . '/.agents/skills/shield/SKILL.md'))->toBe($claude);
    expect($claude)->toStartWith("---\nname: shield\n")
        ->toContain('<!-- shield-agent-file: managed by shield:install, regenerated on upgrade -->')
        ->toContain(file_get_contents($this->source . '/shield.md'));
    expect(file_get_contents($this->root . '/.cursor/rules/shield.mdc'))->toContain('alwaysApply: false', file_get_contents($this->source . '/summary.md'));
    expect(file_get_contents($this->root . '/.github/instructions/shield.instructions.md'))->toStartWith("---\napplyTo: \"**/*.php\"\n");
    foreach ($this->publisher->publish($this->root, $this->source, AgentTarget::cases(), false) as $result) {
        clearstatcache();
        expect($result->status)->toBe('unchanged')->and(filemtime($this->root . '/' . $result->path))->toBe(1000000000);
    }
    expect(file_get_contents($this->root . '/.claude/skills/shield/SKILL.md'))->toBe($claude);
});

test('updates managed files but skips user-owned files unless forced', function (): void {
    $path = $this->root . '/.claude/skills/shield/SKILL.md';
    mkdir(dirname($path), 0775, true);
    file_put_contents($path, 'user content');
    $result = $this->publisher->publish($this->root, $this->source, [AgentTarget::Claude], false)[0];
    expect($result->status)->toBe('skipped')->and($result->message)->toContain('--force')
        ->and(file_get_contents($path))->toBe('user content');
    expect($this->publisher->publish($this->root, $this->source, [AgentTarget::Claude], true)[0]->status)->toBe('updated');
    file_put_contents($path, "<!-- shield-agent-file: managed by shield:install, regenerated on upgrade -->\nold");
    expect($this->publisher->publish($this->root, $this->source, [AgentTarget::Claude], false)[0]->status)->toBe('updated')
        ->and(file_get_contents($path))->toContain(file_get_contents($this->source . '/shield.md'));
});

test('agents appends preserving every original byte including empty files', function (string $original): void {
    file_put_contents($this->root . '/AGENTS.md', $original);
    $result = $this->publisher->publish($this->root, $this->source, [AgentTarget::Agents], false)[0];
    $content = file_get_contents($this->root . '/AGENTS.md');
    expect($result->status)->toBe('updated')->and($content)
        ->toContain('<!-- shield:begin -->', file_get_contents($this->source . '/summary.md'), '<!-- shield:end -->');
    if ($original !== '') {
        $separator = mb_substr($original, -1, null, 'UTF-8') === "\n" ? "\n" : "\n\n";
        expect($content)->toStartWith($original . $separator . '<!-- shield:begin -->');
    }
})->with(['', 'original without newline', "original\n"]);

test('agents replaces only managed block and preserves outside bytes and CRLF', function (string $nl): void {
    $before = 'before' . $nl . $nl;
    $after = $nl . 'after with no newline';
    file_put_contents($this->root . '/AGENTS.md', $before . '<!-- shield:begin -->' . $nl . 'old' . $nl . '<!-- shield:end -->' . $after);
    expect($this->publisher->publish($this->root, $this->source, [AgentTarget::Agents], false)[0]->status)->toBe('updated');
    $content = file_get_contents($this->root . '/AGENTS.md');
    expect($content)->toStartWith($before)->toEndWith($after)->not->toContain($nl . 'old' . $nl);
    if ($nl === "\r\n") {
        expect(str_replace("\r\n", '', $content))->not->toContain("\n");
    }
})->with(["\n", "\r\n"]);

test('malformed agents markers fail untouched even with force', function (string $content): void {
    file_put_contents($this->root . '/AGENTS.md', $content);
    $result = $this->publisher->publish($this->root, $this->source, [AgentTarget::Agents], true)[0];
    expect($result->status)->toBe('failed')->and($result->message)->toContain('marker')
        ->and(file_get_contents($this->root . '/AGENTS.md'))->toBe($content);
})->with([
    '<!-- shield:begin -->', '<!-- shield:end -->', '<!-- shield:end --><!-- shield:begin -->',
    '<!-- shield:begin --><!-- shield:begin --><!-- shield:end -->',
    '<!-- shield:begin --><!-- shield:end --><!-- shield:end -->',
]);

test('writes through symlinks keeping the link', function (string $target, string $relative): void {
    $path = $this->root . '/' . $relative;
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    file_put_contents($this->root . '/real.md', 'outside');
    symlink($this->root . '/real.md', $path);
    expect($this->publisher->publish($this->root, $this->source, [AgentTarget::from($target)], true)[0]->status)->toBe('updated')
        ->and(is_link($path))->toBeTrue()->and(file_get_contents($this->root . '/real.md'))->toContain('Shield');
})->with([['agents', 'AGENTS.md'], ['claude', '.claude/skills/shield/SKILL.md']]);

test('validates all sources and project root before any writes', function (): void {
    foreach ([$this->root . '/missing', $this->root] as $source) {
        foreach ($this->publisher->publish($this->root, $source, AgentTarget::cases(), false) as $result) {
            expect($result->status)->toBe('failed');
        }
        expect(iterator_count(new FilesystemIterator($this->root)))->toBe(0);
    }
    expect($this->publisher->publish($this->root . '/missing', $this->source, [AgentTarget::Agents], false)[0]->status)->toBe('failed');
});

test('a failed target does not prevent others or leave temporary files', function (): void {
    file_put_contents($this->root . '/.claude', 'not a directory');
    $results = $this->publisher->publish($this->root, $this->source, [AgentTarget::Claude, AgentTarget::Agents], false);
    expect($results[0]->status)->toBe('failed')->and($results[1]->status)->toBe('created')
        ->and(glob($this->root . '/.shield-agent-*'))->toBe([]);
});

test('unwritable directory fails without throwing and continues', function (): void {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('Root bypasses directory permissions.');
    }
    mkdir($this->root . '/.claude', 0555);
    try {
        $results = $this->publisher->publish($this->root, $this->source, [AgentTarget::Claude, AgentTarget::Agents], false);
        expect($results[0]->status)->toBe('failed')->and($results[1]->status)->toBe('created')
            ->and(iterator_count(new FilesystemIterator($this->root . '/.claude')))->toBe(0);
    } finally {
        chmod($this->root . '/.claude', 0775);
    }
});

test('reference tracks env names commands and default needles', function (): void {
    $reference = file_get_contents($this->source . '/shield.md');
    $config = file_get_contents(dirname(__DIR__, 2) . '/laravel/config/shield.php');
    expect(mb_check_encoding($config, 'UTF-8'))->toBeTrue();
    preg_match_all('/SHIELD_[A-Z_]+/u', $config, $matches);
    foreach (array_merge($matches[0], ['shield:install', 'shield:status', 'shield:timing'], SensitivePathRule::DEFAULT_NEEDLES) as $value) {
        expect($reference)->toContain($value);
    }
});

test('resource hygiene and line limits', function (): void {
    foreach (['shield.md' => 250, 'summary.md' => 40] as $name => $limit) {
        $text = file_get_contents($this->source . '/' . $name);
        expect(mb_check_encoding($text, 'UTF-8'))->toBeTrue();
        $lower = mb_strtolower($text, 'UTF-8');
        foreach (['—', 'hetzner', 'educonect', 'educonnect'] as $forbidden) {
            expect($lower)->not->toContain($forbidden);
        }
        expect(count(explode("\n", $text)))->toBeLessThan($limit);
        preg_match_all('/\b(?:\d{1,3}\.){3}\d{1,3}\b/u', $text, $matches);
        foreach ($matches[0] as $ip) {
            expect(preg_match('/^(192\.0\.2|198\.51\.100|203\.0\.113)\.[0-9]+$/u', $ip))->toBe(1);
        }
    }
    expect(mb_rtrim(file_get_contents($this->source . '/summary.md'), null, 'UTF-8'))->toEndWith('Full reference: vendor/stero/shield/resources/agent/shield.md');
});
