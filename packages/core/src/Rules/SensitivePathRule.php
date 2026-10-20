<?php

declare(strict_types=1);

namespace Shield\Core\Rules;

use Shield\Core\Finding;
use Shield\Core\Http\NormalizedRequest;

/**
 * Honeypot paths: files and folders no real user ever requests. Bots probe
 * them hoping for leaked secrets. This is your existing .env / .git logic.
 *
 * IMPLEMENTATION NOTES
 * - Lowercase the path, then flag it when any needle appears as a whole path
 *   segment or as the end of the path. Use str_contains on "/" . needle for
 *   segments. Do not use loose regexes.
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
    public function __construct(private array $needles = self::DEFAULT_NEEDLES)
    {
    }

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
        throw new \LogicException('Not implemented');
    }
}
