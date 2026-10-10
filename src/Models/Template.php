<?php

declare(strict_types=1);

namespace LaravelMail\Models;

use Illuminate\Database\Eloquent\Model;
use LaravelMail\Models\Concerns\BelongsToWorkspace;

class Template extends Model
{
    use BelongsToWorkspace;

    protected string $baseTable = 'templates';
    protected $guarded = [];
}
