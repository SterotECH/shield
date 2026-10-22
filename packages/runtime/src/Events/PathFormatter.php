<?php

declare(strict_types=1);

namespace Stero\Shield\Runtime\Events;

use Stero\Shield\Runtime\Sanitiser;

/** Fit safe visible text, preserving both ends. Markup escaping belongs to the renderer. */
final class PathFormatter
{
    public static function fit(string $path, int $width): string
    {
        $path = html_entity_decode(Sanitiser::forTerminal($path), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $width = max(12, $width);
        if (mb_strwidth($path, 'UTF-8') <= $width) {
            return $path;
        }
        $left = (int) ceil(($width - 1) / 2);
        $right = $width - 1 - $left;
        $tail = '';
        for ($i = mb_strlen($path, 'UTF-8') - 1; $i >= 0; $i--) {
            $char = mb_substr($path, $i, 1, 'UTF-8');
            if (mb_strwidth($char . $tail, 'UTF-8') > $right) {
                break;
            }
            $tail = $char . $tail;
        }
        return mb_strimwidth($path, 0, $left, '', 'UTF-8') . '…' . $tail;
    }
}
