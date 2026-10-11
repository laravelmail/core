<?php

declare(strict_types=1);

namespace LaravelMail\Console;

use Illuminate\Console\Command;
use LaravelMail\Models\Subscriber;
use LaravelMail\Models\Tag;
use LaravelMail\Services\Subscribers\SubscriberExporter;

class ExportCommand extends Command
{
    protected $signature = 'laravelmail:export {path : CSV file to write} {--tag=} {--status=}';
    protected $description = 'Export subscribers to CSV.';

    public function handle(SubscriberExporter $x): int
    {
        $q = Subscriber::query();
        $this->option('status') && $q->where('status', $this->option('status'));
        $this->option('tag') && $q->whereHas('tags', fn ($t) => $t->where((new Tag)->getTable().'.name', $this->option('tag')));
        $h = fopen($this->argument('path'), 'w');
        if (! $h) {
            $this->error('Cannot write '.$this->argument('path'));

            return self::FAILURE;
        }
        $n = $x->write($h, $q);
        fclose($h);
        $this->info("Exported $n subscribers.");

        return self::SUCCESS;
    }
}
