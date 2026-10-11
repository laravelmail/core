<?php

declare(strict_types=1);

namespace LaravelMail\Models;

use Illuminate\Database\Eloquent\Model;
use LaravelMail\Models\Concerns\BelongsToWorkspace;

class ScoringRule extends Model
{
    use BelongsToWorkspace;

    public const EVENTS = ['email_opened', 'link_clicked', 'subscribed', 'tag_added', 'custom'];

    protected string $baseTable = 'scoring_rules';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'bool', 'points' => 'int', 'max_total' => 'int'];
    }
}
