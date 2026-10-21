<?php

declare(strict_types=1);

namespace Stero\Shield\CLI;

use InvalidArgumentException;

/** Self-contained badge pairs. Terminal background never determines contrast. */
final class Theme
{
    public const BLOCKED = ['background' => 'red-600', 'foreground' => 'white'];
    public const WOULD_BLOCK = ['background' => 'yellow-500', 'foreground' => 'black'];
    public const WATCH = ['background' => 'gray-500', 'foreground' => 'white'];
    public const LOW = self::WATCH;
    public const MEDIUM = self::WOULD_BLOCK;
    public const HIGH = self::BLOCKED;
    public const CRITICAL = ['background' => 'red-800', 'foreground' => 'white'];

    public const PAIRS = ['blocked' => self::BLOCKED, 'would-block' => self::WOULD_BLOCK,
        'watch' => self::WATCH, 'low' => self::LOW, 'medium' => self::MEDIUM,
        'high' => self::HIGH, 'critical' => self::CRITICAL];

    // Termwind has no dim attribute. Keep secondary text in the default colour.
    public const SECONDARY = 'italic';

    /** @param array{background: string, foreground: string} $pair */
    public static function badge(array $pair): string
    {
        return 'bg-' . $pair['background'] . ' text-' . $pair['foreground'];
    }

    public static function resolve(string $theme, string $colorfgbg = ''): string
    {
        if (!in_array($theme, ['auto', 'light', 'dark', 'plain'], true)) {
            throw new InvalidArgumentException('Invalid --theme: use auto, light, dark or plain');
        }
        if ($theme !== 'auto') {
            return $theme;
        }
        if (mb_check_encoding($colorfgbg, 'UTF-8')) {
            $parts = explode(';', $colorfgbg);
            $last = end($parts);
            if (preg_match('/\A(?:[0-9]|1[0-5])\z/u', $last) === 1) {
                $number = (int) $last;

                return $number <= 6 || $number === 8 ? 'dark' : 'light';
            }
        }

        return 'neutral';
    }
}
