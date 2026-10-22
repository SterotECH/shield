<?php

declare(strict_types=1);

namespace Shield\Runtime;

use Stero\Shield\Engine;
use Stero\Shield\Http\NormalizedRequest;
use Stero\Shield\Rules\Phase;
use Throwable;

/** Framework-neutral request lifecycle and fail-open boundary. */
final class Guard
{
    public const string BLOCK_BODY = 'Not Found';

    public function __construct(
        private readonly Settings $settings,
        private readonly Engine $engine,
        private readonly ?TimingRecorder $timingRecorder = null,
    ) {}

    public function isEnabled(): bool
    {
        return $this->settings->enabled;
    }

    public function shouldSkip(string $path): bool
    {
        try {
            if (! mb_check_encoding($path, 'UTF-8')) {
                return false;
            }
            if (array_any($this->settings->skipPrefixes, fn($prefix) => mb_check_encoding($prefix, 'UTF-8') && mb_strpos($path, $prefix, 0, 'UTF-8') === 0)) {
                return true;
            }
        } catch (Throwable) {
        }

        return false;
    }

    public function before(NormalizedRequest $request): Inspection
    {
        if (! $this->isEnabled() || $this->shouldSkip($request->path)) {
            return Inspection::allow();
        }
        try {
            $start = hrtime(true);
            $decision = $this->engine->inspect($request, Phase::Inline);

            return new Inspection($decision, intdiv(hrtime(true) - $start, 1000), false);
        } catch (Throwable) {
            return Inspection::allow();
        }
    }

    public function after(NormalizedRequest $request, ?string $userId, ?Inspection $inspection): void
    {
        if (! $this->settings->enabled || $inspection?->skipped === true) {
            return;
        }
        try {
            $deferred = new NormalizedRequest(
                $request->method,
                $request->path,
                $request->query,
                $request->headers,
                $request->body,
                $request->bodyTruncated,
                $request->clientIp,
                $request->tenantId,
                $userId,
                $request->receivedAt,
            );
            $start = hrtime(true);
            $this->engine->inspect($deferred, Phase::Deferred);
            if ($this->settings->timing['enabled'] && $this->timingRecorder !== null) {
                $inlineMicros = $inspection->inlineMicros ?? 0;
                $deferredMicros = intdiv(hrtime(true) - $start, 1000);
                $this->timingRecorder->record($inlineMicros, $deferredMicros);
            }
        } catch (Throwable) {
        }
    }

    /** @return array{Content-Type: string, Cache-Control: string} */
    public static function blockHeaders(): array
    {
        return ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store, private'];
    }
}
