<?php

namespace LaravelMail\Tests\Feature;

use LaravelMail\Contracts\SmsDriver;
use LaravelMail\Exceptions\FeatureDisabledException;
use LaravelMail\Models\SmsMessage;
use LaravelMail\Models\Subscriber;
use LaravelMail\Models\Suppression;
use LaravelMail\Services\Sms\SmsSender;
use LaravelMail\Tests\TestCase;

class FakeSms implements SmsDriver
{
    public static array $sent = [];
    public static bool $fail = false;

    public function name(): string
    {
        return 'fake';
    }

    public function send(string $to, string $body): ?string
    {
        if (self::$fail) {
            throw new \RuntimeException('gateway down');
        }
        self::$sent[] = [$to, $body];

        return 'id-'.count(self::$sent);
    }
}

class SmsTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravelmail.features.sms', true);
        $app['config']->set('laravelmail.sms.drivers', ['fake' => FakeSms::class]);
        $app['config']->set('laravelmail.sms.driver', 'fake');
    }

    protected function setUp(): void
    {
        parent::setUp();
        FakeSms::$sent = [];
        FakeSms::$fail = false;
    }

    private function sub(array $extra = []): Subscriber
    {
        return Subscriber::create(['email' => 'a@example.com', 'first_name' => 'Ann'] + $extra);
    }

    public function test_sends_only_with_consent_and_merges(): void
    {
        $s = $this->sub(['phone' => '+447700900123']);
        $m = app(SmsSender::class)->send($s, 'Hi {{first_name}}');
        $this->assertSame('skipped', $m->status);
        $this->assertSame('no SMS consent', $m->error);
        $this->assertSame([], FakeSms::$sent);

        $s->grantSmsConsent();
        $m = app(SmsSender::class)->send($s->fresh(), 'Hi {{first_name}}');
        $this->assertSame('sent', $m->status);
        $this->assertSame('id-1', $m->provider_id);
        $this->assertSame('+447700900123', FakeSms::$sent[0][0]);
        $this->assertSame('Hi Ann Reply STOP to opt out.', FakeSms::$sent[0][1]);
    }

    public function test_skips_invalid_optout_unsubscribed_suppressed(): void
    {
        $x = app(SmsSender::class);
        $s = $this->sub(['phone' => '12345']);
        $this->assertSame('no valid phone number', $x->send($s, 'x')->error);
        $s->grantSmsConsent('+447700900123');
        $s->update(['sms_opted_out_at' => now()]);
        $this->assertSame('opted out of SMS', $x->send($s->fresh(), 'x')->error);
        $s->update(['sms_opted_out_at' => null, 'status' => 'unsubscribed']);
        $this->assertSame('unsubscribed', $x->send($s->fresh(), 'x')->error);
        $s->update(['status' => 'subscribed']);
        Suppression::add('a@example.com');
        $this->assertSame('suppressed', $x->send($s->fresh(), 'x')->error);
        $this->assertSame([], FakeSms::$sent);
    }

    public function test_driver_failure_is_recorded_not_thrown(): void
    {
        $s = $this->sub();
        $s->grantSmsConsent('+447700900123');
        FakeSms::$fail = true;
        $m = app(SmsSender::class)->send($s, 'x');
        $this->assertSame('failed', $m->status);
        $this->assertSame('gateway down', $m->error);
    }

    public function test_stop_reply_opts_out_and_start_does_not_reconsent(): void
    {
        $s = $this->sub();
        $s->grantSmsConsent('+447700900123');
        $this->assertSame('opted_out', app(SmsSender::class)->handleInbound('0044 7700 900123', ' stop '));
        $this->assertNotNull($s->fresh()->sms_opted_out_at);
        $this->assertNull(app(SmsSender::class)->handleInbound('+447700900123', 'hello'));
    }

    public function test_flag_off_and_default_driver_deliver_nothing(): void
    {
        config(['laravelmail.features.sms' => false]);
        $this->expectException(FeatureDisabledException::class);
        app(SmsSender::class)->send($this->sub(), 'x');
    }

    public function test_default_log_driver_and_unknown_driver(): void
    {
        config(['laravelmail.sms.driver' => 'log']);
        $s = $this->sub();
        $s->grantSmsConsent('+447700900123');
        $this->assertSame('log', app(SmsSender::class)->send($s, 'x')->driver);
        $this->assertSame(0, count(FakeSms::$sent));
        config(['laravelmail.sms.driver' => 'nope']);
        $this->expectException(\InvalidArgumentException::class);
        app(SmsSender::class)->driver();
    }

    private function smsFlow(): \LaravelMail\Models\Automation
    {
        $a = \LaravelMail\Models\Automation::create(['name' => 'f', 'active' => true]);
        $a->steps()->create(['node_key' => 's', 'type' => 'send_sms', 'config' => ['body' => 'Hello {{first_name}}'], 'is_start' => true]);

        return $a;
    }

    public function test_flow_sms_step_sends_with_consent(): void
    {
        config(['laravelmail.features.automations' => true]);
        $s = $this->sub();
        $s->grantSmsConsent('+447700900123');
        $j = app(\LaravelMail\Services\Automations\FlowEngine::class)->enrol($this->smsFlow(), $s);
        app(\LaravelMail\Services\Automations\FlowEngine::class)->tick();
        $this->assertSame('completed', $j->fresh()->status);
        $this->assertStringStartsWith('Hello Ann', FakeSms::$sent[0][1]);
    }

    public function test_flow_sms_step_fails_visibly_without_consent_and_parks_when_off(): void
    {
        config(['laravelmail.features.automations' => true]);
        $s = $this->sub(['phone' => '+447700900123']);
        $j = app(\LaravelMail\Services\Automations\FlowEngine::class)->enrol($this->smsFlow(), $s);
        app(\LaravelMail\Services\Automations\FlowEngine::class)->tick();
        $this->assertSame('failed', $j->fresh()->status);
        $this->assertSame([], FakeSms::$sent);

        config(['laravelmail.features.sms' => false]);
        $s2 = Subscriber::create(['email' => 'b@example.com']);
        $j2 = app(\LaravelMail\Services\Automations\FlowEngine::class)->enrol($this->smsFlow(), $s2);
        app(\LaravelMail\Services\Automations\FlowEngine::class)->tick();
        $this->assertSame('active', $j2->fresh()->status);
    }
}
