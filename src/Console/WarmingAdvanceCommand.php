<?php

declare(strict_types=1);

namespace LaravelMail\Console;

use Illuminate\Console\Command;
use LaravelMail\Models\WarmingPlan;
use LaravelMail\Services\Deliverability\WarmingService;

class WarmingAdvanceCommand extends Command
{
    protected $signature = 'laravelmail:warming-advance';
    protected $description = 'Advance active warming plans by one day (requires features.deliverability).';

    public function handle(WarmingService $svc): int
    {
        if (! config('laravelmail.features.deliverability')) {
            $this->warn('features.deliverability is off; nothing to do.');

            return self::SUCCESS;
        }
        WarmingPlan::where('status', WarmingPlan::ACTIVE)->each(fn ($p) => $svc->advance($p));
        $this->info('Warming plans advanced.');

        return self::SUCCESS;
    }
}
