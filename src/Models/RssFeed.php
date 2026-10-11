<?php

declare(strict_types=1);

namespace LaravelMail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LaravelMail\Models\Concerns\BelongsToWorkspace;

class RssFeed extends Model
{
    use BelongsToWorkspace;

    protected string $baseTable = 'rss_feeds';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'bool', 'last_fetched_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(RssItem::class);
    }
}
