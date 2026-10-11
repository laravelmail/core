<?php

declare(strict_types=1);

namespace LaravelMail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LaravelMail\Models\Concerns\BelongsToWorkspace;

/** A/B variant: overrides subject and/or content for a weighted share of recipients. */
class CampaignVariant extends Model
{
    use BelongsToWorkspace;

    protected string $baseTable = 'campaign_variants';
    protected $guarded = [];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
