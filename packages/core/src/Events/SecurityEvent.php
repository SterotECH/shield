<?php

declare(strict_types=1);

namespace Stero\Shield\Events;

use Stero\Shield\Decision;
use Stero\Shield\Finding;
use Stero\Shield\Http\NormalizedRequest;
use Stero\Shield\Mode;
use Stero\Shield\Rules\Severity;

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
    ) {}

    /**
     * Build an event from a request, one of its findings and the final
     * decision. occurredAt = request->receivedAt, or now (UTC) when null.
     */
    public static function fromFinding(
        NormalizedRequest $request,
        Finding $finding,
        Decision $decision,
        Mode $mode,
    ): self {
        return new self(
            $request->receivedAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            $request->tenantId,
            $request->userId,
            $request->clientIp,
            $request->method,
            $request->path,
            $finding->ruleId,
            $finding->severity,
            $decision->wouldBlock,
            $decision->blocked,
            $mode,
            $finding->message,
            mb_substr($finding->evidence, 0, 200, 'UTF-8'),
        );
    }

    /** @return array<string, scalar|null> */
    public function toArray(): array
    {
        return [
            'ts' => $this->occurredAt->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
            'tenant' => $this->tenantId,
            'user' => $this->userId,
            'ip' => $this->ip,
            'method' => $this->method,
            'path' => $this->path,
            'rule' => $this->ruleId,
            'severity' => $this->severity->value,
            'would_block' => $this->wouldBlock,
            'blocked' => $this->blocked,
            'mode' => $this->mode->value,
            'message' => $this->message,
            'evidence' => mb_substr($this->evidence, 0, 200, 'UTF-8'),
        ];
    }
}
