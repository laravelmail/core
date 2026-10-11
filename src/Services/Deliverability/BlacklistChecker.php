<?php

declare(strict_types=1);

namespace LaravelMail\Services\Deliverability;

use InvalidArgumentException;

/** Checks an IPv4 address against DNSBL zones. Log-only: never changes sending behaviour. */
class BlacklistChecker
{
    public const DEFAULT_ZONES = ['zen.spamhaus.org', 'bl.spamcop.net', 'b.barracudacentral.org'];

    public function __construct(private DnsblResolver $resolver)
    {
    }

    /** @return array<string, bool> zone => listed */
    public function check(string $ip, array $zones = self::DEFAULT_ZONES): array
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new InvalidArgumentException('Only IPv4 addresses can be checked.');
        }
        $reversed = implode('.', array_reverse(explode('.', $ip)));
        $result = [];
        foreach ($zones as $zone) {
            $result[$zone] = $this->resolver->lookup("{$reversed}.{$zone}") !== [];
        }

        return $result;
    }
}
