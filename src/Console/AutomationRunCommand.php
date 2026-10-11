<?php

declare(strict_types=1);

namespace LaravelMail\Console;

use Illuminate\Console\Command;
use LaravelMail\Services\Automations\FlowEngine;

class AutomationRunCommand extends Command
{
    protected $signature = 'laravelmail:automation-run';
    protected $description = 'Process due automation journeys (requires features.automations).';

    public function handle(FlowEngine $engine): int
    {
        if (! config('laravelmail.features.automations')) {
            $this->warn('features.automations is off; nothing to do.');

            return self::SUCCESS;
        }
        $this->info('Processed '.$engine->tick().' journeys.');

        return self::SUCCESS;
    }
}
