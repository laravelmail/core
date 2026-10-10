<?php

declare(strict_types=1);

namespace LaravelMail\Contracts;

interface UserResolver
{
    /** The current host-app user, or null. */
    public function current(): mixed;
}
