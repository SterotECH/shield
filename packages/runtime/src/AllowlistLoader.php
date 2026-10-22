<?php

declare(strict_types=1);

namespace Stero\Shield\Runtime;

use Stero\Shield\Net\IpMatcher;
use Throwable;

/** Reads individually validated IP addresses and CIDR ranges. */
final class AllowlistLoader
{
    /** @param list<string> $extra */
    public function load(?string $file, array $extra): AllowlistResult
    {
        $lines = [];
        try {
            if ($file !== null && is_file($file) && is_readable($file)) {
                $contents = @file($file, FILE_IGNORE_NEW_LINES);
                $lines = is_array($contents) ? $contents : [];
            }
        } catch (Throwable) {
        }

        $entries = [];
        $seen = [];
        $dropped = 0;
        foreach ([...$lines, ...$extra] as $line) {
            try {
                if (! is_string($line) || ! mb_check_encoding($line, 'UTF-8')) {
                    $dropped++;

                    continue;
                }
                $entry = mb_trim($line, null, 'UTF-8');
                if ($entry === '' || str_starts_with($entry, '#')) {
                    continue;
                }
                new IpMatcher([$entry]);
                if (isset($seen[$entry])) {
                    continue;
                }
                $seen[$entry] = true;
                $entries[] = $entry;
            } catch (Throwable) {
                $dropped++;
            }
        }

        return new AllowlistResult($entries, $dropped);
    }
}
