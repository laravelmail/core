<?php

declare(strict_types=1);

namespace LaravelMail;

use Illuminate\Console\Scheduling\Schedule;

/**
 * Host-owned scheduling: the package never schedules anything itself.
 * Call LaravelMail::schedule($schedule) from your own scheduler to opt in.
 * Registers only commands whose feature flag is on (none exist in slice 1 beyond sending, which is queue-driven).
 */
class LaravelMail
{
    public static function schedule(Schedule $schedule): void
    {
        if (config('laravelmail.features.deliverability')) {
            $schedule->command('laravelmail:warming-advance')->dailyAt('00:05')->withoutOverlapping();
        }
        if (config('laravelmail.features.automations')) {
            $schedule->command('laravelmail:automation-run')->everyMinute()->withoutOverlapping();
        }
    }
}
