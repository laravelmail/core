<?php

declare(strict_types=1);

namespace LaravelMail\Models;

use Illuminate\Database\Eloquent\Model;
use LaravelMail\Models\Concerns\BelongsToWorkspace;

class Segment extends Model
{
    use BelongsToWorkspace;

    protected string $baseTable = 'segments';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['rules' => 'array'];
    }
}
