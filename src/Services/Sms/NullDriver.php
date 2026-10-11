<?php

declare(strict_types=1);

namespace LaravelMail\Services\Sms;

use LaravelMail\Contracts\SmsDriver;

class NullDriver implements SmsDriver
{
    public function name(): string
    {
        return 'null';
    }

    public function send(string $to, string $body): ?string
    {
        return null;
    }
}
