<?php

declare(strict_types=1);

namespace LaravelMail\Services\Deliverability;

use LaravelMail\Models\Gateway;
use LaravelMail\Models\Message;
use LaravelMail\Models\WarmingPlan;

class WarmingService
{
    public const DEFAULT_DAYS = 28;
    public const MIN_SAMPLE = 100;

    /** Geometric ramp from $start to $end over $days, rounded to friendly numbers. */
    public static function defaultSchedule(int $days = self::DEFAULT_DAYS, int $start = 50, int $end = 100000): array
    {
        $schedule = [];
        for ($d = 0; $d < $days; $d++) {
            $schedule[] = self::roundFriendly($start * (($end / $start) ** ($d / max($days - 1, 1))));
        }
        $schedule[$days - 1] = $end;

        return $schedule;
    }

    public function create(Gateway $gateway, array $attrs = []): WarmingPlan
    {
        return WarmingPlan::create([
            'workspace_id' => $gateway->workspace_id,
            'gateway_id' => $gateway->id,
            'name' => $attrs['name'] ?? 'Warm-up '.$gateway->name,
            'schedule' => array_values(array_map('intval', $attrs['schedule'] ?? self::defaultSchedule())),
            'current_day' => 1,
            'status' => WarmingPlan::ACTIVE,
            'pause_complaint_rate' => $attrs['pause_complaint_rate'] ?? 0.003,
            'started_on' => $attrs['started_on'] ?? now()->toDateString(),
        ]);
    }

    /** Move to the next day (at most once per calendar day). Pauses on a complaint spike; completes on the last day. */
    public function advance(WarmingPlan $plan): WarmingPlan
    {
        if ($plan->status !== WarmingPlan::ACTIVE || ($plan->last_advanced_at && $plan->last_advanced_at->isSameDay(now()))) {
            return $plan;
        }

        $rate = $this->complaintRate((int) $plan->gateway_id);
        if ($rate !== null && $rate > $plan->pause_complaint_rate) {
            $plan->update(['status' => WarmingPlan::PAUSED, 'paused_reason' => sprintf(
                'Complaint rate %.3f%% over the last 24h is above %.3f%%', $rate * 100, $plan->pause_complaint_rate * 100)]);

            return $plan;
        }
        if ($plan->current_day >= $plan->totalDays()) {
            $plan->update(['status' => WarmingPlan::COMPLETED, 'last_advanced_at' => now()]);

            return $plan;
        }
        $plan->update(['current_day' => $plan->current_day + 1, 'last_advanced_at' => now()]);

        return $plan;
    }

    public function pause(WarmingPlan $plan, string $reason = 'Paused manually'): WarmingPlan
    {
        $plan->update(['status' => WarmingPlan::PAUSED, 'paused_reason' => $reason]);

        return $plan;
    }

    public function resume(WarmingPlan $plan): WarmingPlan
    {
        if ($plan->status === WarmingPlan::PAUSED) {
            $plan->update(['status' => WarmingPlan::ACTIVE, 'paused_reason' => null, 'last_advanced_at' => now()]);
        }

        return $plan;
    }

    /** Complaints / sent over 24h; null when the sample is too small to judge. */
    public function complaintRate(int $gatewayId): ?float
    {
        $since = now()->subDay();
        $base = Message::withoutGlobalScopes()->where('gateway_id', $gatewayId)->where('sent_at', '>=', $since);
        $sent = (clone $base)->count();
        if ($sent < self::MIN_SAMPLE) {
            return null;
        }

        return (clone $base)->whereNotNull('complained_at')->count() / $sent;
    }

    private static function roundFriendly(float $v): int
    {
        if ($v < 100) {
            return (int) (round($v / 10) * 10);
        }
        $mag = 10 ** (strlen((string) (int) $v) - 2);

        return (int) (round($v / $mag) * $mag);
    }
}
