<?php

declare(strict_types=1);

namespace Stero\Shield\CLI;

/** Converts untrusted text to control-free, escaped Termwind HTML text. */
final class Sanitiser
{
    public static function forTerminal(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $previous = mb_substitute_character();
            mb_substitute_character(63);
            try {
                $value = mb_scrub($value, 'UTF-8');
            } finally {
                mb_substitute_character($previous);
            }
        }
        $value = preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $value) ?? '';

        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
