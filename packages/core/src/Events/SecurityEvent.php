<?php

declare(strict_types=1);

namespace Shield\Core\Events;

use Shield\Core\Decision;
use Shield\Core\Finding;
use Shield\Core\Http\NormalizedRequest;
use Shield\Core\Mode;
use Shield\Core\Rules\Severity;

/**
 * One row of the audit trail: one finding on one request. This is the
 * schema the future dashboard reads, so keep key names stable.
 *
 * toArray() KEYS: ts (ISO 8601 UTC), tenant, user, ip, method, path, rule,
 * severity, would_block, blocked, mode, message, evidence.
 *
 * PRIVACY RULES (Data Protection Act, 2012, Act 843)
 * - Never store the body, cookies, Authorization header, tokens or form
 *   values. Evidence is a short snippet of the path or pattern only.
 * - If the path or query can hold secrets (password reset links), replace
 *   the value with "[redacted]" before it reaches the event.
 * - Plan a retention period (for example 90 days) for wherever sinks write.
 */
final readonly class SecurityEvent
{
    public function __construct(
        public \DateTimeImmutable $occurredAt,
        public ?string $tenantId,
        public ?string $userId,
        public string $ip,
        public string $method,
        public string $path,
        public string $ruleId,
        public Severity $severity,
        public bool $wouldBlock,
        public bool $blocked,
        public Mode $mode,
        public string $message,
        public string $evidence,
    ) {
    }

    /**
     * TODO: build an event from a request, one of its findings and the final
     * decision. occurredAt = request->receivedAt, or now (UTC) when null.
     */
    public static function fromFinding(
        NormalizedRequest $request,
        Finding $finding,
        Decision $decision,
        Mode $mode,
    ): self {
        throw new \LogicException('Not implemented');
    }

    /** @return array<string, scalar|null> TODO: see KEYS above. */
    public function toArray(): array
    {
        throw new \LogicException('Not implemented');
    }
}
