<?php

declare(strict_types=1);

namespace LaravelMail\Http\Api;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use LaravelMail\Models\IpPool;
use LaravelMail\Models\SendingIp;
use LaravelMail\Services\Deliverability\BlacklistMonitor;

class IpPoolsController
{
    public function pools()
    {
        return IpPool::withCount('ips')->orderBy('name')->get();
    }

    public function storePool(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:100', 'description' => 'nullable|string|max:255']);

        return response()->json(IpPool::firstOrCreate(['name' => $d['name']], ['description' => $d['description'] ?? null]), 201);
    }

    public function destroyPool(int $id)
    {
        $pool = IpPool::findOrFail($id);
        $pool->ips()->update(['ip_pool_id' => null]); // IPs stay, unpooled (does not rely on DB-level FK actions)
        $pool->delete();

        return response()->noContent();
    }

    public function ips()
    {
        return SendingIp::with('pool:id,name')->orderBy('ip')->get();
    }

    public function storeIp(Request $r)
    {
        $d = $r->validate(['ip' => 'required|ipv4', 'hostname' => 'nullable|string|max:255', 'ip_pool_id' => 'nullable|integer|exists:'.(new IpPool)->getTable().',id']);

        return response()->json(SendingIp::firstOrCreate(['ip' => $d['ip']], ['hostname' => $d['hostname'] ?? null, 'ip_pool_id' => $d['ip_pool_id'] ?? null]), 201);
    }

    public function updateIp(Request $r, int $id)
    {
        $ip = SendingIp::findOrFail($id);
        $d = $r->validate(['status' => ['sometimes', Rule::in(['active', 'paused'])], 'ip_pool_id' => 'nullable|integer|exists:'.(new IpPool)->getTable().',id', 'hostname' => 'nullable|string|max:255']);
        $ip->update($d);

        return $ip;
    }

    public function destroyIp(int $id)
    {
        SendingIp::findOrFail($id)->delete();

        return response()->noContent();
    }

    /** Runs the DNS lookups now and records the result. */
    public function check(int $id, BlacklistMonitor $m)
    {
        $ip = SendingIp::findOrFail($id);
        $c = $m->check($ip);

        return ['ip' => $ip->ip, 'status' => $ip->fresh()->status, 'listed_count' => $c->listed_count, 'results' => $c->results];
    }

    public function history(int $id)
    {
        return SendingIp::findOrFail($id)->checks()->orderByDesc('checked_at')->limit(100)->get(['id', 'results', 'listed_count', 'checked_at']);
    }
}
