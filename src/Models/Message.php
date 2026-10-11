<?php

declare(strict_types=1);

namespace LaravelMail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LaravelMail\Models\Concerns\BelongsToWorkspace;

class Message extends Model
{
    use BelongsToWorkspace;

    protected string $baseTable = 'messages';
    protected $guarded = [];
    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'opened_at' => 'datetime', 'clicked_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            $m->hash ??= (string) \Illuminate\Support\Str::uuid();
        });
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }
}
