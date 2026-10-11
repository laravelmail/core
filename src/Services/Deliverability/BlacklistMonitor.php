<?php

declare(strict_types=1);

namespace LaravelMail\Services\Deliverability;

use LaravelMail\Events\SendingIpListingChanged;
use LaravelMail\Models\BlacklistCheck;
use LaravelMail\Models\SendingIp;

/** Runs DNSBL checks for tracked IPs and persists history. Informational: sending is never altered. */
class BlacklistMonitor
{
    public function __construct(private BlacklistChecker $checker)
    {
    }

    public function zones(): array
    {
        return config('laravelmail.deliverability.dnsbl_zones') ?: BlacklistChecker::DEFAULT_ZONES;
    }

    public function check(SendingIp $ip): BlacklistCheck
    {
        $results = $this->checker->check($ip->ip, $this->zones());
        $listed = array_keys(array_filter($results));
        $was = $ip->status === SendingIp::LISTED;

        $check = BlacklistCheck::create(['sending_ip_id' => $ip->id, 'workspace_id' => $ip->workspace_id, 'results' => $results,
            'listed_count' => count($listed), 'checked_at' => now()]);

        $ip->forceFill([
            'listed_zones' => $listed,
            'last_checked_at' => now(),
            // A paused IP stays paused; only active <-> listed flips automatically.
            'status' => $ip->status === SendingIp::PAUSED ? SendingIp::PAUSED : ($listed ? SendingIp::LISTED : SendingIp::ACTIVE),
        ])->save();

        if ($ip->status !== SendingIp::PAUSED && ($listed !== []) !== $was) {
            event(new SendingIpListingChanged($ip, $listed !== [], $listed));
        }

        return $check;
    }

    /** @return int number of IPs checked */
    public function checkAll(): int
    {
        $n = 0;
        SendingIp::where('status', '!=', 'paused')->orWhereNull('status')->each(function (SendingIp $ip) use (&$n) {
            $this->check($ip);
            $n++;
        });

        return $n;
    }
}
