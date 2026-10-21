<?php

declare(strict_types=1);

namespace Stero\Shield\Http;

/**
 * Builds a NormalizedRequest from plain PHP inputs. Host apps with their own
 * request object skip this and build NormalizedRequest directly.
 *
 * DESIGN RULE: do not read $_SERVER, $_GET or php://input inside this class.
 * The caller passes them in, so tests can feed any request.
 *
 * IMPLEMENTATION NOTES
 * - Path: take REQUEST_URI, cut at "?", percent-decode ONCE (never twice,
 *   double decoding hides attacks and creates new ones), collapse "//" to
 *   "/", ensure a leading "/".
 * - Headers: read HTTP_* keys from $server, convert HTTP_USER_AGENT to
 *   "user-agent". Add CONTENT_TYPE and CONTENT_LENGTH too.
 * - Body: cut $rawBody to $maxBodyBytes and set bodyTruncated accordingly.
 * - Client IP: REMOTE_ADDR by default. Use X-Forwarded-For ONLY when
 *   REMOTE_ADDR is in $trustedProxies, and then take the right-most address
 *   that is not itself a trusted proxy. Trusting the header blindly lets an
 *   attacker fake any IP, including yours, so this is security critical.
 */
final class RequestFactory
{
    /**
     * @param array<string, mixed> $server        usually $_SERVER
     * @param array<string, mixed> $query         usually $_GET
     * @param list<string>         $trustedProxies exact IPs of your proxies
     */
    public static function fromGlobals(
        array $server,
        array $query,
        string $rawBody,
        array $trustedProxies = [],
        ?string $tenantId = null,
        ?string $userId = null,
        int $maxBodyBytes = 16384,
    ): NormalizedRequest {
        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_string($value) || !mb_check_encoding($key, 'UTF-8')) {
                continue;
            }
            if (mb_strpos($key, 'HTTP_', 0, 'UTF-8') === 0) {
                $name = mb_strtolower(str_replace('_', '-', mb_substr($key, 5, null, 'UTF-8')), 'UTF-8');
                $headers[$name] = $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[mb_strtolower(str_replace('_', '-', $key), 'UTF-8')] = $value;
            }
        }
        $parameters = [];
        foreach ($query as $key => $value) {
            if (is_string($value) || is_array($value)) {
                $parameters[(string) $key] = $value;
            }
        }
        $maxBodyBytes = max(0, $maxBodyBytes);
        $truncated = mb_strlen($rawBody, '8bit') > $maxBodyBytes;
        $body = $truncated ? mb_strcut($rawBody, 0, $maxBodyBytes, 'UTF-8') : $rawBody;

        return new NormalizedRequest(
            is_string($server['REQUEST_METHOD'] ?? null) && mb_check_encoding($server['REQUEST_METHOD'], 'UTF-8')
                ? mb_strtoupper($server['REQUEST_METHOD'], 'UTF-8') : 'GET',
            self::normalisePath(is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/'),
            $parameters,
            $headers,
            $body,
            $truncated,
            self::resolveClientIp($server, $trustedProxies),
            $tenantId,
            $userId,
        );
    }

    /** Decode once, remove the query and collapse duplicate slashes. */
    private static function normalisePath(string $requestUri): string
    {
        $path = explode('?', $requestUri, 2)[0];
        if (mb_check_encoding($path, 'UTF-8')) {
            $scheme = mb_strpos($path, '://', 0, 'UTF-8');
            if ($scheme !== false) {
                $start = mb_strpos($path, '/', $scheme + 3, 'UTF-8');
                $path = $start === false ? '/' : mb_substr($path, $start, null, 'UTF-8');
            }
        }

        // Work on delimiters as bytes so invalid UTF-8 and null bytes survive.
        $path = rawurldecode($path);
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment !== '') {
                $segments[] = $segment;
            }
        }
        $normalised = '/' . implode('/', $segments);
        if ($segments !== [] && str_ends_with($path, '/')) {
            $normalised .= '/';
        }

        return $normalised;
    }

    /**
     * Resolve the right-most untrusted address only behind a trusted proxy.
     *
     * @param array<string, mixed> $server
     * @param list<string> $trustedProxies
     */
    private static function resolveClientIp(array $server, array $trustedProxies): string
    {
        $remote = is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '0.0.0.0';
        $forwarded = $server['HTTP_X_FORWARDED_FOR'] ?? null;
        // Normalise the peer first, then consider forwarded entries only if trusted.
        $addresses = [$remote];
        if (is_string($forwarded)) {
            $addresses = array_merge($addresses, array_reverse(explode(',', $forwarded)));
        }
        foreach ($addresses as $index => $address) {
            if (!mb_check_encoding($address, 'UTF-8')) {
                if ($index === 0) {
                    return $remote;
                }

                continue;
            }
            $address = mb_trim($address, null, 'UTF-8');
            if (mb_substr($address, 0, 1, 'UTF-8') === '[') {
                $end = mb_strpos($address, ']:', 0, 'UTF-8');
                if ($end !== false && filter_var(mb_substr($address, $end + 2, null, 'UTF-8'), FILTER_VALIDATE_INT) !== false) {
                    $address = mb_substr($address, 1, $end - 1, 'UTF-8');
                }
            } elseif (mb_substr_count($address, ':', 'UTF-8') === 1) {
                [$host, $port] = explode(':', $address, 2);
                if (filter_var($port, FILTER_VALIDATE_INT) !== false) {
                    $address = $host;
                }
            }
            if ($index === 0) {
                $remote = $address;
                if (!in_array($remote, $trustedProxies, true)) {
                    return $remote;
                }

                continue;
            }
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            if (!in_array($address, $trustedProxies, true)) {
                return $address;
            }
        }

        return $remote;
    }
}
