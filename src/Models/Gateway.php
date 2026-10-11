<?php

declare(strict_types=1);

namespace LaravelMail\Models;

use Illuminate\Database\Eloquent\Model;
use LaravelMail\Models\Concerns\BelongsToWorkspace;

/** A sending route: a named host mailer or an inline transport array (stored encrypted). */
class Gateway extends Model
{
    use BelongsToWorkspace;

    protected string $baseTable = 'gateways';
    protected $guarded = [];
    protected $hidden = ['mailer'];
    protected $attributes = ['active' => true, 'priority' => 100];

    protected function casts(): array
    {
        return ['mailer' => 'encrypted:array', 'active' => 'bool', 'failover_enabled' => 'bool'];
    }

    /** @return string|array named mailer or transport array */
    public function mailerSpec(): string|array
    {
        $m = $this->mailer;

        return is_array($m) && array_keys($m) === ['name'] ? (string) $m['name'] : $m;
    }

    /** Store a named host mailer. */
    public function setMailerName(string $name): static
    {
        $this->mailer = ['name' => $name];

        return $this;
    }
}
