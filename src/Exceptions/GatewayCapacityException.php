<?php

declare(strict_types=1);

namespace LaravelMail\Exceptions;

use RuntimeException;

class GatewayCapacityException extends RuntimeException
{
    public function __construct(string $message, public int $retryAfterSeconds = 3600)
    {
        parent::__construct($message);
    }
}
