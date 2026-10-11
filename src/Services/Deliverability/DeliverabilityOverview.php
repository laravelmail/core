<?php

declare(strict_types=1);

namespace LaravelMail\Services\Deliverability;

use LaravelMail\Models\Gateway;
use LaravelMail\Models\GatewayFailover;
use LaravelMail\Models\WarmingPlan;

/** Read-only snapshot per gateway: usage vs caps, warming state, recent failovers. */
class DeliverabilityOverview
{
    public function __construct(private GatewayRouter $router, private WarmingService $warming)
    {
    }

    public function snapshot(): array
    {
        return Gateway::query()->orderBy('priority')->orderBy('id')->get()->map(function (Gateway $g) {
            $usage = $this->router->usage($g);
            $plan = WarmingPlan::where('gateway_id', $g->id)->latest('id')->first();

            return [
                'id' => $g->id, 'name' => $g->name, 'active' => $g->active, 'failover_enabled' => $g->failover_enabled,
                'sent_hour' => $usage['hour'], 'sent_day' => $usage['day'],
                'hourly_limit' => $g->hourly_limit, 'daily_limit' => $g->daily_limit,
                'warming_cap' => $this->router->warmingCap($g),
                'capped' => $this->router->capReason($g),
                'warming_status' => $plan?->status, 'warming_day' => $plan?->current_day,
                'complaint_rate_24h' => $this->warming->complaintRate($g->id),
                'failovers_24h' => GatewayFailover::where('from_gateway_id', $g->id)->where('created_at', '>=', now()->subDay())->count(),
            ];
        })->all();
    }
}
