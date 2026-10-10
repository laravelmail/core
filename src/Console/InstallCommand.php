<?php

declare(strict_types=1);

namespace LaravelMail\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'laravelmail:install';
    protected $description = 'Publish LaravelMail config and migrations. Does not enable sending.';

    public function handle(): int
    {
        $this->callSilent('vendor:publish', ['--tag' => 'laravelmail-config']);
        $this->callSilent('vendor:publish', ['--tag' => 'laravelmail-migrations']);
        $this->info('Published config/laravelmail.php and migrations.');
        $this->line('Next: php artisan migrate');
        $this->warn('Sending is OFF. Set LARAVELMAIL_SENDING_ENABLED=true only when ready.');

        return self::SUCCESS;
    }
}
