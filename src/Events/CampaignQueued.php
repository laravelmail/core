<?php

declare(strict_types=1);

namespace LaravelMail\Events;

use LaravelMail\Models\Campaign;

class CampaignQueued
{
    public function __construct(public Campaign $campaign, public int $recipients)
    {
    }
}
