<?php

declare(strict_types=1);

namespace LaravelMail\Exceptions;

use RuntimeException;

class SendingDisabledException extends RuntimeException
{
    public static function make(): self
    {
        return new self('LaravelMail sending is disabled. Set laravelmail.features.sending (LARAVELMAIL_SENDING_ENABLED=true) to allow it.');
    }
}
