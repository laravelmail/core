<?php

namespace LaravelMail\Tests\Feature;

use LaravelMail\Models\{ApiToken, Campaign, Message, MessageUrl, ScoreEvent, ScoringRule, Subscriber, Tag};
use LaravelMail\Services\Scoring\ScoringService;
use LaravelMail\Services\Subscribers\TagService;
use LaravelMail\Tests\TestCase;

class ScoringTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravelmail.routes.enabled', true);
        $app['config']->set('laravelmail.features.api', true);
        $app['config']->set('laravelmail.features.tracking', true);
        $app['config']->set('laravelmail.features.scoring', true);
    }

    private function msg(Subscriber $s): Message
    {
        $c = Campaign::create(['name' => 'c', 'subject' => 's', 'content' => 'x']);

        return Message::create(['campaign_id' => $c->id, 'subscriber_id' => $s->id, 'recipient_email' => $s->email, 'status' => 'sent']);
    }

    public function test_off_by_default_awards_nothing(): void
    {
        config(['laravelmail.features.scoring' => false]);
        ScoringRule::create(['name' => 'o', 'event' => 'email_opened', 'points' => 5]);
        $s = Subscriber::create(['email' => 'a@example.com']);
        $this->assertSame(0, app(ScoringService::class)->record($s, 'email_opened', 'x'));
        $this->assertSame(0, $s->fresh()->score);
    }

    public function test_open_scores_once_per_message_and_click_once_per_link(): void
    {
        ScoringRule::create(['name' => 'open', 'event' => 'email_opened', 'points' => 2]);
        ScoringRule::create(['name' => 'click', 'event' => 'link_clicked', 'points' => 10]);
        $s = Subscriber::create(['email' => 'a@example.com']);
        $m = $this->msg($s);
        $u = MessageUrl::create(['message_id' => $m->id, 'hash' => 'abc', 'url' => 'https://example.org']);

        foreach ([1, 2, 3] as $_) {
            $this->get('/laravelmail/t/o/'.$m->hash);
        }
        $this->assertSame(2, $s->fresh()->score);
        $this->get('/laravelmail/t/c/'.$m->hash.'/abc')->assertRedirect('https://example.org');
        $this->get('/laravelmail/t/c/'.$m->hash.'/abc')->assertRedirect();
        $this->assertSame(12, $s->fresh()->score);
        $this->assertSame(2, ScoreEvent::where('subscriber_id', $s->id)->count());
    }

    public function test_tag_added_rule_cap_and_negative_points(): void
    {
        $vip = Tag::create(['name' => 'vip']);
        ScoringRule::create(['name' => 'vip tag', 'event' => 'tag_added', 'tag_id' => $vip->id, 'points' => 20]);
        ScoringRule::create(['name' => 'any tag', 'event' => 'tag_added', 'points' => 3, 'max_total' => 4]);
        $s = Subscriber::create(['email' => 'a@example.com']);
        app(TagService::class)->attach($s, ['vip']);
        $this->assertSame(23, $s->fresh()->score);
        app(TagService::class)->attach($s, ['vip', 'other']); // vip already attached: no re-award; cap leaves 1 point
        $this->assertSame(24, $s->fresh()->score);
        app(TagService::class)->attach($s, ['third']);
        $this->assertSame(24, $s->fresh()->score);

        ScoringRule::create(['name' => 'penalty', 'event' => 'custom', 'custom_event' => 'complaint', 'points' => -50]);
        app(ScoringService::class)->record($s, 'custom', 'c1', custom: 'complaint');
        $this->assertSame(-26, $s->fresh()->score);
    }

    public function test_segment_condition_and_decay(): void
    {
        $a = Subscriber::create(['email' => 'a@example.com']);
        Subscriber::create(['email' => 'b@example.com']);
        $svc = app(ScoringService::class);
        $svc->adjust($a, 30, 'seed');
        $q = app(\LaravelMail\Services\Segments\SegmentRuleCompiler::class)->query(['match' => 'all', 'rules' => [['type' => 'lead_score', 'operator' => 'at_least', 'value' => 25]]]);
        $this->assertSame(['a@example.com'], $q->pluck('email')->all());

        $this->assertSame(0, $svc->decay(30, 5)); // recent activity
        $this->travel(31)->days();
        $this->assertSame(1, $svc->decay(30, 50));
        $this->assertSame(0, $a->fresh()->score); // never below zero
    }

    public function test_api_rules_events_and_custom(): void
    {
        $h = ['Authorization' => 'Bearer '.ApiToken::issue('t', ['*'])[1]];
        $this->postJson('/laravelmail/api/v1/scoring-rules', ['name' => 'x', 'event' => 'custom', 'points' => 5], $h)->assertStatus(422);
        $this->postJson('/laravelmail/api/v1/scoring-rules', ['name' => 'x', 'event' => 'custom', 'custom_event' => 'demo', 'points' => 0], $h)->assertStatus(422);
        $this->postJson('/laravelmail/api/v1/scoring-rules', ['name' => 'demo', 'event' => 'custom', 'custom_event' => 'demo', 'points' => 15], $h)->assertCreated();
        $s = Subscriber::create(['email' => 'a@example.com']);
        $this->postJson("/laravelmail/api/v1/subscribers/{$s->id}/score-events", ['event' => 'demo', 'reference' => 'r1'], $h)->assertJsonPath('awarded', 15);
        $this->postJson("/laravelmail/api/v1/subscribers/{$s->id}/score-events", ['event' => 'demo', 'reference' => 'r1'], $h)->assertJsonPath('awarded', 0);
        $this->getJson("/laravelmail/api/v1/subscribers/{$s->id}/score-events", $h)->assertJsonCount(1);
        $this->getJson("/laravelmail/api/v1/subscribers/{$s->id}", $h)->assertJsonPath('score', 15);
    }

    public function test_flow_condition_lead_score(): void
    {
        $s = Subscriber::create(['email' => 'a@example.com', 'score' => 40]);
        $e = app(\LaravelMail\Services\Automations\FlowEngine::class);
        $this->assertTrue($e->check($s, ['check' => 'lead_score', 'value' => 40]));
        $this->assertFalse($e->check($s, ['check' => 'lead_score', 'value' => 41]));
    }
}
