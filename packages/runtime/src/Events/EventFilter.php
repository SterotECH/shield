<?php

declare(strict_types=1);

namespace Stero\Shield\Runtime\Events;

use DateTimeImmutable;
use InvalidArgumentException;

/** Exact field filters, inclusive time boundary and case insensitive path text. */
final readonly class EventFilter
{
    public function __construct(
        public string $verdict = 'any',
        public ?string $severity = null,
        public ?string $ip = null,
        public ?string $rule = null,
        public ?string $tenant = null,
        public ?DateTimeImmutable $since = null,
        public ?string $pathContains = null,
    ) {
        if (!in_array($verdict, ['any', 'blocked', 'would_block'], true)) {
            throw new InvalidArgumentException('Invalid --verdict: use blocked, would_block or any');
        }
        if ($severity !== null && !in_array($severity, ['low', 'medium', 'high', 'critical'], true)) {
            throw new InvalidArgumentException('Invalid --severity: use low, medium, high or critical');
        }
        foreach ([$ip, $rule, $tenant, $pathContains] as $value) {
            if ($value !== null && !mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidArgumentException('Invalid filter: expected UTF-8 text');
            }
        }
    }

    /** @param list<array<string, scalar|null>> $events
     * @return list<array<string, scalar|null>>
     */
    public function apply(array $events): array
    {
        return array_values(array_filter($events, function (array $event): bool {
            foreach (['severity', 'ip', 'rule', 'tenant'] as $field) {
                if ($this->$field !== null && ($event[$field] ?? '') !== $this->$field) {
                    return false;
                }
            }
            if ($this->verdict !== 'any' && EventReader::verdict($event) !== $this->verdict) {
                return false;
            }
            if ($this->since !== null && (!is_string($event['ts'] ?? null) || !EventReader::valid($event) || new DateTimeImmutable($event['ts']) < $this->since)) {
                return false;
            }
            $path = $event['path'] ?? '';
            return $this->pathContains === null || (is_string($path) && mb_check_encoding($path, 'UTF-8') && mb_stripos($path, $this->pathContains, 0, 'UTF-8') !== false);
        }));
    }

    public static function parseSince(string $value, ?DateTimeImmutable $now = null): DateTimeImmutable
    {
        if (!mb_check_encoding($value, 'UTF-8') || preg_match('/\A([1-9][0-9]{0,5})([mhd])\z/u', $value, $matches) !== 1) {
            throw new InvalidArgumentException('Invalid --since: use a duration such as 15m, 2h, 1d or 7d');
        }
        $seconds = (int) $matches[1] * ['m' => 60, 'h' => 3600, 'd' => 86400][$matches[2]];
        return ($now ?? new DateTimeImmutable())->modify('-' . $seconds . ' seconds');
    }
}
