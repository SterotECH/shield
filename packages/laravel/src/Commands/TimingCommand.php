<?php

declare(strict_types=1);

namespace Stero\Shield\Laravel\Commands;

use Illuminate\Console\Command;
use Stero\Shield\Runtime\Settings;
use Stero\Shield\Runtime\TimingReport;

/** Reports sampled Shield latency against the 5 ms inline p95 budget. */
final class TimingCommand extends Command
{
    protected $signature = 'shield:timing';

    protected $description = 'Report Shield timing samples and the inline budget';

    public function handle(Settings $settings): int
    {
        $path = $settings->timing['path'];
        if (! is_file($path)) {
            $this->line('Timing file does not exist. Enable SHIELD_TIMING to collect samples.');

            return self::SUCCESS;
        }
        if (! is_readable($path)) {
            $this->error('Timing file is unreadable.');

            return self::FAILURE;
        }

        $report = TimingReport::fromFile($path);
        if ($report->count === 0) {
            $this->line('No timing samples.');

            return self::SUCCESS;
        }

        $this->line('Samples: '.$report->count);
        foreach ([50, 95, 99] as $percentile) {
            $inline = match ($percentile) {
                50 => $report->inlineP50Ms,
                95 => $report->inlineP95Ms,
                default => $report->inlineP99Ms,
            };
            $deferred = match ($percentile) {
                50 => $report->deferredP50Ms,
                95 => $report->deferredP95Ms,
                default => $report->deferredP99Ms,
            };
            $this->line(sprintf('Inline p%d: %.3f ms', $percentile, $inline));
            $this->line(sprintf('Deferred p%d: %.3f ms', $percentile, $deferred));
        }
        $passed = $report->passesBudget();
        $this->line(($passed ? 'PASS' : 'FAIL').' (inline p95 budget: 5 ms)');

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
