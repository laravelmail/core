<?php

declare(strict_types=1);

namespace LaravelMail\Models;

use Illuminate\Database\Eloquent\Model;
use LaravelMail\Models\Concerns\BelongsToWorkspace;

class SmsMessage extends Model
{
    use BelongsToWorkspace;

    protected string $baseTable = 'sms_messages';
    protected $guarded = [];
    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
