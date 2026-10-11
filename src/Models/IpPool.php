<?php

declare(strict_types=1);

namespace LaravelMail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LaravelMail\Models\Concerns\BelongsToWorkspace;

class IpPool extends Model
{
    use BelongsToWorkspace;

    protected string $baseTable = 'ip_pools';
    protected $guarded = [];

    public function ips(): HasMany
    {
        return $this->hasMany(SendingIp::class);
    }
}
