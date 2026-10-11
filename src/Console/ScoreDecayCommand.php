<?php

declare(strict_types=1);

namespace LaravelMail\Console;

use Illuminate\Console\Command;
use LaravelMail\Services\Scoring\ScoringService;

class ScoreDecayCommand extends Command
{
    protected $signature = 'laravelmail:score-decay {--days=30 : inactive for this many days} {--points=5 : points to remove}';
    protected $description = 'Reduce lead scores of inactive subscribers (requires features.scoring). Not scheduled for you.';

    public function handle(ScoringService $s): int
    {
        if (! $s->enabled()) {
            $this->warn('features.scoring is off; nothing to do.');

            return self::SUCCESS;
        }
        $this->info('Decayed '.$s->decay((int) $this->option('days'), (int) $this->option('points')).' subscriber(s).');

        return self::SUCCESS;
    }
}
