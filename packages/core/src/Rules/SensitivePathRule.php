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
 * - Match a cleaned, lowercased copy split into non-empty path segments.
 *   Single-segment needles also accept a dotted suffix. Multi-segment
 *   needles require consecutive exact segments. Never decode the path here.
 * - Preserve the original path as evidence, scrubbing invalid UTF-8 only.
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
        $path = str_replace(["\0", '\\'], ['', '/'], $request->path);
        // Invalid bytes must survive matching so they cannot conceal a needle.
        // The indirect call prevents Pint replacing the required byte-wise fallback.
        $path = mb_check_encoding($path, 'UTF-8') ? mb_strtolower($path, 'UTF-8') : call_user_func('strtolower', $path);
        $segments = array_values(array_filter(explode('/', $path), static fn(string $segment): bool => $segment !== ''));
        foreach ($this->needles as $needle) {
            if ($needle === '' || !mb_check_encoding($needle, 'UTF-8')) {
                continue;
            }
            $parts = array_values(array_filter(explode('/', mb_strtolower($needle, 'UTF-8')), static fn(string $part): bool => $part !== ''));
            $length = count($parts);
            if ($length === 0) {
                continue;
            }
            foreach ($segments as $index => $segment) {
                if ($length === 1) {
                    $matches = $segment === $parts[0]
                        || (mb_check_encoding($segment, 'UTF-8')
                            ? mb_strpos($segment, $parts[0] . '.', 0, 'UTF-8') === 0
                            : str_starts_with($segment, $parts[0] . '.'));
                } else {
                    $matches = array_slice($segments, $index, $length) === $parts;
                }
                if ($matches) {
                    $evidence = mb_check_encoding($request->path, 'UTF-8')
                        ? $request->path : mb_scrub($request->path, 'UTF-8');

                    return new Finding(
                        $this->id(),
                        Severity::High,
                        'Request for a sensitive file or folder',
                        mb_substr($evidence, 0, 200, 'UTF-8'),
                        true,
                    );
                }
            }
        }

        return null;
    }
}
