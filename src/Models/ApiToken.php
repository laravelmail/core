<?php

declare(strict_types=1);

namespace LaravelMail\Models;

use Illuminate\Database\Eloquent\Model;

class ApiToken extends Model
{
    public const ABILITIES = [
        'subscribers:read', 'subscribers:write', 'tags:read', 'tags:write', 'segments:read', 'segments:write',
        'templates:read', 'templates:write', 'campaigns:read', 'campaigns:write', 'campaigns:send',
    ];

    protected $guarded = [];
    protected $hidden = ['token_hash'];

    public function getTable()
    {
        return config('laravelmail.table_prefix', 'laravelmail_').'api_tokens';
    }

    protected function casts(): array
    {
        return ['abilities' => 'array', 'last_used_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    /** @return array{0: self, 1: string} model and the plain token (shown once) */
    public static function issue(string $name, array $abilities, int|string|null $workspaceId = null, ?\DateTimeInterface $expiresAt = null): array
    {
        $bad = array_diff($abilities, [...self::ABILITIES, '*']);
        if ($bad !== [] || $abilities === []) {
            throw new \InvalidArgumentException('Unknown or empty abilities: '.implode(',', $bad));
        }
        $plain = 'lm_'.bin2hex(random_bytes(24));
        $t = static::create([
            'name' => $name, 'workspace_id' => $workspaceId === null ? null : (string) $workspaceId,
            'token_hash' => hash('sha256', $plain), 'abilities' => array_values($abilities), 'expires_at' => $expiresAt,
        ]);

        return [$t, $plain];
    }

    public function can(string $ability): bool
    {
        $a = (array) $this->abilities;

        return in_array('*', $a, true) || in_array($ability, $a, true);
    }
}
