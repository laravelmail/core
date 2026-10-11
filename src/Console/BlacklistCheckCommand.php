<?php

declare(strict_types=1);

namespace LaravelMail\Console;

use Illuminate\Console\Command;
use LaravelMail\Models\SendingIp;
use LaravelMail\Services\Deliverability\BlacklistMonitor;

class BlacklistCheckCommand extends Command
{
    protected $signature = 'laravelmail:blacklist-check {--ip= : only this tracked IP}';
    protected $description = 'Check tracked sending IPs against DNSBLs and record the result (requires features.deliverability).';

    public function handle(BlacklistMonitor $m): int
    {
        if (! config('laravelmail.features.deliverability')) {
            $this->warn('features.deliverability is off; nothing to do.');

            return self::SUCCESS;
        }
        if ($this->option('ip')) {
            $ip = SendingIp::where('ip', $this->option('ip'))->first();
            if (! $ip) {
                $this->error('That IP is not tracked.');

                return self::FAILURE;
            }
            $c = $m->check($ip);
            $this->info("{$ip->ip}: {$c->listed_count} listing(s)");

            return self::SUCCESS;
        }
        $this->info('Checked '.$m->checkAll().' IP(s).');

        return self::SUCCESS;
    }
}
