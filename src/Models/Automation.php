<?php

declare(strict_types=1);

namespace LaravelMail\Models;

use Illuminate\Database\Eloquent\Model;
use LaravelMail\Models\Concerns\BelongsToWorkspace;

class Automation extends Model
{
    use BelongsToWorkspace;

    protected $baseTable = 'automations';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'bool'];
    }

    public function steps(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AutomationStep::class);
    }

    public function journeys(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AutomationJourney::class);
    }

}
