<?php

declare(strict_types=1);

namespace Stero\Shield\CLI;

use DateTimeImmutable;
use DateTimeZone;
use Stero\Shield\Runtime\Sanitiser;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

/** Pure event-to-HTML formatting. Every displayed event string is untrusted. */
final class EventFormatter
{
    /** @param array<string, mixed> $event */
    public function format(array $event, int $width = 80): string
    {
        $width = max(60, $width);
        [$status, $statusStyle] = match (true) {
            ($event['blocked'] ?? null) === true => ['BLOCKED', Theme::badge(Theme::BLOCKED)],
            ($event['would_block'] ?? null) === true => ['WOULD BLOCK', Theme::badge(Theme::WOULD_BLOCK)],
            default => ['WATCH', Theme::badge(Theme::WATCH)],
        };
        [$severity, $severityStyle] = match ($event['severity'] ?? null) {
            'low' => ['LOW', Theme::badge(Theme::LOW)],
            'medium' => ['MEDIUM', Theme::badge(Theme::MEDIUM)],
            'high' => ['HIGH', Theme::badge(Theme::HIGH)],
            'critical' => ['CRITICAL', Theme::badge(Theme::CRITICAL)],
            default => ['-', ''],
        };
        $time = '-';
        if (is_string($event['ts'] ?? null) && $event['ts'] !== '') {
            try {
                $time = (new DateTimeImmutable(self::plain($event['ts'])))
                    ->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('H:i:s');
            } catch (Throwable) {
            }
        }
        $ip = self::text($event['ip'] ?? null, min(39, intdiv($width, 4)));
        $prefixWidth = mb_strwidth($time . $status . $severity . self::plain($ip), 'UTF-8') + 4;
        $path = self::text($event['path'] ?? null, max(1, $width - $prefixWidth));
        $details = 'rule ' . self::clean($event['rule'] ?? null)
            . ' | tenant ' . self::clean($event['tenant'] ?? null)
            . ' | method ' . self::clean($event['method'] ?? null);

        return '<div><div>' . $time . ' <span class="' . $statusStyle . '">' . $status . '</span> '
            . '<span class="' . $severityStyle . '">' . $severity . '</span> ' . $ip
            . ' <span class="font-bold">' . $path . '</span></div>'
            . '<div class="' . Theme::SECONDARY . '">' . self::text($details, $width) . '</div></div>';
    }

    /** Escapes text and truncates by display columns before encoding entities. */
    public static function text(mixed $value, ?int $width = null): string
    {
        $plain = self::clean($value);
        if ($width !== null && mb_strwidth($plain, 'UTF-8') > $width) {
            $plain = mb_strimwidth($plain, 0, max(0, $width - 1), '', 'UTF-8') . '…';
        }

        return Sanitiser::forTerminal(OutputFormatter::escape($plain));
    }

    private static function clean(mixed $value): string
    {
        return self::plain(Sanitiser::forTerminal(is_string($value) ? $value : '-'));
    }

    /** Decode our own escaped text for measuring and composing, never for HTML. */
    private static function plain(string $safe): string
    {
        return html_entity_decode($safe, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
