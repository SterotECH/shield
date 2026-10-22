<?php

declare(strict_types=1);

namespace Stero\Shield\Runtime\Events;

/** Split each IP/rule stream when the gap to its previous event exceeds 60s. */
final class IncidentGrouper
{
    public const GAP_SECONDS = 60;

    /** @param list<array<string, scalar|null>> $events
     * @return list<Incident>
     */
    public static function group(array $events): array
    {
        $events = array_values(array_filter($events, EventReader::valid(...)));
        usort($events, static fn(array $a, array $b): int => new \DateTimeImmutable((string) $a['ts']) <=> new \DateTimeImmutable((string) $b['ts']));
        $groups = [];
        $active = [];
        $ranks = ['low' => 0, 'medium' => 1, 'high' => 2, 'critical' => 3];
        foreach ($events as $event) {
            $key = json_encode([$event['ip'], $event['rule']]);
            $index = $active[$key] ?? null;
            $previous = $index === null ? null : $groups[$index];
            $ts = (string) $event['ts'];
            $gap = $previous === null ? PHP_INT_MAX : (new \DateTimeImmutable($ts))->getTimestamp() - (new \DateTimeImmutable($previous->lastTs))->getTimestamp();
            if ($previous === null || $gap > self::GAP_SECONDS) {
                $active[$key] = count($groups);
                $groups[] = new Incident((string) $event['ip'], (string) $event['rule'], (string) $event['severity'], is_string($event['tenant']) ? $event['tenant'] : null, 1, $ts, $ts, $event['blocked'] ? 'blocked' : 'would_block', (string) $event['path']);
            } else {
                $severity = (string) $event['severity'];
                $groups[$index] = new Incident($previous->ip, $previous->rule, $ranks[$severity] > $ranks[$previous->severity] ? $severity : $previous->severity, is_string($event['tenant']) ? $event['tenant'] : null, $previous->count + 1, $previous->firstTs, $ts, $previous->verdict === 'blocked' || $event['blocked'] ? 'blocked' : 'would_block', (string) $event['path']);
            }
        }
        usort($groups, static fn(Incident $a, Incident $b): int => new \DateTimeImmutable($b->lastTs) <=> new \DateTimeImmutable($a->lastTs));
        return $groups;
    }
}
