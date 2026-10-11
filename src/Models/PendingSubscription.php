<?php

declare(strict_types=1);

namespace LaravelMail\Models;

use Illuminate\Database\Eloquent\Model;
use LaravelMail\Models\Concerns\BelongsToWorkspace;

class PendingSubscription extends Model
{
    use BelongsToWorkspace;

    protected $baseTable = 'pending_subscriptions';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'confirmed_at' => 'datetime'];
    }

}
