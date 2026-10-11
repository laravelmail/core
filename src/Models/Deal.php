<?php

declare(strict_types=1);

namespace LaravelMail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LaravelMail\Models\Concerns\BelongsToWorkspace;

/** CRM-lite deal. Stage names match the app so data is portable. */
class Deal extends Model
{
    use BelongsToWorkspace;

    public const STAGES = ['lead' => 'Lead', 'qualified' => 'Qualified', 'proposal' => 'Proposal', 'won' => 'Won', 'lost' => 'Lost'];
    public const CLOSED = ['won', 'lost'];

    protected string $baseTable = 'deals';
    protected $guarded = [];
    protected $attributes = ['stage' => 'lead', 'currency' => 'USD', 'value' => 0];

    protected function casts(): array
    {
        return ['value' => 'decimal:2', 'expected_close_on' => 'date', 'closed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (Deal $d) {
            if (! array_key_exists($d->stage, self::STAGES)) {
                throw new \InvalidArgumentException("Unknown deal stage [{$d->stage}].");
            }
            if ($d->isDirty('stage') || ! $d->exists) {
                $d->closed_at = in_array($d->stage, self::CLOSED, true) ? ($d->closed_at ?? now()) : null;
            }
            $d->currency = strtoupper((string) $d->currency);
        });
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function isOpen(): bool
    {
        return ! in_array($this->stage, self::CLOSED, true);
    }
}
