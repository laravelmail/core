<?php

declare(strict_types=1);

namespace LaravelMail\Contracts;

/**
 * Host apps implement this to scope all package data to a tenant/workspace.
 * Return null for single-tenant installs.
 */
interface WorkspaceResolver
{
    public function currentId(): int|string|null;
}
