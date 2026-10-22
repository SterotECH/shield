<?php

declare(strict_types=1);

namespace Shield\Laravel\Commands;

use Illuminate\Console\Command;
use Shield\Runtime\Settings;
use Stero\Shield\CLI\Application;
use Symfony\Component\Console\Input\ArrayInput;

/** Translate Artisan options to the framework-neutral event viewer. */
final class LogsCommand extends Command
{
    protected $signature = 'shield:logs
        {--follow : Follow new events until interrupted}
        {--limit=50 : Number of recent matching events, or initial events with --follow}
        {--since= : Relative duration or ISO 8601 time}
        {--rule= : Exact rule id}
        {--ip= : Exact client IP}
        {--tenant= : Exact tenant}
        {--severity= : Minimum severity}
        {--would-block : Only events that would block}
        {--json : Print matching raw JSON lines}
        {--theme= : auto, light, dark or plain}';

    protected $description = 'View Shield security events from the configured events file';

    public function handle(Settings $settings): int
    {
        $follow = $this->option('follow') === true;
        $options = ['command' => $follow ? 'tail' : 'events', '--file' => $settings->eventsPath];
        foreach (['since', 'rule', 'ip', 'tenant', 'severity', 'would-block'] as $name) {
            $value = $this->option($name);
            if ($value !== null && $value !== false) {
                if ($follow) {
                    $this->error('Filters cannot be used with --follow.');

                    return self::FAILURE;
                }
                $options['--'.$name] = $value;
            }
        }
        $options[$follow ? '--lines' : '--limit'] = $this->option('limit');
        $options['--json'] = $this->option('json');
        if ($this->option('theme') !== null) {
            $options['--theme'] = $this->option('theme');
        }
        if (! $this->output->isDecorated()) {
            $options['--no-ansi'] = true;
        }

        return (new Application)->run(new ArrayInput($options), $this->output);
    }
}
