<?php

declare(strict_types=1);

namespace LaravelMail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use LaravelMail\Models\Concerns\BelongsToWorkspace;

class Tag extends Model
{
    use BelongsToWorkspace;

    protected string $baseTable = 'tags';
    protected $guarded = [];

    public function subscribers(): BelongsToMany
    {
        return $this->belongsToMany(Subscriber::class, config('laravelmail.table_prefix').'subscriber_tag');
    }
}
