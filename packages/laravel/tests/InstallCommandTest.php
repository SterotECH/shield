<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Stero\Shield\Laravel\ShieldServiceProvider;

beforeEach(function (): void {
    $this->installRoot = sys_get_temp_dir().'/shield-install-'.bin2hex(random_bytes(8));
    mkdir($this->installRoot, 0700, true);
    app()->setBasePath($this->installRoot);
    app()->useStoragePath($this->installRoot.'/storage');
    mkdir($this->installRoot.'/storage');
    app()->register(ShieldServiceProvider::class, true);
    file_put_contents($this->installRoot.'/.env', "APP_NAME=unchanged\n");
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->installRoot);
});

it('install defaults to only shared skill and leaves env untouched', function (): void {
    Artisan::call('shield:install');
    expect(Artisan::output())->toContain('created .agents/skills/shield/SKILL.md')
        ->and(is_file($this->installRoot.'/config/shield.php'))->toBeTrue()
        ->and(is_file($this->installRoot.'/.agents/skills/shield/SKILL.md'))->toBeTrue()
        ->and(file_exists($this->installRoot.'/.claude'))->toBeFalse()
        ->and(file_exists($this->installRoot.'/AGENTS.md'))->toBeFalse()
        ->and(file_exists($this->installRoot.'/.cursor'))->toBeFalse()
        ->and(file_exists($this->installRoot.'/.github'))->toBeFalse()
        ->and(file_get_contents($this->installRoot.'/.env'))->toBe("APP_NAME=unchanged\n");
});

it('install all writes five targets and second run reports each unchanged', function (): void {
    $paths = ['.agents/skills/shield/SKILL.md', '.claude/skills/shield/SKILL.md', 'AGENTS.md', '.cursor/rules/shield.mdc', '.github/instructions/shield.instructions.md'];
    foreach (['created', 'unchanged'] as $status) {
        expect(Artisan::call('shield:install', ['--agents' => 'all']))->toBe(0);
        $output = Artisan::output();
        foreach ($paths as $path) {
            expect($output)->toContain($status.' '.$path)
                ->and(is_file($this->installRoot.'/'.$path))->toBeTrue();
        }
    }
    expect(file_get_contents($this->installRoot.'/.env'))->toBe("APP_NAME=unchanged\n");
});

it('install rejects bogus targets before publishing config or writing anything', function (): void {
    expect(Artisan::call('shield:install', ['--agents' => 'bogus']))->toBe(1)
        ->and(Artisan::output())->toContain('bogus')
        ->and(file_exists($this->installRoot.'/config/shield.php'))->toBeFalse()
        ->and(file_exists($this->installRoot.'/storage/shield'))->toBeFalse()
        ->and(file_exists($this->installRoot.'/.claude'))->toBeFalse();
});

it('install none skips agent files and force agents replaces user files', function (): void {
    expect(Artisan::call('shield:install', ['--agents' => 'none']))->toBe(0)
        ->and(file_exists($this->installRoot.'/.claude'))->toBeFalse();
    mkdir($this->installRoot.'/.claude/skills/shield', 0775, true);
    $path = $this->installRoot.'/.claude/skills/shield/SKILL.md';
    file_put_contents($path, 'user');
    expect(Artisan::call('shield:install', ['--agents' => 'claude']))->toBe(0)->and(Artisan::output())->toContain('skipped .claude/skills/shield/SKILL.md');
    expect(Artisan::call('shield:install', ['--agents' => 'claude', '--force-agents' => true]))->toBe(0)
        ->and(file_get_contents($path))->not->toBe('user');
});

it('install returns failure for a failed target while attempting the others', function (): void {
    file_put_contents($this->installRoot.'/AGENTS.md', '<!-- shield:begin -->');
    expect(Artisan::call('shield:install', ['--agents' => 'all']))->toBe(1)
        ->and(Artisan::output())->toContain('failed AGENTS.md', 'created .cursor/rules/shield.mdc');
});
