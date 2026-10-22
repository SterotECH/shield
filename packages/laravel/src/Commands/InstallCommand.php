<?php

declare(strict_types=1);

namespace Shield\Laravel\Commands;

use Illuminate\Console\Command;
use Throwable;

/** Publishes Shield configuration and prepares its private storage folder. */
final class InstallCommand extends Command
{
    protected $signature = 'shield:install';

    protected $description = 'Publish Shield configuration and prepare storage';

    public function handle(): int
    {
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

        return self::SUCCESS;
    }
}
