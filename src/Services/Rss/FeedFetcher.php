<?php

declare(strict_types=1);

namespace LaravelMail\Services\Rss;

use Illuminate\Support\Facades\Http;
use LaravelMail\Models\RssFeed;
use LaravelMail\Models\RssItem;
use LaravelMail\Support\SafeUrl;

/** Fetches and parses RSS 2.0 / Atom. https only, public hosts only, size and redirect limits, no XML entity expansion. */
class FeedFetcher
{
    public const MAX_BYTES = 2_000_000;
    public const MAX_ITEMS = 100;

    /** @return int new items stored */
    public function fetch(RssFeed $feed): int
    {
        try {
            $items = $this->parse($this->download($feed->url));
        } catch (\Throwable $e) {
            $feed->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 500), 'last_fetched_at' => now()])->save();

            return 0;
        }
        $new = 0;
        foreach ($items as $i) {
            $row = RssItem::firstOrCreate(['rss_feed_id' => $feed->id, 'guid_hash' => hash('sha256', $i['guid'])], [
                'workspace_id' => $feed->workspace_id, 'title' => $i['title'], 'link' => $i['link'], 'summary' => $i['summary'], 'published_at' => $i['published_at']]);
            $new += $row->wasRecentlyCreated ? 1 : 0;
        }
        $feed->forceFill(['last_error' => null, 'last_fetched_at' => now()])->save();

        return $new;
    }

    public function download(string $url): string
    {
        for ($hop = 0; $hop <= 3; $hop++) {
            if (! SafeUrl::isPublicHttps($url)) {
                throw new \RuntimeException('Feed URL must be https and resolve to a public address.');
            }
            $r = Http::timeout(10)->withoutRedirecting()->withHeaders(['Accept' => 'application/rss+xml, application/atom+xml, application/xml;q=0.9, */*;q=0.5'])->get($url);
            if ($r->redirect()) {
                $loc = $r->header('Location');
                $url = $loc !== '' && str_starts_with($loc, '/') ? preg_replace('#^(https?://[^/]+).*$#i', '$1', $url).$loc : $loc;
                continue;
            }
            if (! $r->successful()) {
                throw new \RuntimeException('Feed returned HTTP '.$r->status());
            }
            if (strlen($r->body()) > self::MAX_BYTES) {
                throw new \RuntimeException('Feed is larger than 2 MB.');
            }

            return $r->body();
        }
        throw new \RuntimeException('Too many redirects.');
    }

    /** @return list<array{guid:string,title:string,link:?string,summary:?string,published_at:?\Illuminate\Support\Carbon}> */
    public function parse(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        // No LIBXML_NOENT (no entity substitution) and LIBXML_NONET (no network): XXE-safe.
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_use_internal_errors($prev);
        if ($doc === false || str_contains($xml, '<!ENTITY')) {
            throw new \RuntimeException('Not a valid feed.');
        }

        $out = [];
        $nodes = isset($doc->channel->item) ? $doc->channel->item : ($doc->entry ?? []);
        foreach ($nodes as $n) {
            $title = trim(strip_tags((string) $n->title));
            $link = $this->link($n);
            $guid = trim((string) ($n->guid ?? $n->id ?? '')) ?: ($link ?? $title);
            if ($title === '' || $guid === '') {
                continue;
            }
            $date = (string) ($n->pubDate ?? $n->published ?? $n->updated ?? '');
            $summary = trim(strip_tags((string) ($n->description ?? $n->summary ?? '')));
            $out[] = ['guid' => $guid, 'title' => mb_substr($title, 0, 500), 'link' => $link, 'summary' => $summary === '' ? null : mb_substr($summary, 0, 2000),
                'published_at' => $date === '' ? null : $this->date($date)];
            if (count($out) >= self::MAX_ITEMS) {
                break;
            }
        }

        return $out;
    }

    private function link(\SimpleXMLElement $n): ?string
    {
        $l = isset($n->link['href']) ? (string) $n->link['href'] : trim((string) $n->link);

        return preg_match('#^https?://#i', $l) === 1 ? $l : null; // never javascript: or data: links
    }

    private function date(string $d): ?\Illuminate\Support\Carbon
    {
        try {
            return \Illuminate\Support\Carbon::parse($d);
        } catch (\Throwable) {
            return null;
        }
    }
}
