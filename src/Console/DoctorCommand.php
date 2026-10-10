<?php

declare(strict_types=1);

namespace LaravelMail\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class DoctorCommand extends Command
{
    protected $signature = 'laravelmail:doctor';
    protected $description = 'Check LaravelMail installation state (read-only).';

    public function handle(): int
    {
        $p = config('laravelmail.table_prefix');
        $ok = true;
        foreach (['subscribers', 'tags', 'templates', 'campaigns', 'messages', 'suppressions'] as $t) {
            $has = Schema::hasTable($p.$t);
            $ok = $ok && $has;
            $this->line(($has ? '[ok]   ' : '[miss] ').$p.$t);
        }
        $this->line('Sending: '.(config('laravelmail.features.sending') ? 'ENABLED' : 'disabled (safe default)'));
        $this->line('Routes: '.(config('laravelmail.routes.enabled') ? 'enabled' : 'disabled'));

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
