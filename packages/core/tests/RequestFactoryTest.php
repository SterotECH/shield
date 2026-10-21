<?php

declare(strict_types=1);

use Stero\Shield\Http\RequestFactory;

it('normalises the path exactly once', function (string $uri, string $expected): void {
    expect(RequestFactory::fromGlobals(['REQUEST_URI' => $uri], [], '')->path)->toBe($expected);
})->with([
    ['', '/'], ['abc', '/abc'], ['//a///b?x=1', '/a/b'],
    ['/%2eenv', '/.env'], ['/%252eenv', '/%2eenv'],
    ['/a%2fb', '/a/b'], ['/a+b', '/a+b'], ['/a%3fb?x=1', '/a?b'],
    ["/bad\xff", "/bad\xff"], ['/%ff', "/\xff"],
    ['/a/b?x=1', '/a/b'], ['a/b', '/a/b'],
    ['http://host/a?x=1', '/a'], ['http://host', '/'],
    ['/%2e%65nv', '/.env'], ['/%252e%252eenv', '/%2e%2eenv'],
    ['/A/../B', '/A/../B'], ['/%00', "/\0"],
    ['%', '/%'], ['%zz', '/%zz'], ['////', '/'],
    [str_repeat('/a', 100000), str_repeat('/a', 100000)],
    ['/a%2f', '/a/'],
]);

it('takes forwarded addresses only from trusted proxies', function (array $server, array $trusted, string $expected): void {
    expect(RequestFactory::fromGlobals($server, [], '', $trusted)->clientIp)->toBe($expected);
})->with([
    [['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '127.0.0.1'], [], '203.0.113.9'],
    [['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '127.0.0.1, 203.0.113.9, 10.0.0.2'], ['10.0.0.1', '10.0.0.2'], '203.0.113.9'],
    [['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '2001:db8::1'], ['10.0.0.1'], '2001:db8::1'],
    [['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9, invalid'], ['10.0.0.1'], '203.0.113.9'],
    [['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 9.9.9.9'], ['10.0.0.1'], '9.9.9.9'],
    [['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '5.5.5.5, 10.0.0.2'], ['10.0.0.1', '10.0.0.2'], '5.5.5.5'],
    [['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'unknown, , garbage'], ['10.0.0.1'], '10.0.0.1'],
    [['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4:5678'], ['10.0.0.1'], '1.2.3.4'],
    [['REMOTE_ADDR' => '2001:db8::2', 'HTTP_X_FORWARDED_FOR' => '[2001:db8::1]:443'], ['2001:db8::2'], '2001:db8::1'],
    [['REMOTE_ADDR' => '1.2.3.4:5678'], [], '1.2.3.4'],
    [['REMOTE_ADDR' => '[2001:db8::1]:443'], [], '2001:db8::1'],
    [['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '127.0.0.1'], ['10.0.0.1'], '203.0.113.9'],
    [[], [], '0.0.0.0'],
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
        ->and($request->clientIp)->toBe('0.0.0.0')
        ->and($request->headers)->toBe([])
        ->and($request->bodyTruncated)->toBeFalse();
});

it('uppercases methods and preserves request metadata', function (): void {
    $request = RequestFactory::fromGlobals([
        'REQUEST_METHOD' => 'post', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        'HTTP_BAD' => [], 'HTTP_NULL' => null,
    ], ['name' => 'value', 'nested' => ['x' => 'y'], 'invalid' => null], '', [], 'tenant', 'user');

    expect($request->method)->toBe('POST')
        ->and($request->headers)->toBe(['x-forwarded-for' => '1.2.3.4'])
        ->and($request->query)->toBe(['name' => 'value', 'nested' => ['x' => 'y']])
        ->and($request->tenantId)->toBe('tenant')
        ->and($request->userId)->toBe('user')
        ->and($request->receivedAt)->toBeNull();
});

it('caps bodies only when they exceed the byte limit', function (string $body, int $cap, string $expected, bool $truncated): void {
    $request = RequestFactory::fromGlobals([], [], $body, maxBodyBytes: $cap);

    expect($request->body)->toBe($expected)
        ->and($request->bodyTruncated)->toBe($truncated)
        ->and(mb_check_encoding($request->body, 'UTF-8'))->toBeTrue();
})->with([
    ['abc', 4, 'abc', false], ['abc', 3, 'abc', false],
    ['abcd', 3, 'abc', true], ['aé', 2, 'a', true],
]);
