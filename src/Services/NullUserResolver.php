<?php

declare(strict_types=1);

namespace LaravelMail\Services;

use LaravelMail\Contracts\UserResolver;

class NullUserResolver implements UserResolver
{
    public function current(): mixed
    {
        return null;
    }
}
