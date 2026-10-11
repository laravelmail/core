<?php

declare(strict_types=1);

namespace LaravelMail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use LaravelMail\Models\Concerns\BelongsToWorkspace;

class Tag extends Model
{
    use BelongsToWorkspace;

    protected string $baseTable = 'tags';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['public_subscribe_enabled' => 'bool', 'double_opt_in' => 'bool', 'preference_topic' => 'bool', 'allowed_origins' => 'array'];
    }

    public function acceptsPublicSignups(): bool
    {
        return $this->public_subscribe_enabled && $this->public_uuid !== null;
    }

    public function enablePublicSignup(bool $doubleOptIn = true, array $origins = []): self
    {
        $this->forceFill([
            'public_uuid' => $this->public_uuid ?? (string) \Illuminate\Support\Str::uuid(),
            'public_subscribe_enabled' => true,
            'double_opt_in' => $doubleOptIn,
            'allowed_origins' => $origins,
        ])->save();

        return $this;
    }

    public function subscribers(): BelongsToMany
    {
        return $this->belongsToMany(Subscriber::class, config('laravelmail.table_prefix').'subscriber_tag');
    }
}
