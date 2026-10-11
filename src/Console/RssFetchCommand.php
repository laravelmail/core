<?php

declare(strict_types=1);

namespace LaravelMail\Console;

use Illuminate\Console\Command;
use LaravelMail\Models\RssFeed;
use LaravelMail\Services\Rss\FeedFetcher;

class RssFetchCommand extends Command
{
    protected $signature = 'laravelmail:rss-fetch';
    protected $description = 'Fetch all active RSS feeds (requires features.rss). Not scheduled for you.';

    public function handle(FeedFetcher $f): int
    {
        if (! config('laravelmail.features.rss')) {
            $this->warn('features.rss is off; nothing to do.');

            return self::SUCCESS;
        }
        $n = 0;
        RssFeed::where('active', true)->each(function ($feed) use ($f, &$n) {
            $n += $f->fetch($feed);
        });
        $this->info("$n new item(s).");

        return self::SUCCESS;
    }
}
