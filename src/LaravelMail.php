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
        // Slice 1 has no scheduled commands. Later slices register campaign dispatch, automations, etc. here,
        // each guarded by config('laravelmail.features.*').
    }
}
