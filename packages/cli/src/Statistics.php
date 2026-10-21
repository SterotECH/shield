<?php

declare(strict_types=1);

namespace Stero\Shield\CLI;

use DateTimeZone;

/**
 * Aggregates event counts; equal top counts are ordered alphabetically.
 * @phpstan-type Summary array{total: int, modes: array<array-key, int>, would_block: int, blocked: int, rules: array<array-key, int>, ips: array<array-key, int>, paths: array<array-key, int>, hours: array<array-key, int>}
 */
final class Statistics
{
    /**
     * @param iterable<array<string, mixed>> $events
     * @return array{total: int, modes: array<array-key, int>, would_block: int, blocked: int, rules: array<array-key, int>, ips: array<array-key, int>, paths: array<array-key, int>, hours: array<array-key, int>}
     */
    public function summarise(iterable $events): array
    {
        $total = $wouldBlock = $blocked = 0;
        $modes = $rules = $ips = $paths = $hours = [];
        foreach ($events as $event) {
            $total++;
            $mode = match ($event['mode'] ?? null) {
                'detect' => 'detect', 'enforce' => 'enforce', default => '-'
            };
            $modes[$mode] = ($modes[$mode] ?? 0) + 1;
            $wouldBlock += ($event['would_block'] ?? null) === true ? 1 : 0;
            $blocked += ($event['blocked'] ?? null) === true ? 1 : 0;
            self::count($rules, $event['rule'] ?? null);
            self::count($ips, $event['ip'] ?? null);
            self::count($paths, $event['path'] ?? null);
            $time = EventFilter::timestamp($event['ts'] ?? null);
            if ($time !== null) {
                $hour = $time->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:00 P');
                $hours[$hour] = ($hours[$hour] ?? 0) + 1;
            }
        }
        ksort($modes);
        ksort($hours);

        return ['total' => $total, 'modes' => $modes, 'would_block' => $wouldBlock, 'blocked' => $blocked,
            'rules' => self::top($rules), 'ips' => self::top($ips), 'paths' => self::top($paths), 'hours' => $hours];
    }

    /** @param list<int> $counts */
    public static function barLine(array $counts): string
    {
        $max = max([1, ...$counts]);
        $line = '';
        foreach ($counts as $count) {
            $index = max(0, min(7, (int) ceil($count / $max * 8) - 1));
            $line .= mb_substr('▁▂▃▄▅▆▇█', $index, 1, 'UTF-8');
        }

        return $line;
    }

    /** @param array<array-key, int> $counts */
    private static function count(array &$counts, mixed $value): void
    {
        $key = is_string($value) ? $value : '-';
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    /**
     * @param array<array-key, int> $counts
     * @return array<array-key, int>
     */
    private static function top(array $counts): array
    {
        uksort($counts, static fn(string|int $a, string|int $b): int => ($counts[$b] <=> $counts[$a]) ?: ((string) $a <=> (string) $b));

        return array_slice($counts, 0, 5, true);
    }
}
