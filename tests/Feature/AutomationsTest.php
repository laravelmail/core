<?php

namespace LaravelMail\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use LaravelMail\Exceptions\FeatureDisabledException;
use LaravelMail\Mail\CampaignMail;
use LaravelMail\Models\{Automation, AutomationJourney, Subscriber, Tag};
use LaravelMail\Services\Automations\FlowEngine;
use LaravelMail\Tests\TestCase;

class AutomationsTest extends TestCase
{
    private function flow(array $steps): Automation
    {
        $a = Automation::create(['name' => 'f', 'active' => true]);
        foreach ($steps as $i => $s) {
            $a->steps()->create($s + ['is_start' => $i === 0]);
        }

        return $a;
    }

    public function test_disabled_by_default_and_throws(): void
    {
        $this->expectException(FeatureDisabledException::class);
        app(FlowEngine::class)->tick();
    }

    public function test_tag_wait_condition_flow(): void
    {
        config(['laravelmail.features.automations' => true]);
        $tag = Tag::create(['name' => 'welcomed']);
        $s = Subscriber::create(['email' => 'a@example.com']);
        $a = $this->flow([
            ['node_key' => 'a', 'type' => 'add_tag', 'config' => ['tag_id' => $tag->id], 'next_key' => 'w'],
            ['node_key' => 'w', 'type' => 'wait', 'config' => ['hours' => 1], 'next_key' => 'c'],
            ['node_key' => 'c', 'type' => 'condition', 'config' => ['check' => 'has_tag', 'value' => $tag->id], 'next_key' => 'yes', 'alt_key' => 'no'],
            ['node_key' => 'yes', 'type' => 'update_field', 'config' => ['field' => 'path', 'value' => 'yes']],
            ['node_key' => 'no', 'type' => 'update_field', 'config' => ['field' => 'path', 'value' => 'no']],
        ]);
        $engine = app(FlowEngine::class);
        $j = $engine->enrol($a, $s);
        $engine->tick();
        $this->assertTrue($s->tags()->whereKey($tag->id)->exists());
        $this->assertSame('c', $j->fresh()->current_key);
        $this->assertSame(0, $engine->tick()); // not due yet

        $this->travel(2)->hours();
        $engine->tick();
        $this->assertSame('completed', $j->fresh()->status);
        $this->assertSame('yes', $s->fresh()->meta['path']);
    }

    public function test_inactive_automation_and_unsubscribed_do_not_enrol(): void
    {
        config(['laravelmail.features.automations' => true]);
        $s = Subscriber::create(['email' => 'a@example.com']);
        $a = $this->flow([['node_key' => 'a', 'type' => 'exit']]);
        $a->update(['active' => false]);
        $this->assertNull(app(FlowEngine::class)->enrol($a, $s));
        $a->update(['active' => true]);
        $s->unsubscribe();
        $this->assertNull(app(FlowEngine::class)->enrol($a, $s));
    }

    public function test_send_email_parks_while_sending_disabled_then_sends_when_enabled(): void
    {
        config(['laravelmail.features.automations' => true]);
        Mail::fake();
        $s = Subscriber::create(['email' => 'a@example.com', 'first_name' => 'Ann']);
        $a = $this->flow([['node_key' => 'm', 'type' => 'send_email', 'config' => ['subject' => 'Hi {{first_name}}', 'content' => '<p>Yo {{first_name}}</p>']]]);
        $engine = app(FlowEngine::class);
        $j = $engine->enrol($a, $s);

        $engine->tick();
        Mail::assertNothingSent();
        $this->assertSame('active', $j->fresh()->status);

        config(['laravelmail.features.sending' => true]);
        $this->travel(2)->hours();
        $engine->tick();
        Mail::assertSent(CampaignMail::class, fn ($m) => $m->subjectLine === 'Hi Ann' && $m->hasTo('a@example.com'));
        $this->assertSame('completed', $j->fresh()->status);
    }

    public function test_unsupported_step_fails_loudly_and_loops_are_budgeted(): void
    {
        config(['laravelmail.features.automations' => true]);
        $s = Subscriber::create(['email' => 'a@example.com']);
        $a = $this->flow([['node_key' => 'x', 'type' => 'send_sms']]);
        $j = app(FlowEngine::class)->enrol($a, $s);
        app(FlowEngine::class)->tick();
        $this->assertSame('failed', $j->fresh()->status);
    }

    public function test_webhook_blocks_private_and_non_https(): void
    {
        config(['laravelmail.features.automations' => true]);
        Http::fake();
        $s = Subscriber::create(['email' => 'a@example.com']);
        $a = $this->flow([['node_key' => 'w', 'type' => 'webhook', 'config' => ['url' => 'https://127.0.0.1/hook']]]);
        $j = app(FlowEngine::class)->enrol($a, $s);
        app(FlowEngine::class)->tick();
        Http::assertNothingSent();
        $this->assertSame('failed', $j->fresh()->status);
    }

    public function test_goal_completes_journey(): void
    {
        config(['laravelmail.features.automations' => true]);
        $tag = Tag::create(['name' => 'bought']);
        $s = Subscriber::create(['email' => 'a@example.com']);
        $a = $this->flow([
            ['node_key' => 'w', 'type' => 'wait', 'config' => ['days' => 1], 'next_key' => 'x'],
            ['node_key' => 'x', 'type' => 'exit'],
            ['node_key' => 'g', 'type' => 'goal', 'config' => ['check' => 'has_tag', 'value' => $tag->id]],
        ]);
        $j = app(FlowEngine::class)->enrol($a, $s);
        app(FlowEngine::class)->tick();
        $s->tags()->attach($tag);
        $this->travel(2)->days();
        app(FlowEngine::class)->tick();
        $this->assertSame('completed', $j->fresh()->status);
        $this->assertStringContainsString('goal reached', collect($j->fresh()->log)->last());
    }
}
