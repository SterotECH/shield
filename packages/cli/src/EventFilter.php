<?php

declare(strict_types=1);

namespace Stero\Shield\CLI;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/** Exact event-field filters, a minimum severity and an inclusive time boundary. */
final readonly class EventFilter
{
    private ?DateTimeImmutable $since;

    /** @param array<string, mixed> $options */
    public function __construct(private array $options = [], ?DateTimeImmutable $now = null)
    {
        $severity = $options['severity'] ?? null;
        if ($severity !== null && self::rank($severity) === 0) {
            throw new InvalidArgumentException('Invalid --severity: use low, medium, high or critical');
        }
        $since = $options['since'] ?? null;
        $this->since = $since === null ? null : self::parseSince($since, $now ?? new DateTimeImmutable());
    }

    /** @param array<string, mixed> $event */
    public function matches(array $event): bool
    {
        foreach (['rule', 'ip', 'tenant'] as $key) {
            if (isset($this->options[$key]) && ($event[$key] ?? null) !== $this->options[$key]) {
                return false;
            }
        }
        if (isset($this->options['severity']) && self::rank($event['severity'] ?? null) < self::rank($this->options['severity'])) {
            return false;
        }
        if (($this->options['would-block'] ?? false) === true && ($event['would_block'] ?? null) !== true) {
            return false;
        }
        if ($this->since !== null) {
            $time = self::timestamp($event['ts'] ?? null);
            if ($time === null || $time < $this->since) {
                return false;
            }
        }

        return true;
    }

    public static function timestamp(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/u', $value) !== 1) {
            return null;
        }
        try {
            $time = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();

            return $errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0) ? null : $time;
        } catch (Throwable) {
            return null;
        }
    }

    private static function parseSince(mixed $value, DateTimeImmutable $now): DateTimeImmutable
    {
        if (is_string($value) && mb_check_encoding($value, 'UTF-8')) {
            if (preg_match('/\A([1-9][0-9]*)([mhd])\z/u', $value, $parts) === 1) {
                $number = filter_var($parts[1], FILTER_VALIDATE_INT);
                $factor = match ($parts[2]) {
                    'm' => 60, 'h' => 3600, default => 86400
                };
                if (is_int($number) && $number <= intdiv(PHP_INT_MAX, $factor)) {
                    try {
                        return $now->sub(new DateInterval('PT' . ($number * $factor) . 'S'));
                    } catch (Throwable) {
                    }
                }
            } else {
                $time = self::timestamp($value);
                if ($time !== null) {
                    return $time;
                }
            }
        }

        throw new InvalidArgumentException('Invalid --since: use 15m, 2h, 1d or an ISO 8601 timestamp');
    }

    private static function rank(mixed $value): int
    {
        return match ($value) {
            'low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4, default => 0
        };
    }
}
