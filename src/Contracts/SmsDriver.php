<?php

declare(strict_types=1);

namespace LaravelMail\Contracts;

/**
 * A way to deliver one text message. Implement this for a provider or a device (HTTP gateway, modem, ...).
 * Throw on failure; return the provider's message id (or null) on success. $to is validated E.164.
 */
interface SmsDriver
{
    public function name(): string;

    public function send(string $to, string $body): ?string;
}
