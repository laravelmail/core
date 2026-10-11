<?php

declare(strict_types=1);

namespace LaravelMail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use LaravelMail\Models\Concerns\BelongsToWorkspace;

class Subscriber extends Model
{
    use BelongsToWorkspace;

    protected string $baseTable = 'subscribers';
    protected $guarded = [];
    protected $attributes = ['status' => 'subscribed'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'unsubscribed_at' => 'datetime'];
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, config('laravelmail.table_prefix').'subscriber_tag');
    }

    /** Record explicit SMS opt-in. Call this only when the person actually agreed to receive texts. */
    public function grantSmsConsent(?string $phone = null): void
    {
        $this->forceFill(['phone' => \LaravelMail\Support\PhoneNumber::normalize($phone ?? $this->phone), 'sms_consent_at' => now(), 'sms_opted_out_at' => null])->save();
    }

    public function isSubscribed(): bool
    {
        return $this->status === 'subscribed';
    }

    public function unsubscribe(): void
    {
        $this->forceFill(['status' => 'unsubscribed', 'unsubscribed_at' => now()])->save();
    }

    /** Signed unsubscribe link. Requires laravelmail.routes.enabled. */
    public function unsubscribeUrl(): string
    {
        return \Illuminate\Support\Facades\URL::signedRoute('laravelmail.unsubscribe', ['subscriber' => $this->id]);
    }
}
