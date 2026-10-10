<?php

declare(strict_types=1);

namespace LaravelMail\Services;

use LaravelMail\Contracts\WorkspaceResolver;

class NullWorkspaceResolver implements WorkspaceResolver
{
    public function currentId(): int|string|null
    {
        return null;
    }
}
