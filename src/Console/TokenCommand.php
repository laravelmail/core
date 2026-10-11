<?php

declare(strict_types=1);

namespace LaravelMail\Console;

use Illuminate\Console\Command;
use LaravelMail\Models\ApiToken;

class TokenCommand extends Command
{
    protected $signature = 'laravelmail:token {name} {--ability=* : e.g. subscribers:read (repeatable; "*" = all)} {--workspace= : workspace id} {--days= : expire after N days}';
    protected $description = 'Create an API token. The plain token is shown once.';

    public function handle(): int
    {
        $abilities = $this->option('ability');
        if ($abilities === []) {
            $this->error('Give at least one --ability. Available: '.implode(', ', ApiToken::ABILITIES).', *');

            return self::FAILURE;
        }
        [, $plain] = ApiToken::issue((string) $this->argument('name'), $abilities, $this->option('workspace'),
            $this->option('days') ? now()->addDays((int) $this->option('days')) : null);
        $this->line($plain);
        $this->warn('Store this token now. It cannot be shown again.');

        return self::SUCCESS;
    }
}
