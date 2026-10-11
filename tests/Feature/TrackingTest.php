<?php

namespace LaravelMail\Tests\Feature;

use Illuminate\Support\Facades\Mail;
use LaravelMail\Mail\CampaignMail;
use LaravelMail\Models\{Campaign, Message, MessageUrl, Subscriber};
use LaravelMail\Services\CampaignDispatcher;
use LaravelMail\Support\PhoneNumber;
use LaravelMail\Tests\TestCase;

class TrackingTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravelmail.routes.enabled', true);
        $app['config']->set('laravelmail.features.tracking', true);
        $app['config']->set('laravelmail.features.sending', true);
    }

    private function send(): Message
    {
        Mail::fake();
        Subscriber::create(['email' => 'a@example.com', 'first_name' => 'Ann']);
        $c = Campaign::create(['name' => 'c', 'subject' => 's', 'content' => '<body><a href="https://example.org/x?a=1&amp;b=2">go</a><a href="javascript:alert(1)">bad</a> {{unsubscribe_url}}</body>', 'send_to_all' => true]);
        app(CampaignDispatcher::class)->dispatch($c);

        return Message::first();
    }

    public function test_links_pixel_and_unsubscribe_are_injected(): void
    {
        $m = $this->send();
        Mail::assertSent(CampaignMail::class, function ($mail) use ($m) {
            return str_contains($mail->body, "/laravelmail/t/o/{$m->hash}")
                && str_contains($mail->body, '/laravelmail/t/c/'.$m->hash)
                && str_contains($mail->body, '/laravelmail/unsubscribe/')
                && ! str_contains($mail->body, 'https://example.org')
                && str_contains($mail->body, 'javascript:alert(1)'); // untouched, never made redirectable
        });
        $this->assertSame('https://example.org/x?a=1&b=2', MessageUrl::first()->url);
    }

    public function test_open_and_click_are_recorded_and_redirect_only_stored_urls(): void
    {
        $m = $this->send();
        $this->get("/laravelmail/t/o/{$m->hash}")->assertOk()->assertHeader('Content-Type', 'image/gif');
        $this->assertNotNull($m->fresh()->opened_at);

        $u = MessageUrl::first();
        $this->get("/laravelmail/t/c/{$m->hash}/{$u->hash}")->assertRedirect('https://example.org/x?a=1&b=2');
        $this->assertSame(1, $m->fresh()->click_count);
        $this->get("/laravelmail/t/c/{$m->hash}/nonexistent")->assertNotFound();
    }

    public function test_phone_number(): void
    {
        $this->assertSame('+447700900123', PhoneNumber::normalize('0044 (7700) 900-123'));
        $this->assertTrue(PhoneNumber::isValid('+447700900123'));
        $this->assertFalse(PhoneNumber::isValid('07700900123'));
    }

}
