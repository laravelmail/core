<?php

declare(strict_types=1);

namespace LaravelMail\Console;

use Illuminate\Console\Command;
use LaravelMail\Services\Import\AppImporter;

class ImportAppCommand extends Command
{
    protected $signature = 'laravelmail:import-app {--workspace= : app workspace id to import (required)} {--connection= : DB connection holding the app tables (default laravelmail.import.connection)}
        {--entities=* : tags, subscribers, deals, conversations (default all)} {--commit : actually write; without it this is a dry run}';
    protected $description = 'Copy data from the hosted app schema into the package tables. Read-only on the source; dry run unless --commit.';

    public function handle(): int
    {
        if (! $this->option('workspace')) {
            $this->error('--workspace is required: one workspace per run so tenants never mix.');

            return self::FAILURE;
        }
        $conn = $this->option('connection') ?: config('laravelmail.import.connection');
        if (! $conn) {
            $this->error('No source connection. Pass --connection or set laravelmail.import.connection.');

            return self::FAILURE;
        }
        try {
            $stats = (new AppImporter((string) $this->option('workspace'), $conn, (bool) $this->option('commit')))->run($this->option('entities') ?: AppImporter::ENTITIES);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->table(['entity', 'seen', 'created', 'existing', 'skipped'], collect($stats)->map(fn ($s, $e) => [$e, $s['seen'], $s['created'], $s['existing'], $s['skipped']])->values()->all());
        $this->option('commit') ? $this->info('Imported.') : $this->warn('Dry run: nothing written. Re-run with --commit.');

        return self::SUCCESS;
    }
}
