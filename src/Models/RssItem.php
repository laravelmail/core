<?php

declare(strict_types=1);

namespace LaravelMail\Models;

use Illuminate\Database\Eloquent\Model;
use LaravelMail\Models\Concerns\BelongsToWorkspace;

class RssItem extends Model
{
    use BelongsToWorkspace;

    protected string $baseTable = 'rss_items';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }
}
