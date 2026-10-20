<?php

declare(strict_types=1);

namespace Stero\Shield\Http;

/**
 * The only shape of request the engine ever sees. It knows nothing about
 * Laravel, PSR-7 or $_SERVER. Each host app builds one of these (the core
 * provides RequestFactory for plain PHP).
 *
 * FIELD RULES
 * - $path: decoded once, no query string, duplicate slashes collapsed,
 *   always starts with "/". Rules match against this, never raw input.
 * - $query: parsed query parameters.
 * - $headers: names lowercased, for example "user-agent".
 * - $body: first N bytes only (default 16 KB). $bodyTruncated tells rules
 *   the body was cut.
 * - $clientIp: the real client IP, resolved by RequestFactory.
 * - $tenantId / $userId: filled by the host app when known, else null.
 * - $receivedAt: when null, treat as "now".
 */
final readonly class NormalizedRequest
{
    /**
     * @param  array<string, string|array<mixed>>  $query
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query,
        public array $headers,
        public string $body,
        public bool $bodyTruncated,
        public string $clientIp,
        public ?string $tenantId = null,
        public ?string $userId = null,
        public ?\DateTimeImmutable $receivedAt = null,
    ) {}

    /** Return the header value for a case-insensitive name, or null. */
    public function header(string $name): ?string
    {
        return $this->headers[mb_strtolower($name, 'UTF-8')] ?? null;
    }

    /**
     * Return all query values flattened into one string
     * ("a=1&b=x"), for rules that scan the whole query for patterns.
     */
    public function queryString(): string
    {
        return urldecode(http_build_query($this->query, '', '&'));
    }
}
