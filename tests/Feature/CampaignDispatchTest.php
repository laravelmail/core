<?php

namespace LaravelMail\Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use LaravelMail\Exceptions\InvalidCampaignStateException;
use LaravelMail\Exceptions\SendingDisabledException;
use LaravelMail\Jobs\SendCampaignMessage;
use LaravelMail\Mail\CampaignMail;
use LaravelMail\Models\Campaign;
use LaravelMail\Models\Subscriber;
use LaravelMail\Models\Suppression;
use LaravelMail\Models\Tag;
use LaravelMail\Models\Template;
use LaravelMail\Services\CampaignDispatcher;
use LaravelMail\Tests\TestCase;

class CampaignDispatchTest extends TestCase
{
    private function campaign(): Campaign
    {
        $tag = Tag::create(['name' => 'news']);
        $a = Subscriber::create(['email' => 'a@example.com', 'first_name' => 'Ann']);
        $b = Subscriber::create(['email' => 'b@example.com']);
        $c = Subscriber::create(['email' => 'c@example.com']); // not tagged
        $a->tags()->attach($tag);
        $b->tags()->attach($tag);
        $t = Template::create(['name' => 't', 'content' => '<main>{{content}}</main>']);
        $camp = Campaign::create(['name' => 'c', 'subject' => 'Hi {{first_name}}', 'content' => 'Hello {{first_name}}', 'template_id' => $t->id]);
        $camp->tags()->attach($tag);

        return $camp;
    }

    public function test_dispatch_refused_while_sending_disabled(): void
    {
        Queue::fake();
        $camp = $this->campaign();
        $this->expectException(SendingDisabledException::class);
        try {
            app(CampaignDispatcher::class)->dispatch($camp);
        } finally {
            Queue::assertNothingPushed();
            $this->assertSame('draft', $camp->fresh()->status);
        }
    }

    public function test_preview_works_while_disabled_and_skips_suppressed(): void
    {
        $camp = $this->campaign();
        Suppression::add('B@example.com', 'bounce');
        $emails = app(CampaignDispatcher::class)->preview($camp)->pluck('email')->all();
        $this->assertSame(['a@example.com'], $emails);
    }

    public function test_dispatch_sends_rendered_mail_when_enabled(): void
    {
        config(['laravelmail.sending.enabled' => true]);
        Mail::fake();
        $camp = $this->campaign();

        $n = app(CampaignDispatcher::class)->dispatch($camp);

        $this->assertSame(2, $n);
        Mail::assertSentCount(2);
        Mail::assertSent(CampaignMail::class, fn ($m) => $m->hasTo('a@example.com')
            && $m->subjectLine === 'Hi Ann' && str_contains($m->body, '<main>Hello Ann</main>'));
        $this->assertSame('sent', $camp->fresh()->status);
    }

    public function test_job_rechecks_gate_at_send_time(): void
    {
        config(['laravelmail.sending.enabled' => true]);
        Queue::fake();
        $camp = $this->campaign();
        app(CampaignDispatcher::class)->dispatch($camp);
        Queue::assertPushed(SendCampaignMessage::class, 2);

        config(['laravelmail.sending.enabled' => false]);
        Mail::fake();
        $id = $camp->messages()->first()->id;
        (new SendCampaignMessage($id))->handle(app(\LaravelMail\Services\Renderer::class));
        Mail::assertNothingSent();
    }

    public function test_only_drafts_dispatch_and_no_double_send(): void
    {
        config(['laravelmail.sending.enabled' => true]);
        Mail::fake();
        $camp = $this->campaign();
        $d = app(CampaignDispatcher::class);
        $d->dispatch($camp);
        $this->expectException(InvalidCampaignStateException::class);
        try {
            $d->dispatch($camp->fresh());
        } finally {
            Mail::assertSentCount(2);
        }
    }

    public function test_unsubscribed_subscriber_is_excluded(): void
    {
        $camp = $this->campaign();
        Subscriber::where('email', 'a@example.com')->first()->unsubscribe();
        $this->assertSame(['b@example.com'], app(CampaignDispatcher::class)->preview($camp)->pluck('email')->all());
    }

    public function test_merge_tags_are_html_escaped(): void
    {
        $s = new Subscriber(['email' => 'x@example.com', 'first_name' => '<b>Bob</b>']);
        $this->assertSame('Hi &lt;b&gt;Bob&lt;/b&gt;', app(\LaravelMail\Services\Renderer::class)->merge('Hi {{first_name}}', $s));
    }
}
