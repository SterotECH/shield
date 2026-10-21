<?php

declare(strict_types=1);

namespace ShieldTests;

use Stero\Shield\Events\EventSink;
use Stero\Shield\Events\SecurityEvent;
use Stero\Shield\Finding;
use Stero\Shield\Http\NormalizedRequest;
use Stero\Shield\Rules\Phase;
use Stero\Shield\Rules\Rule;
use Stero\Shield\Store\ArrayStore;
use Stero\Shield\Store\Store;

final class FakeRule implements Rule
{
    public int $calls = 0;
    public function __construct(private ?Finding $finding = null, private Phase $phase = Phase::Inline) {}
    public function id(): string
    {
        return 'fake';
    }
    public function phase(): Phase
    {
        return $this->phase;
    }
    public function inspect(NormalizedRequest $request): ?Finding
    {
        $this->calls++;
        return $this->finding;
    }
}

final class ThrowingRule implements Rule
{
    public function __construct(private bool $throwFromPhase = false) {}
    public function id(): string
    {
        return 'throwing';
    }
    public function phase(): Phase
    {
        if ($this->throwFromPhase) {
            throw new \RuntimeException('phase failure');
        }
        return Phase::Inline;
    }
    public function inspect(NormalizedRequest $request): ?Finding
    {
        throw new \RuntimeException('rule failure');
    }
}

final class ThrowingStore implements Store
{
    public array $calls = [];
    public function __construct(private string $throwOn = '', private ?int $count = null, private Store $inner = new ArrayStore()) {}
    public function increment(string $key, int $windowSeconds): int
    {
        $this->calls[] = 'increment';
        if ($this->throwOn === 'increment') {
            throw new \RuntimeException('increment failure');
        }
        return $this->count ?? $this->inner->increment($key, $windowSeconds);
    }
    public function ban(string $ip, int $seconds): void
    {
        $this->calls[] = 'ban';
        if ($this->throwOn === 'ban') {
            throw new \RuntimeException('ban failure');
        }
        $this->inner->ban($ip, $seconds);
    }
    public function isBanned(string $ip): bool
    {
        $this->calls[] = 'isBanned';
        if ($this->throwOn === 'isBanned') {
            throw new \RuntimeException('lookup failure');
        }
        return $this->inner->isBanned($ip);
    }
    public function unban(string $ip): void
    {
        $this->inner->unban($ip);
    }
}

final class ThrowingSink implements EventSink
{
    public int $calls = 0;
    public function record(SecurityEvent $event): void
    {
        $this->calls++;
        throw new \RuntimeException('sink failure');
    }
}
