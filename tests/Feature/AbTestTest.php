<?php

namespace LaravelMail\Tests\Feature;

use Illuminate\Support\Facades\Mail;
use LaravelMail\Models\Campaign;
use LaravelMail\Models\Message;
use LaravelMail\Models\Subscriber;
use LaravelMail\Services\AbTestService;
use LaravelMail\Services\CampaignDispatcher;
use LaravelMail\Tests\TestCase;

class AbTestTest extends TestCase
{
    private function campaign(int $n = 60): Campaign
    {
        for ($i = 0; $i < $n; $i++) {
            Subscriber::create(['email' => "s$i@example.com"]);
        }

        return Campaign::create(['name' => 'C', 'subject' => 'Base', 'content' => 'Base body', 'send_to_all' => true]);
    }

    public function test_validation(): void
    {
        $c = $this->campaign(1);
        $ab = app(AbTestService::class);
        foreach ([[['label' => 'A', 'subject' => 'x']], [['label' => 'A', 'subject' => 'x'], ['label' => 'A', 'subject' => 'y']], [['label' => 'A', 'subject' => 'x'], ['label' => 'B']]] as $bad) {
            try {
                $ab->define($c, $bad);
                $this->fail('expected exception');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_assignment_is_stable_and_roughly_weighted(): void
    {
        $c = $this->campaign(1);
        $ab = app(AbTestService::class);
        $ab->define($c, [['label' => 'A', 'subject' => 'a', 'weight' => 80], ['label' => 'B', 'subject' => 'b', 'weight' => 20]]);
        $counts = ['A' => 0, 'B' => 0];
        for ($i = 1; $i <= 1000; $i++) {
            $v = $ab->assign($c, $i);
            $this->assertSame($v->id, $ab->assign($c, $i)->id);
            $counts[$v->label]++;
        }
        $this->assertGreaterThan(700, $counts['A']);
        $this->assertGreaterThan(100, $counts['B']);
    }

    public function test_dispatch_sends_variant_subjects_and_records_results(): void
    {
        config(['laravelmail.features.sending' => true]);
        Mail::fake();
        $c = $this->campaign();
        app(AbTestService::class)->define($c, [['label' => 'A', 'subject' => 'Subject A'], ['label' => 'B', 'subject' => 'Subject B', 'content' => 'B body']]);
        app(CampaignDispatcher::class)->dispatch($c);

        $this->assertSame(60, Message::whereNotNull('variant_id')->count());
        $subjects = [];
        Mail::assertSent(\LaravelMail\Mail\CampaignMail::class, function ($m) use (&$subjects) {
            $subjects[$m->envelope()->subject] = true;

            return true;
        });
        $this->assertEqualsCanonicalizing(['Subject A', 'Subject B'], array_keys($subjects));

        $a = \LaravelMail\Models\CampaignVariant::where('label', 'A')->first();
        Message::where('variant_id', $a->id)->update(['opened_at' => now()]);
        $this->assertSame('A', app(AbTestService::class)->leader($c));
        $this->assertNull(app(AbTestService::class)->leader($c, 'click_rate')); // no clicks: tie at 0
        // sent campaigns can no longer change variants
        $this->expectException(\LaravelMail\Exceptions\InvalidCampaignStateException::class);
        app(AbTestService::class)->define($c->fresh(), []);
    }

    public function test_campaign_without_variants_unchanged(): void
    {
        config(['laravelmail.features.sending' => true]);
        Mail::fake();
        $c = $this->campaign(3);
        app(CampaignDispatcher::class)->dispatch($c);
        $this->assertSame(0, Message::whereNotNull('variant_id')->count());
        Mail::assertSent(\LaravelMail\Mail\CampaignMail::class, 3);
    }
}
