<?php

declare(strict_types=1);

namespace LaravelMail\Models;

use Illuminate\Database\Eloquent\Model;
use LaravelMail\Models\Concerns\BelongsToWorkspace;

class GatewayFailover extends Model
{
    use BelongsToWorkspace;

    protected string $baseTable = 'gateway_failovers';
    protected $guarded = [];
}
