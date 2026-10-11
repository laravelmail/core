<?php

declare(strict_types=1);

namespace LaravelMail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LaravelMail\Models\Concerns\BelongsToWorkspace;

class ConversationMessage extends Model
{
    use BelongsToWorkspace;

    protected string $baseTable = 'conversation_messages';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'read_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
