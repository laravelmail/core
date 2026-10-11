<?php

declare(strict_types=1);

namespace LaravelMail\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationJourney extends Model
{

    protected $baseTable = 'automation_journeys';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['wake_at' => 'datetime', 'log' => 'array'];
    }

    public function automation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    public function subscriber(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Subscriber::class)->withoutGlobalScopes();
    }

    public function getTable()
    {
        return config('laravelmail.table_prefix', 'laravelmail_').$this->baseTable;
    }
}
