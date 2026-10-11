<?php

namespace LaravelMail\Tests\Feature;

use LaravelMail\Models\{Subscriber, Suppression, Tag};
use LaravelMail\Services\Automations\FlowGraphValidator;
use LaravelMail\Services\Deliverability\{BlacklistChecker, DnsblResolver};
use LaravelMail\Services\Subscribers\{SubscriberImporter, TagService};
use LaravelMail\Services\Templates\{HtmlToMjml, MjmlCompileException, MjmlCompiler};
use LaravelMail\Tests\TestCase;

class Batch3Test extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravelmail.routes.enabled', true);
        $app['config']->set('laravelmail.features.webhooks', true);
        $app['config']->set('laravelmail.webhooks.mailgun', 'sekret');
        $app['config']->set('laravelmail.webhooks.postmark', 'pmtoken');
    }

    public function test_mjml_unavailable_degrades_to_raw_html(): void
    {
        $c = new MjmlCompiler;
        config(['laravelmail.mjml.node' => '/nonexistent']);
        $this->assertFalse($c->available());
        $this->assertSame('<p>hi</p>', $c->compileOrPassThrough('<p>hi</p>'));
        $this->expectException(MjmlCompileException::class);
        $c->compile('<mjml></mjml>');
    }

    public function test_html_to_mjml_strips_scripts_and_keeps_styles(): void
    {
        $out = HtmlToMjml::wrap('<html><head><style>p{color:red}</style></head><body><p>x</p><script>evil()</script></body></html>');
        $this->assertStringContainsString('<mj-style>p{color:red}</mj-style>', $out);
        $this->assertStringNotContainsString('evil', $out);
    }

    public function test_dnsbl_check_uses_reversed_ip(): void
    {
        $r = new class extends DnsblResolver {
            public array $asked = [];
            public function lookup(string $h): array { $this->asked[] = $h; return str_contains($h, 'spamcop') ? ['127.0.0.2'] : []; }
        };
        $res = (new BlacklistChecker($r))->check('1.2.3.4');
        $this->assertSame('4.3.2.1.zen.spamhaus.org', $r->asked[0]);
        $this->assertTrue($res['bl.spamcop.net']);
        $this->assertFalse($res['zen.spamhaus.org']);
        $this->expectException(\InvalidArgumentException::class);
        (new BlacklistChecker($r))->check('not-an-ip');
    }

    public function test_flow_validator(): void
    {
        $v = new FlowGraphValidator;
        $this->assertSame([], $v->errors([
            ['node_key' => 'a', 'type' => 'send_email', 'next_key' => 'b'],
            ['node_key' => 'b', 'type' => 'add_tag', 'config' => ['tag_id' => 1]],
        ]));
        $errs = $v->errors([
            ['node_key' => 'a', 'type' => 'webhook', 'config' => ['url' => 'http://x'], 'next_key' => 'b'],
            ['node_key' => 'b', 'type' => 'send_email', 'next_key' => 'a'],
        ]);
        $this->assertTrue(collect($errs)->contains(fn ($e) => str_contains($e, 'https://')));
        $this->assertTrue(collect($errs)->contains(fn ($e) => str_contains($e, 'loop')));
    }

    public function test_csv_import_creates_updates_skips_and_never_resubscribes(): void
    {
        Suppression::add('blocked@example.com');
        $u = Subscriber::create(['email' => 'gone@example.com']);
        $u->unsubscribe();
        $csv = "Email,first_name,tags\nnew@example.com,Neo,a;b\nBAD,x,\nblocked@example.com,B,\ngone@example.com,Gone,a\n";
        $h = fopen('php://memory', 'r+'); fwrite($h, $csv); rewind($h);

        $s = app(SubscriberImporter::class)->import($h, ['imported']);

        $this->assertSame(1, $s['created']);
        $this->assertSame(1, $s['updated']);
        $this->assertSame(1, $s['skipped']);
        $this->assertSame(1, $s['invalid']);
        $this->assertSame('unsubscribed', $u->fresh()->status);
        $this->assertEqualsCanonicalizing(['a', 'b', 'imported'], Subscriber::where('email', 'new@example.com')->first()->tags->pluck('name')->all());
    }

    public function test_tag_service_bulk(): void
    {
        foreach (['a', 'b', 'c'] as $n) { Subscriber::create(['email' => "$n@example.com"]); }
        $n = app(TagService::class)->tagQuery(Subscriber::query()->where('email', '<>', 'c@example.com'), 'vip');
        $this->assertSame(2, $n);
        $this->assertSame(2, Tag::where('name', 'vip')->first()->subscribers()->count());
    }

    public function test_mailgun_webhook_requires_valid_signature_then_suppresses_hard_bounce(): void
    {
        Subscriber::create(['email' => 'dead@example.com']);
        $body = ['event-data' => ['event' => 'failed', 'severity' => 'permanent', 'recipient' => 'dead@example.com']];
        $this->postJson('/laravelmail/webhooks/mailgun', $body + ['signature' => ['timestamp' => '1', 'token' => 't', 'signature' => 'wrong']])->assertForbidden();
        $this->assertSame(0, Suppression::count());

        $sig = hash_hmac('sha256', '1t', 'sekret');
        $this->postJson('/laravelmail/webhooks/mailgun', $body + ['signature' => ['timestamp' => '1', 'token' => 't', 'signature' => $sig]])->assertOk();
        $this->assertTrue(Suppression::isSuppressed('dead@example.com'));
        $this->assertSame('unsubscribed', Subscriber::where('email', 'dead@example.com')->first()->status);
    }

    public function test_soft_bounce_does_not_suppress_and_postmark_token_works(): void
    {
        Subscriber::create(['email' => 'soft@example.com']);
        $this->postJson('/laravelmail/webhooks/postmark?token=pmtoken', ['RecordType' => 'Bounce', 'Type' => 'SoftBounce', 'Email' => 'soft@example.com'])->assertOk();
        $this->assertSame(0, Suppression::count());
        $this->postJson('/laravelmail/webhooks/postmark?token=bad', ['RecordType' => 'SpamComplaint', 'Email' => 'soft@example.com'])->assertForbidden();
        $this->postJson('/laravelmail/webhooks/postmark?token=pmtoken', ['RecordType' => 'SpamComplaint', 'Email' => 'soft@example.com'])->assertOk();
        $this->assertTrue(Suppression::isSuppressed('soft@example.com'));
    }

    public function test_unknown_provider_404(): void
    {
        $this->postJson('/laravelmail/webhooks/evil', [])->assertNotFound();
    }
}
