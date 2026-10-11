<?php

declare(strict_types=1);

namespace LaravelMail\Services;

use Illuminate\Support\Facades\Mail;

/** Package-owned mailer: named host mailer or inline transport array; optional round-robin pool. Never rewrites host mail config. */
class MailerFactory
{
    public function fromSpec(string|array|null $spec)
    {
        return is_array($spec) ? Mail::build($spec) : Mail::mailer($spec);
    }

    public function make(int $seed = 0)
    {
        $pool = array_values((array) config('laravelmail.sending.pool', []));
        $m = $pool !== [] ? $pool[$seed % count($pool)] : config('laravelmail.sending.mailer');

        return is_array($m) ? Mail::build($m) : Mail::mailer($m);
    }
}
