<?php

namespace LaravelMail\Tests\Feature;

use Illuminate\Support\Facades\Mail;
use LaravelMail\Exceptions\GatewayCapacityException;
use LaravelMail\Models\{Campaign, Gateway, GatewayFailover, Message, Subscriber, WarmingPlan};
use LaravelMail\Services\CampaignDispatcher;
use LaravelMail\Services\Deliverability\{DeliverabilityOverview, GatewayRouter, WarmingService};
use LaravelMail\Tests\TestCase;

class DeliverabilityTest extends TestCase
{
    private function gw(string $name, string $mailer, array $extra = []): Gateway
    {
        config(["mail.mailers.$mailer" => ['transport' => 'array']]);

        return Gateway::create(['name' => $name, 'mailer' => ['name' => $mailer]] + $extra);
    }

    private function msg(Gateway $g, string $email, bool $sent = true, bool $complained = false): Message
    {
        $s = Subscriber::create(['email' => $email]);
        $c = Campaign::firstOrCreate(['name' => 'c'], ['subject' => 's', 'content' => 'b']);

        return Message::create(['campaign_id' => $c->id, 'subscriber_id' => $s->id, 'recipient_email' => $email, 'gateway_id' => $g->id,
            'status' => 'sent', 'sent_at' => $sent ? now() : null, 'complained_at' => $complained ? now() : null]);
    }

    public function test_default_schedule_ramps_and_ends_at_target(): void
    {
        $s = WarmingService::defaultSchedule();
        $this->assertCount(28, $s);
        $this->assertSame(100000, end($s));
        $this->assertSame($s, collect($s)->sort()->values()->all()); // monotonic
    }

    public function test_daily_cap_blocks_and_failover_takes_over_and_is_logged(): void
    {
        $a = $this->gw('a', 'ma', ['daily_limit' => 1, 'failover_enabled' => true, 'priority' => 1]);
        $b = $this->gw('b', 'mb', ['failover_enabled' => true, 'priority' => 2]);
        $this->msg($a, 'seed@example.com'); // a is at cap
        $m = $this->msg($a, 'x@example.com', false);
        $router = app(GatewayRouter::class);

        $this->assertSame('daily_cap', $router->capReason($a));
        [$res, $used] = $router->send($m, $a, fn ($g) => 'ok-'.$g->name);
        $this->assertSame('ok-b', $res);
        $this->assertSame($b->id, $m->fresh()->gateway_id);
        $this->assertSame('daily_cap', GatewayFailover::first()->reason);
    }

    public function test_all_capped_raises_capacity_exception_and_inactive_gateway_never_sends(): void
    {
        $a = $this->gw('a', 'ma', ['hourly_limit' => 1]);
        $this->msg($a, 'seed@example.com');
        $m = $this->msg($a, 'x@example.com', false);
        try {
            app(GatewayRouter::class)->send($m, $a, fn () => 'sent');
            $this->fail('expected capacity exception');
        } catch (GatewayCapacityException $e) {
            $this->assertGreaterThanOrEqual(60, $e->retryAfterSeconds);
        }
        $a->update(['active' => false, 'hourly_limit' => null]);
        $this->expectException(\RuntimeException::class);
        app(GatewayRouter::class)->send($m, $a->fresh(), fn () => 'sent');
    }

    public function test_send_error_fails_over(): void
    {
        $a = $this->gw('a', 'ma', ['failover_enabled' => true, 'priority' => 1]);
        $this->gw('b', 'mb', ['failover_enabled' => true, 'priority' => 2]);
        $m = $this->msg($a, 'x@example.com', false);
        [$res] = app(GatewayRouter::class)->send($m, $a, function ($g) { if ($g->name === 'a') { throw new \Exception('smtp down'); } return 'ok-b'; });
        $this->assertSame('ok-b', $res);
        $this->assertSame('error', GatewayFailover::first()->reason);
    }

    public function test_warming_cap_applies_even_when_paused_and_advances_once_a_day(): void
    {
        $g = $this->gw('a', 'ma');
        $svc = app(WarmingService::class);
        $plan = $svc->create($g, ['schedule' => [1, 5, 10]]);
        $router = app(GatewayRouter::class);
        $this->msg($g, 'one@example.com');
        $this->assertSame('warming_cap', $router->capReason($g));
        $svc->pause($plan);
        $this->assertSame('warming_cap', $router->capReason($g)); // pausing never lifts the cap

        $svc->resume($plan->fresh());
        $this->travel(1)->days();
        $svc->advance($plan->fresh());
        $svc->advance($plan->fresh()); // same day: no double advance
        $this->assertSame(2, $plan->fresh()->current_day);
        $this->assertNull($router->capReason($g)); // new day, cap 5, nothing sent today
    }

    public function test_complaint_spike_pauses_plan(): void
    {
        $g = $this->gw('a', 'ma');
        $svc = app(WarmingService::class);
        $plan = $svc->create($g, ['schedule' => [1000, 2000]]);
        for ($i = 0; $i < 100; $i++) { $this->msg($g, "u$i@example.com", true, $i < 2); } // 2% complaints
        $svc->advance($plan);
        $this->assertSame('paused', $plan->fresh()->status);
        $this->assertSame(1, $plan->fresh()->current_day);
    }

    public function test_dispatch_routes_through_gateways_when_enabled_and_overview(): void
    {
        config(['laravelmail.features.sending' => true, 'laravelmail.features.deliverability' => true]);
        $a = $this->gw('a', 'ma', ['daily_limit' => 2]);
        foreach (['x', 'y', 'z'] as $n) { Subscriber::create(['email' => "$n@example.com"]); }
        $c = Campaign::create(['name' => 'go', 'subject' => 's', 'content' => 'b', 'send_to_all' => true]);
        app(CampaignDispatcher::class)->dispatch($c);

        $this->assertSame(2, Message::where('status', 'sent')->count());
        $this->assertSame(1, Message::where('status', 'pending')->count()); // capped: waits, not dropped
        $this->assertSame(2, count(Mail::mailer('ma')->getSymfonyTransport()->messages()));
        $row = app(DeliverabilityOverview::class)->snapshot()[0];
        $this->assertSame(2, $row['sent_day']);
        $this->assertSame('daily_cap', $row['capped']);
    }

    public function test_gateway_credentials_are_encrypted_and_hidden(): void
    {
        $g = Gateway::create(['name' => 'smtp', 'mailer' => ['transport' => 'smtp', 'host' => 'h', 'password' => 'topsecret']]);
        $raw = \DB::table('laravelmail_gateways')->value('mailer');
        $this->assertStringNotContainsString('topsecret', $raw);
        $this->assertArrayNotHasKey('mailer', $g->fresh()->toArray());
    }
}
