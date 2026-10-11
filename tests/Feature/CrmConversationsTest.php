<?php

namespace LaravelMail\Tests\Feature;

use Illuminate\Support\Facades\Mail;
use LaravelMail\Exceptions\FeatureDisabledException;
use LaravelMail\Mail\CampaignMail;
use LaravelMail\Models\{ApiToken, Conversation, Deal, Subscriber, Suppression};
use LaravelMail\Services\Conversations\ConversationService;
use LaravelMail\Tests\TestCase;

class CrmConversationsTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravelmail.routes.enabled', true);
        $app['config']->set('laravelmail.features.api', true);
        $app['config']->set('laravelmail.features.crm', true);
        $app['config']->set('laravelmail.features.conversations', true);
    }

    private function h(array $abilities = ['*']): array
    {
        return ['Authorization' => 'Bearer '.ApiToken::issue('t', $abilities)[1]];
    }

    public function test_deal_stage_closing_and_api(): void
    {
        $h = $this->h();
        $s = Subscriber::create(['email' => 'a@example.com']);
        $id = $this->postJson('/laravelmail/api/v1/deals', ['title' => 'Big', 'value' => 1200.5, 'currency' => 'gbp', 'subscriber_id' => $s->id], $h)->assertCreated()
            ->assertJsonPath('stage', 'lead')->assertJsonPath('currency', 'GBP')->assertJsonPath('closed_at', null)->json('id');
        $this->patchJson("/laravelmail/api/v1/deals/$id", ['stage' => 'won'], $h)->assertOk();
        $this->assertNotNull(Deal::find($id)->closed_at);
        $this->patchJson("/laravelmail/api/v1/deals/$id", ['stage' => 'proposal'], $h)->assertOk();
        $this->assertNull(Deal::find($id)->closed_at);
        $this->postJson('/laravelmail/api/v1/deals', ['title' => 'X', 'stage' => 'nope'], $h)->assertStatus(422);
        $this->postJson('/laravelmail/api/v1/deals', ['title' => 'X', 'subscriber_id' => 999], $h)->assertStatus(422);
        $this->getJson('/laravelmail/api/v1/deals/summary', $h)->assertJsonPath('0.stage', 'proposal')->assertJsonPath('0.value', 1200.5);
        $this->getJson('/laravelmail/api/v1/deals?stage=won', $h)->assertJsonCount(0, 'data');
    }

    public function test_deal_workspace_isolation(): void
    {
        $a = $this->h(['*']);
        [, $plainB] = ApiToken::issue('b', ['*'], 'ws-b');
        $b = ['Authorization' => 'Bearer '.$plainB];
        $this->postJson('/laravelmail/api/v1/deals', ['title' => 'mine'], $b)->assertCreated();
        $this->assertSame('ws-b', Deal::withoutGlobalScopes()->first()->workspace_id);
        $this->getJson('/laravelmail/api/v1/deals', $a)->assertOk(); // unscoped token sees all; scoped token only its own
        [, $plainC] = ApiToken::issue('c', ['*'], 'ws-c');
        $this->getJson('/laravelmail/api/v1/deals', ['Authorization' => 'Bearer '.$plainC])->assertJsonCount(0, 'data');
    }

    public function test_conversation_flow_inbound_idempotent_and_reopens(): void
    {
        $s = Subscriber::create(['email' => 'a@example.com']);
        $svc = app(ConversationService::class);
        $c = $svc->open($s, 'Hello', 'First message', 7);
        $this->assertSame(1, $c->messages()->count());
        $this->assertNotNull($svc->recordInbound($c, 'Hi back', 'prov-1'));
        $this->assertNull($svc->recordInbound($c, 'Hi back', 'prov-1')); // replay
        $svc->close($c);
        $svc->recordInbound($c->fresh(), 'One more', 'prov-2');
        $this->assertSame('open', $c->fresh()->status);
        $this->assertSame(3, $c->messages()->count());
    }

    public function test_replying_records_but_only_emails_when_allowed(): void
    {
        Mail::fake();
        $h = $this->h(['conversations:write', 'conversations:read']);
        $s = Subscriber::create(['email' => 'a@example.com']);
        $c = $this->postJson('/laravelmail/api/v1/conversations', ['subscriber_id' => $s->id, 'subject' => 'S', 'body' => 'b'], $h)->assertCreated()->json('id');

        $this->postJson("/laravelmail/api/v1/conversations/$c/replies", ['body' => 'record only'], $h)->assertCreated()->assertJsonPath('emailed', false);
        Mail::assertNothingSent();
        // send=true without conversations:send is refused outright
        $this->postJson("/laravelmail/api/v1/conversations/$c/replies", ['body' => 'x', 'send' => true], $h)->assertStatus(403);

        $send = $this->h(['conversations:write', 'conversations:send']);
        $this->postJson("/laravelmail/api/v1/conversations/$c/replies", ['body' => 'x', 'send' => true], $send)->assertCreated()->assertJsonPath('emailed', false); // sending flag off
        Mail::assertNothingSent();

        config(['laravelmail.features.sending' => true]);
        $this->postJson("/laravelmail/api/v1/conversations/$c/replies", ['body' => '<b>hi</b>', 'send' => true], $send)->assertJsonPath('emailed', true);
        Mail::assertSent(CampaignMail::class, fn ($m) => $m->hasTo('a@example.com') && ! str_contains($m->body, '<b>'));

        Suppression::add('a@example.com');
        $this->postJson("/laravelmail/api/v1/conversations/$c/replies", ['body' => 'again', 'send' => true], $send)->assertJsonPath('emailed', false);
        Mail::assertSentCount(1);
    }

    public function test_flags_off_by_default(): void
    {
        config(['laravelmail.features.conversations' => false]);
        $this->expectException(FeatureDisabledException::class);
        app(ConversationService::class)->open(Subscriber::create(['email' => 'z@example.com']), 's', 'b');
    }
}
