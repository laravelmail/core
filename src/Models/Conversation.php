<?php

declare(strict_types=1);

namespace LaravelMail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LaravelMail\Models\Concerns\BelongsToWorkspace;

class Conversation extends Model
{
    use BelongsToWorkspace;

    protected string $baseTable = 'conversations';
    protected $guarded = [];
    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['last_reply_at' => 'datetime'];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class)->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
