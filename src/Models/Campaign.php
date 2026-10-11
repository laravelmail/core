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

    public function segments(): BelongsToMany
    {
        return $this->belongsToMany(Segment::class, config('laravelmail.table_prefix').'campaign_segment')
            ->wherePivot('excluded', false)->withPivot('excluded');
    }

    public function excludedSegments(): BelongsToMany
    {
        return $this->belongsToMany(Segment::class, config('laravelmail.table_prefix').'campaign_segment')
            ->wherePivot('excluded', true)->withPivot('excluded');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(CampaignVariant::class);
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
            $compiler = app(\LaravelMail\Services\Segments\SegmentRuleCompiler::class);
            $segments = $this->segments()->get();
            // Targets are tags OR segments (either matches).
            $q->where(function ($w) use ($tagIds, $segments, $compiler) {
                $w->whereHas('tags', fn ($t) => $t->whereIn(config('laravelmail.table_prefix').'tags.id', $tagIds));
                foreach ($segments as $seg) {
                    $w->orWhereIn('id', $compiler->idSubquery((array) $seg->rules));
                }
            });
        }

        foreach ($this->excludedSegments()->get() as $seg) {
            $q->whereNotIn('id', app(\LaravelMail\Services\Segments\SegmentRuleCompiler::class)->idSubquery((array) $seg->rules));
        }

        return $q;
    }
}
