<?php

namespace LaravelMail\Tests\Feature;

use Illuminate\Support\Facades\Http;
use LaravelMail\Models\{ApiToken, Campaign, RssFeed, RssItem};
use LaravelMail\Services\Rss\FeedCampaignBuilder;
use LaravelMail\Services\Rss\FeedFetcher;
use LaravelMail\Support\SafeUrl;
use LaravelMail\Tests\TestCase;

class RssTest extends TestCase
{
    private const URL = 'https://93.184.216.34/feed.xml';

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravelmail.routes.enabled', true);
        $app['config']->set('laravelmail.features.api', true);
        $app['config']->set('laravelmail.features.rss', true);
    }

    private const RSS = '<?xml version="1.0"?><rss version="2.0"><channel><title>T</title>
      <item><title>One &amp; &lt;b&gt;only&lt;/b&gt;</title><link>https://example.org/1</link><guid>g1</guid><description><![CDATA[<p>Hello <script>x</script></p>]]></description><pubDate>Mon, 05 Oct 2026 10:00:00 +0000</pubDate></item>
      <item><title>Bad link</title><link>javascript:alert(1)</link><guid>g2</guid></item>
      <item><title></title><guid>g3</guid></item></channel></rss>';

    private const ATOM = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><entry><title>Atom one</title><link href="https://example.org/a"/><id>urn:a</id><updated>2026-10-01T00:00:00Z</updated><summary>S</summary></entry></feed>';

    public function test_ssrf_guard(): void
    {
        foreach (['http://93.184.216.34/f', 'https://127.0.0.1/f', 'https://10.0.0.5/f', 'https://169.254.169.254/latest', 'https://user:pw@93.184.216.34/f', 'ftp://x', 'https://localhost/f'] as $u) {
            $this->assertFalse(SafeUrl::isPublicHttps($u), $u);
        }
        $this->assertTrue(SafeUrl::isPublicHttps(self::URL));
    }

    public function test_parse_rss_and_atom_safely(): void
    {
        $f = app(FeedFetcher::class);
        $items = $f->parse(self::RSS);
        $this->assertCount(2, $items);
        $this->assertSame('One & only', $items[0]['title']);
        $this->assertSame('Hello x', $items[0]['summary']);
        $this->assertNull($items[1]['link']);
        $this->assertSame('https://example.org/a', $f->parse(self::ATOM)[0]['link']);
        $this->expectException(\RuntimeException::class);
        $f->parse('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><rss><channel><item><title>&e;</title></item></channel></rss>');
    }

    public function test_fetch_dedupes_records_errors_and_follows_safe_redirects(): void
    {
        Http::fake([
            'https://93.184.216.34/feed.xml' => Http::sequence()->push(self::RSS)->push(self::RSS)->push('', 500),
            'https://10.0.0.1/*' => Http::response(self::RSS),
        ]);
        $feed = RssFeed::create(['name' => 'F', 'url' => self::URL]);
        $f = app(FeedFetcher::class);
        $this->assertSame(2, $f->fetch($feed));
        $this->assertSame(0, $f->fetch($feed));
        $this->assertSame(2, RssItem::count());
        $this->assertSame(0, $f->fetch($feed));
        $this->assertStringContainsString('HTTP 500', $feed->fresh()->last_error);

        $feed->update(['url' => 'https://93.184.216.34/evil']);
        Http::fake(['https://93.184.216.34/evil' => Http::response('', 301, ['Location' => 'https://10.0.0.1/evil'])]);
        $f->fetch($feed);
        $this->assertStringContainsString('public address', $feed->fresh()->last_error);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '10.0.0.1'));
    }

    public function test_draft_campaign_is_escaped_unsent_and_uses_items_once(): void
    {
        $feed = RssFeed::create(['name' => 'F', 'url' => self::URL]);
        RssItem::create(['rss_feed_id' => $feed->id, 'guid_hash' => 'a', 'title' => '<img src=x onerror=1>', 'link' => 'https://example.org/1', 'summary' => 'S']);
        $c = app(FeedCampaignBuilder::class)->draft($feed);
        $this->assertSame('draft', $c->status);
        $this->assertStringNotContainsString('<img', $c->content);
        $this->assertStringContainsString('&lt;img', $c->content);
        $this->assertNull(app(FeedCampaignBuilder::class)->draft($feed));
        $this->assertSame(1, Campaign::count());
    }

    public function test_api_and_flag(): void
    {
        $h = ['Authorization' => 'Bearer '.ApiToken::issue('t', ['*'])[1]];
        $this->postJson('/laravelmail/api/v1/rss-feeds', ['name' => 'x', 'url' => 'https://127.0.0.1/f'], $h)->assertStatus(422);
        $this->postJson('/laravelmail/api/v1/rss-feeds', ['name' => 'x', 'url' => 'http://93.184.216.34/f'], $h)->assertStatus(422);
        $id = $this->postJson('/laravelmail/api/v1/rss-feeds', ['name' => 'x', 'url' => self::URL], $h)->assertCreated()->json('id');
        Http::fake([self::URL => Http::response(self::RSS)]);
        $this->postJson("/laravelmail/api/v1/rss-feeds/$id/fetch", [], $h)->assertJsonPath('new_items', 2);
        $this->postJson("/laravelmail/api/v1/rss-feeds/$id/draft-campaign", [], $h)->assertCreated()->assertJsonPath('status', 'draft');
        $this->postJson("/laravelmail/api/v1/rss-feeds/$id/draft-campaign", [], $h)->assertStatus(409);
        config(['laravelmail.features.rss' => false]);
        $this->artisan('laravelmail:rss-fetch')->expectsOutputToContain('off')->assertSuccessful();
    }
}
