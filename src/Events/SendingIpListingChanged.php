<?php

declare(strict_types=1);

namespace LaravelMail\Events;

use LaravelMail\Models\SendingIp;

/** Fired when an IP becomes listed on a DNSBL, or is cleared. Hook it to alert; the package only records. */
class SendingIpListingChanged
{
    public function __construct(public SendingIp $ip, public bool $listed, public array $zones)
    {
    }
}
