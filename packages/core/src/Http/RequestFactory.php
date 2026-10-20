<?php

declare(strict_types=1);

namespace Shield\Core\Http;

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
        throw new \LogicException('Not implemented');
    }

    /** TODO: see "Path" note above. Pure function, unit test it hard. */
    private static function normalisePath(string $requestUri): string
    {
        throw new \LogicException('Not implemented');
    }

    /** TODO: see "Client IP" note above. Unit test spoofed X-Forwarded-For cases. */
    private static function resolveClientIp(array $server, array $trustedProxies): string
    {
        throw new \LogicException('Not implemented');
    }
}
