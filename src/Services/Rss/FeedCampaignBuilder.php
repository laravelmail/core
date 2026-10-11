<?php

declare(strict_types=1);

namespace LaravelMail\Services\Rss;

use Illuminate\Support\Facades\DB;
use LaravelMail\Models\Campaign;
use LaravelMail\Models\RssFeed;
use LaravelMail\Models\RssItem;

/** Turns not-yet-used feed items into a DRAFT campaign. It never sends; a person reviews and dispatches. */
class FeedCampaignBuilder
{
    public function draft(RssFeed $feed, ?string $subject = null, int $limit = 10): ?Campaign
    {
        return DB::transaction(function () use ($feed, $subject, $limit) {
            $items = $feed->items()->whereNull('campaign_id')->orderByDesc('published_at')->orderByDesc('id')->limit(max(1, min($limit, 50)))->get();
            if ($items->isEmpty()) {
                return null;
            }
            $c = Campaign::create(['name' => $feed->name.' digest '.now()->toDateString(), 'subject' => $subject ?: $feed->name.': latest posts',
                'content' => $this->html($items), 'status' => Campaign::DRAFT]);
            RssItem::whereIn('id', $items->pluck('id'))->update(['campaign_id' => $c->id]);

            return $c;
        });
    }

    private function html($items): string
    {
        $h = '';
        foreach ($items as $i) {
            $title = e($i->title);
            $h .= '<h3>'.($i->link ? '<a href="'.e($i->link).'">'.$title.'</a>' : $title).'</h3>';
            $h .= $i->summary ? '<p>'.e($i->summary).'</p>' : '';
        }

        return $h;
    }
}
