<?php

declare(strict_types=1);

namespace LaravelMail\Services\Deliverability;

use Illuminate\Support\Collection;
use LaravelMail\Exceptions\GatewayCapacityException;
use LaravelMail\Models\Gateway;
use LaravelMail\Models\GatewayFailover;
use LaravelMail\Models\Message;
use LaravelMail\Models\WarmingPlan;
use Throwable;

/** Chooses a gateway for a message honouring hourly/daily/warming caps, with optional priority failover. */
class GatewayRouter
{
    public const REASON_ERROR = 'error';
    public const REASON_HOURLY = 'hourly_cap';
    public const REASON_DAILY = 'daily_cap';
    public const REASON_WARMING = 'warming_cap';

    /**
     * @param callable(Gateway): mixed $send performs the send through the given gateway
     * @return array{0: mixed, 1: Gateway}
     */
    public function send(Message $message, Gateway $primary, callable $send, bool $allowFailover = true): array
    {
        $candidates = $allowFailover ? $this->candidates($primary) : collect([$primary]);
        $lastError = null;
        $capped = false;
        $prev = null;
        $prevReason = null;

        foreach ($candidates as $gateway) {
            if ($prev !== null) {
                $this->logFailover($message, $prev, $gateway, $prevReason, $lastError);
            }
            if ($reason = $this->capReason($gateway)) {
                $capped = true;
                [$prev, $prevReason, $lastError] = [$gateway, $reason, null];
                continue;
            }
            try {
                $result = $send($gateway);
                $message->forceFill(['gateway_id' => $gateway->id])->save();

                return [$result, $gateway];
            } catch (Throwable $e) {
                [$prev, $prevReason, $lastError] = [$gateway, self::REASON_ERROR, $e];
            }
        }

        if ($prev !== null && $candidates->count() > 1) {
            $this->logFailover($message, $prev, null, $prevReason, $lastError);
        }
        if ($lastError !== null) {
            throw $lastError;
        }
        if ($capped) {
            throw new GatewayCapacityException('All gateways are at their sending cap.', $this->secondsUntilCapacity());
        }
        throw new \RuntimeException('No gateway available.');
    }

    /** Primary first, then other active failover-enabled gateways by priority. Inactive gateways never send. */
    public function candidates(Gateway $primary): Collection
    {
        $list = collect($primary->active ? [$primary] : []);
        if (! $primary->failover_enabled) {
            return $list;
        }

        return $list->merge(Gateway::query()->where('active', true)->where('failover_enabled', true)
            ->whereKeyNot($primary->id)->orderBy('priority')->orderBy('id')->get());
    }

    public function capReason(Gateway $g): ?string
    {
        $u = $this->usage($g);
        if ($g->hourly_limit !== null && $u['hour'] >= $g->hourly_limit) {
            return self::REASON_HOURLY;
        }
        if ($g->daily_limit !== null && $u['day'] >= $g->daily_limit) {
            return self::REASON_DAILY;
        }
        $w = $this->warmingCap($g);

        return $w !== null && $u['day'] >= $w ? self::REASON_WARMING : null;
    }

    /** @return array{hour:int, day:int} */
    public function usage(Gateway $g): array
    {
        $base = Message::withoutGlobalScopes()->where('gateway_id', $g->id);

        return [
            'hour' => (clone $base)->where('sent_at', '>=', now()->startOfHour())->count(),
            'day' => (clone $base)->where('sent_at', '>=', now()->startOfDay())->count(),
        ];
    }

    /** Lowest cap among active or paused plans (a paused plan keeps its cap: pausing never lifts limits). */
    public function warmingCap(Gateway $g): ?int
    {
        $caps = WarmingPlan::withoutGlobalScopes()->where('gateway_id', $g->id)
            ->whereIn('status', [WarmingPlan::ACTIVE, WarmingPlan::PAUSED])->get()
            ->map(fn (WarmingPlan $p) => $p->currentCap())->filter(fn ($c) => $c !== null);

        return $caps->isEmpty() ? null : (int) $caps->min();
    }

    private function secondsUntilCapacity(): int
    {
        return max(60, (int) now()->diffInSeconds(now()->addHour()->startOfHour(), true) + 30);
    }

    private function logFailover(Message $m, Gateway $from, ?Gateway $to, ?string $reason, ?Throwable $e): void
    {
        GatewayFailover::withoutGlobalScopes()->create([
            'workspace_id' => $m->workspace_id, 'message_id' => $m->id,
            'from_gateway_id' => $from->id, 'to_gateway_id' => $to?->id,
            'reason' => $reason ?? self::REASON_ERROR, 'error' => $e ? mb_substr($e->getMessage(), 0, 2000) : null,
        ]);
    }
}
