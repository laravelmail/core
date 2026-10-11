<?php

namespace LaravelMail\Tests\Feature;

use Illuminate\Support\Facades\Mail;
use LaravelMail\Models\{PendingSubscription, Subscriber, Suppression, Tag};
use LaravelMail\Services\Subscribers\PreferenceCenter;
use LaravelMail\Tests\TestCase;

class FormsTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravelmail.routes.enabled', true);
        $app['config']->set('laravelmail.features.forms', true);
    }

    private function list(bool $double = true, array $origins = []): Tag
    {
        return Tag::create(['name' => 'news'])->enablePublicSignup($double, $origins);
    }

    public function test_double_opt_in_stores_pending_and_sends_nothing_while_sending_off(): void
    {
        Mail::fake();
        $t = $this->list();
        $this->postJson('/laravelmail/subscribe/'.$t->public_uuid, ['email' => 'New@Example.com'])->assertStatus(202)->assertJson(['status' => 'pending_confirmation']);
        Mail::assertNothingSent();
        $this->assertSame(0, Subscriber::count());
        $this->assertSame('new@example.com', PendingSubscription::first()->email);
    }

    public function test_confirmation_mail_sent_only_when_sending_enabled_and_confirm_subscribes(): void
    {
        config(['laravelmail.features.sending' => true]);
        Mail::fake();
        $t = $this->list();
        $this->postJson('/laravelmail/subscribe/'.$t->public_uuid, ['email' => 'a@example.com'])->assertStatus(202);
        Mail::assertSentCount(1);
        $body = null;
        Mail::assertSent(\LaravelMail\Mail\CampaignMail::class, function ($m) use (&$body) { $body = $m->body; return true; });
        preg_match('#/laravelmail/subscribe/confirm/([A-Za-z0-9]+)#', $body, $m);
        $this->getJson('/laravelmail/subscribe/confirm/'.$m[1])->assertOk();
        $this->assertTrue(Subscriber::where('email', 'a@example.com')->first()->tags()->whereKey($t->id)->exists());
        $this->getJson('/laravelmail/subscribe/confirm/'.$m[1])->assertNotFound(); // single use
    }

    public function test_single_opt_in_subscribes_and_existing_answers_identically(): void
    {
        $t = $this->list(false);
        $this->postJson('/laravelmail/subscribe/'.$t->public_uuid, ['email' => 'a@example.com'])->assertOk()->assertJson(['status' => 'subscribed']);
        $this->postJson('/laravelmail/subscribe/'.$t->public_uuid, ['email' => 'a@example.com'])->assertOk()->assertJson(['status' => 'subscribed']);
        $this->assertSame(1, Subscriber::count());
    }

    public function test_honeypot_suppressed_origin_and_unknown_list(): void
    {
        $t = $this->list(false, ['good.example.com']);
        $this->postJson('/laravelmail/subscribe/'.$t->public_uuid, ['email' => 'bot@example.com', 'website' => 'spam'], ['Origin' => 'https://good.example.com'])->assertOk();
        $this->assertSame(0, Subscriber::count());
        $this->postJson('/laravelmail/subscribe/'.$t->public_uuid, ['email' => 'a@example.com'], ['Origin' => 'https://evil.example.org'])->assertForbidden();
        $this->postJson('/laravelmail/subscribe/nope', ['email' => 'a@example.com'])->assertNotFound();
        Suppression::add('sup@example.com');
        $this->postJson('/laravelmail/subscribe/'.$t->public_uuid, ['email' => 'sup@example.com'], ['Origin' => 'https://good.example.com'])->assertOk();
        $this->assertSame(0, Subscriber::count());
    }

    public function test_preference_center_only_touches_topic_tags_and_is_signed(): void
    {
        $topic = Tag::create(['name' => 'weekly', 'preference_topic' => true]);
        $internal = Tag::create(['name' => 'customer']);
        $s = Subscriber::create(['email' => 'a@example.com']);
        $s->tags()->attach($internal);
        $pc = app(PreferenceCenter::class);

        $this->getJson('/laravelmail/preferences/'.$s->id)->assertForbidden(); // unsigned
        $this->postJson($pc->url($s), ['topics' => [$topic->id, $internal->id]])->assertOk();
        $this->assertEqualsCanonicalizing([$topic->id, $internal->id], $s->tags()->pluck((new Tag)->getTable().'.id')->all());
        $this->assertSame([$topic->id], $pc->selectedTopicIds($s));
        $this->postJson($pc->url($s), ['topics' => []])->assertOk();
        $this->assertSame([$internal->id], $s->tags()->pluck((new Tag)->getTable().'.id')->all()); // internal tag untouched
    }

    public function test_resubscribe_blocked_for_suppressed(): void
    {
        $s = Subscriber::create(['email' => 'a@example.com']);
        $s->unsubscribe();
        $pc = app(PreferenceCenter::class);
        $this->assertTrue($pc->canResubscribe($s));
        Suppression::add('a@example.com', 'complaint');
        $this->assertFalse($pc->resubscribe($s));
    }
}
