<?php

declare(strict_types=1);

namespace LaravelMail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LaravelMail\Models\Concerns\BelongsToWorkspace;

class WarmingPlan extends Model
{
    use BelongsToWorkspace;

    public const ACTIVE = 'active';
    public const PAUSED = 'paused';
    public const COMPLETED = 'completed';

    protected string $baseTable = 'warming_plans';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['schedule' => 'array', 'last_advanced_at' => 'datetime', 'started_on' => 'date', 'pause_complaint_rate' => 'float'];
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class);
    }

    public function totalDays(): int
    {
        return count((array) $this->schedule);
    }

    /** Daily cap for the current day; the last day's cap sticks once the plan completes. */
    public function currentCap(): ?int
    {
        $s = array_values((array) $this->schedule);
        if ($s === []) {
            return null;
        }
        $i = min(max($this->current_day, 1), count($s)) - 1;

        return (int) $s[$i];
    }
}
