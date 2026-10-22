<?php

declare(strict_types=1);

namespace Stero\Shield\Runtime\Events;

/** Count known fields without changing event keys. */
final class Facets
{
    /** @param list<array<string, scalar|null>> $events
     * @return array<string|int, int>
     */
    public static function count(array $events, string $field): array
    {
        if (!in_array($field, ['ip', 'rule', 'severity', 'tenant', 'verdict'], true)) {
            throw new \InvalidArgumentException('Unknown facet field');
        }
        $counts = [];
        foreach ($events as $event) {
            $value = $field === 'verdict' ? EventReader::verdict($event) : ($event[$field] ?? '');
            if (is_string($value)) {
                $counts[$value] = ($counts[$value] ?? 0) + 1;
            }
        }
        uksort($counts, static fn(string|int $a, string|int $b): int => ($counts[$b] <=> $counts[$a]) ?: strcmp((string) $a, (string) $b));
        return $counts;
    }
}
