<?php

declare(strict_types=1);

namespace LaravelMail\Models;

use Illuminate\Database\Eloquent\Model;

class MessageUrl extends Model
{
    protected $guarded = [];

    public function getTable()
    {
        return config('laravelmail.table_prefix', 'laravelmail_').'message_urls';
    }
}
