<?php

declare(strict_types=1);

namespace Shield\Laravel\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Shield\Runtime\Agent\AgentFilesPublisher;
use Shield\Runtime\Agent\AgentTarget;
use Throwable;

/** Publishes configuration and agent instructions and prepares private storage. */
final class InstallCommand extends Command
{
    protected $signature = 'shield:install {--agents=shared : shared, claude, agents, cursor, copilot, all or none} {--force-agents : Replace user-owned agent files}';

    protected $description = 'Publish Shield configuration and prepare storage';

    public function handle(): int
    {
        try {
            $agents = $this->option('agents');
            if (! is_string($agents)) {
                throw new InvalidArgumentException('Agent targets must be a string.');
            }
            $targets = AgentTarget::fromList($agents);
        } catch (InvalidArgumentException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->call('vendor:publish', ['--tag' => 'shield-config']);

        $directory = storage_path('shield');
        try {
            if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
                $this->error('Could not create '.$directory);

                return self::FAILURE;
            }
            @chmod($directory, 0775);
        } catch (Throwable) {
            $this->error('Could not prepare Shield storage.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('Add these flags to your .env file when ready:');
        $this->line('SHIELD_ENABLED=false');
        $this->line('SHIELD_MODE=detect');
        $this->line('SHIELD_REDIS_DB=5');
        $this->line('SHIELD_TIMING=false');
        $this->newLine();
        $this->line('Next: review config/shield.php, configure Redis and the allowlist, then run php artisan shield:status.');

        $results = (new AgentFilesPublisher)->publish(
            base_path(),
            dirname(__DIR__, 4).'/resources/agent',
            $targets,
            (bool) $this->option('force-agents'),
        );
        $failed = false;
        foreach ($results as $result) {
            $this->line($result->status.' '.$result->path.' '.$result->message);
            $failed = $failed || $result->status === 'failed';
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
