<?php

declare(strict_types=1);

namespace Stero\Shield\Rules;

use Stero\Shield\Finding;
use Stero\Shield\Http\NormalizedRequest;

/**
 * Honeypot paths: files and folders no real user ever requests. Bots probe
 * them hoping for leaked secrets. This is your existing .env / .git logic.
 *
 * IMPLEMENTATION NOTES
 * - Lowercase the path, then flag it when any needle appears as a whole path
 *   segment or as the end of the path. Use mb_strpos with slash boundaries
 *   for segments. Do not use loose regexes.
 * - Return Finding with: ruleId "sensitive-path", Severity::High,
 *   banWorthy true, evidence = the path (cut to 200 chars).
 * - MUST NOT flag "/.well-known/" (certificate renewal, security.txt).
 *   Add a test for it in tests/corpus/normal.
 * - Phase::Inline. It is a string check, so it is cheap.
 */
final class SensitivePathRule implements Rule
{
    public const array DEFAULT_NEEDLES = [
        '.env',
        '.git',
        '.svn',
        '.ds_store',
        'wp-login.php',
        'wp-admin',
        'phpmyadmin',
        'phpinfo.php',
        'vendor/phpunit',
        'storage/logs',
        'backup.sql',
        'dump.sql',
    ];

    /** @param list<string> $needles */
    public function __construct(private array $needles = self::DEFAULT_NEEDLES) {}

    public function id(): string
    {
        return 'sensitive-path';
    }

    public function phase(): Phase
    {
        return Phase::Inline;
    }

    public function inspect(NormalizedRequest $request): ?Finding
    {
        if (!mb_check_encoding($request->path, 'UTF-8')) {
            return null;
        }
        $path = mb_strtolower($request->path, 'UTF-8');
        if ($path === '/.well-known' || mb_strpos($path, '/.well-known/', 0, 'UTF-8') === 0) {
            return null;
        }
        foreach ($this->needles as $needle) {
            if ($needle === '' || !mb_check_encoding($needle, 'UTF-8')) {
                continue;
            }
            $pattern = '/' . mb_strtolower(mb_trim($needle, '/', 'UTF-8'), 'UTF-8') . '/';
            if (mb_strpos($path . '/', $pattern, 0, 'UTF-8') !== false) {
                return new Finding(
                    $this->id(),
                    Severity::High,
                    'Sensitive path requested',
                    mb_substr($request->path, 0, 200, 'UTF-8'),
                    true,
                );
            }
        }

        return null;
    }
}
