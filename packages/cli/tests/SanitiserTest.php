<?php

declare(strict_types=1);

use Stero\Shield\CLI\Sanitiser;

it('strips terminal controls including ESC sequences', function (string $input, string $expected): void {
    $safe = Sanitiser::forTerminal($input);
    expect($safe)->toBe($expected)
        ->and(preg_match('/[\x00-\x1F\x7F]/', $safe))->toBe(0)
        ->and($safe)->not->toContain('<', '>');
})->with([
    ["a\e[2Jb", 'a[2Jb'],
    ["\e]0;title\x07", ']0;title'],
    ["a\r\0\n\tb\x7F", 'ab'],
    ["a\u{0080}\u{009F}b", 'ab'],
    ["a\u{202A}\u{202B}\u{202C}\u{202D}\u{202E}\u{2066}\u{2067}\u{2068}\u{2069}b", 'ab'],
]);

it('replaces real invalid UTF-8 with question marks', function (): void {
    expect(Sanitiser::forTerminal("/\xFF/\x80"))->toBe('/?/?');
});

it('escapes markup injection and existing entities', function (): void {
    expect(Sanitiser::forTerminal('<span class="request-data">x</span> & \' &lt;b&gt;'))
        ->toBe('&lt;span class=&quot;request-data&quot;&gt;x&lt;/span&gt; &amp; &#039; &amp;lt;b&amp;gt;');
});
