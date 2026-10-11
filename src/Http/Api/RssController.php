<?php

declare(strict_types=1);

namespace LaravelMail\Http\Api;

use Illuminate\Http\Request;
use LaravelMail\Models\RssFeed;
use LaravelMail\Services\Rss\FeedCampaignBuilder;
use LaravelMail\Services\Rss\FeedFetcher;
use LaravelMail\Support\SafeUrl;

class RssController
{
    public function index()
    {
        return RssFeed::withCount('items')->orderBy('name')->get();
    }

    public function store(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:255', 'url' => 'required|url|max:2000']);
        abort_unless(SafeUrl::isPublicHttps($d['url']), 422, 'Feed URL must be https and resolve to a public address.');

        return response()->json(RssFeed::create($d), 201);
    }

    public function destroy(int $id)
    {
        RssFeed::findOrFail($id)->delete();

        return response()->noContent();
    }

    public function items(int $id)
    {
        return RssFeed::findOrFail($id)->items()->orderByDesc('published_at')->orderByDesc('id')->limit(100)->get();
    }

    public function fetch(int $id, FeedFetcher $f)
    {
        $feed = RssFeed::findOrFail($id);
        $new = $f->fetch($feed);

        return ['new_items' => $new, 'error' => $feed->fresh()->last_error];
    }

    /** Creates a DRAFT campaign from unused items. Nothing is sent. */
    public function draft(Request $r, int $id, FeedCampaignBuilder $b)
    {
        $d = $r->validate(['subject' => 'nullable|string|max:255', 'limit' => 'integer|min:1|max:50']);
        $c = $b->draft(RssFeed::findOrFail($id), $d['subject'] ?? null, $d['limit'] ?? 10);

        return $c ? response()->json(['campaign_id' => $c->id, 'status' => $c->status], 201) : response()->json(['message' => 'No unused items.'], 409);
    }
}
