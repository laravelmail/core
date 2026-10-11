<?php

declare(strict_types=1);

namespace LaravelMail\Exceptions;

use RuntimeException;

class FeatureDisabledException extends RuntimeException
{
    public function __construct(string $feature)
    {
        parent::__construct("LaravelMail feature [{$feature}] is disabled. Enable laravelmail.features.{$feature} to use it.");
    }
}
