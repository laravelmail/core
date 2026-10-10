<?php

declare(strict_types=1);

namespace LaravelMail\Models;

use Illuminate\Database\Eloquent\Model;
use LaravelMail\Models\Concerns\BelongsToWorkspace;

class Suppression extends Model
{
    use BelongsToWorkspace;

    protected string $baseTable = 'suppressions';
    protected $guarded = [];

    public static function add(string $email, ?string $reason = null): self
    {
        return static::firstOrCreate(['email' => strtolower(trim($email))], ['reason' => $reason]);
    }

    public static function isSuppressed(string $email): bool
    {
        return static::where('email', strtolower(trim($email)))->exists();
    }
}
