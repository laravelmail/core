<?php

declare(strict_types=1);

namespace LaravelMail\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationStep extends Model
{

    protected $baseTable = 'automation_steps';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['config' => 'array', 'is_start' => 'bool'];
    }

    public function getTable()
    {
        return config('laravelmail.table_prefix', 'laravelmail_').$this->baseTable;
    }
}
