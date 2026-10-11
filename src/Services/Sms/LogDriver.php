<?php

declare(strict_types=1);

namespace LaravelMail\Services\Sms;

use Illuminate\Support\Facades\Log;
use LaravelMail\Contracts\SmsDriver;

/** Default driver: writes to the log, delivers nothing. */
class LogDriver implements SmsDriver
{
    public function name(): string
    {
        return 'log';
    }

    public function send(string $to, string $body): ?string
    {
        Log::info('laravelmail.sms (not delivered, log driver)', ['to' => substr($to, 0, 4).'***'.substr($to, -2), 'length' => mb_strlen($body)]);

        return null;
    }
}
