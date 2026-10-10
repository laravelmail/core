<?php

declare(strict_types=1);

namespace LaravelMail\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LaravelMail\Models\Concerns\BelongsToWorkspace;

class Campaign extends Model
{
    use BelongsToWorkspace;

    public const DRAFT = 'draft';
    public const QUEUED = 'queued';
    public const SENDING = 'sending';
    public const SENT = 'sent';
    public const CANCELLED = 'cancelled';

    protected string $baseTable = 'campaigns';
    protected $guarded = [];
    protected $attributes = ['status' => self::DRAFT];

    protected function casts(): array
    {
        return ['send_to_all' => 'bool', 'scheduled_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, config('laravelmail.table_prefix').'campaign_tag');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    /** Subscribers this campaign targets, before suppression is applied. */
    public function audience(): Builder
    {
        $q = Subscriber::query()->where('status', 'subscribed');

        if (! $this->send_to_all) {
            $tagIds = $this->tags()->pluck(config('laravelmail.table_prefix').'tags.id');
            $q->whereHas('tags', fn ($t) => $t->whereIn(config('laravelmail.table_prefix').'tags.id', $tagIds));
        }

        return $q;
    }
}
