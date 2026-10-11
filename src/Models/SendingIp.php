<?php

declare(strict_types=1);

namespace LaravelMail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LaravelMail\Models\Concerns\BelongsToWorkspace;

/** An IP you send from, tracked for blacklist monitoring. Records state only; it never changes how mail is sent. */
class SendingIp extends Model
{
    use BelongsToWorkspace;

    public const ACTIVE = 'active';
    public const PAUSED = 'paused';
    public const LISTED = 'listed';

    protected string $baseTable = 'sending_ips';
    protected $guarded = [];
    protected $attributes = ['status' => self::ACTIVE];

    protected function casts(): array
    {
        return ['listed_zones' => 'array', 'last_checked_at' => 'datetime'];
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(IpPool::class, 'ip_pool_id');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(BlacklistCheck::class);
    }
}
