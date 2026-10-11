<?php

declare(strict_types=1);

namespace LaravelMail\Services\Deliverability;

/** Looks up DNSBL A records. Swappable in tests. */
class DnsblResolver
{
    /** @return string[] A-record addresses; empty when the name does not resolve (not listed). */
    public function lookup(string $hostname): array
    {
        $records = @dns_get_record($hostname, DNS_A);

        return is_array($records)
            ? array_values(array_filter(array_map(static fn ($r) => $r['ip'] ?? null, $records)))
            : [];
    }
}
