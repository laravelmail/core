<?php

declare(strict_types=1);

namespace LaravelMail\Models;

use Illuminate\Database\Eloquent\Model;
use LaravelMail\Models\Concerns\BelongsToWorkspace;

class ScoreEvent extends Model
{
    use BelongsToWorkspace;

    public $timestamps = false;
    protected string $baseTable = 'score_events';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
