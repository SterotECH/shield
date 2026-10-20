<?php

declare(strict_types=1);

use Stero\Shield\Http\RequestFactory;

it('normalises the path exactly once', function (string $uri, string $expected): void {
    expect(RequestFactory::fromGlobals(['REQUEST_URI' => $uri], [], '')->path)->toBe($expected);
})->with([
    ['', '/'], ['abc', '/abc'], ['//a///b?x=1', '/a/b'],
    ['/%2eenv', '/.env'], ['/%252eenv', '/%2eenv'],
    ['/a%2fb', '/a/b'], ['/a+b', '/a+b'], ['/a%3fb?x=1', '/a?b'],
    ["/bad\xff", '/'], ['/%ff', '/'],
]);

it('takes forwarded addresses only from trusted proxies', function (array $server, array $trusted, string $expected): void {
    expect(RequestFactory::fromGlobals($server, [], '', $trusted)->clientIp)->toBe($expected);
})->with([
    [['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '127.0.0.1'], [], '203.0.113.9'],
    [['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '127.0.0.1, 203.0.113.9, 10.0.0.2'], ['10.0.0.1', '10.0.0.2'], '203.0.113.9'],
    [['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '2001:db8::1'], ['10.0.0.1'], '2001:db8::1'],
    [['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9, invalid'], ['10.0.0.1'], '10.0.0.1'],
    [['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '10.0.0.2'], ['10.0.0.1', '10.0.0.2'], '10.0.0.1'],
    [['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => ['bad']], ['10.0.0.1'], '10.0.0.1'],
]);

it('builds headers, query, identity and a byte-limited UTF-8 body', function (): void {
    $request = RequestFactory::fromGlobals([
        'REQUEST_METHOD' => 'POST', 'HTTP_USER_AGENT' => 'Browser',
        'HTTP_AUTHORIZATION' => 'private', 'CONTENT_TYPE' => 'text/plain', 'CONTENT_LENGTH' => '6',
        'REMOTE_ADDR' => '203.0.113.9',
    ], ['a' => ['b' => '1']], 'ééé', [], 'school', 'person', 5);

    expect($request->method)->toBe('POST')
        ->and($request->header('User-Agent'))->toBe('Browser')
        ->and($request->header('Content-Type'))->toBe('text/plain')
        ->and($request->header('Content-Length'))->toBe('6')
        ->and($request->query)->toBe(['a' => ['b' => '1']])
        ->and($request->body)->toBe('éé')
        ->and($request->bodyTruncated)->toBeTrue()
        ->and($request->tenantId)->toBe('school')
        ->and($request->userId)->toBe('person');
});

it('handles missing and malformed inputs without throwing', function (): void {
    $request = RequestFactory::fromGlobals(['REQUEST_URI' => [], 'REQUEST_METHOD' => [], 'REMOTE_ADDR' => [], 'HTTP_BAD' => []], [], '', [], null, null, 0);

    expect($request->path)->toBe('/')
        ->and($request->method)->toBe('GET')
        ->and($request->clientIp)->toBe('')
        ->and($request->headers)->toBe([])
        ->and($request->bodyTruncated)->toBeFalse();
});
