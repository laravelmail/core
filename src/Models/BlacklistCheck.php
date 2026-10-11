<?php

declare(strict_types=1);

namespace LaravelMail\Models;

use Illuminate\Database\Eloquent\Model;
use LaravelMail\Models\Concerns\BelongsToWorkspace;

class BlacklistCheck extends Model
{
    use BelongsToWorkspace;

    public $timestamps = false;
    protected string $baseTable = 'blacklist_checks';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['results' => 'array', 'checked_at' => 'datetime'];
    }
}
