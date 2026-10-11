<?php

namespace LaravelMail\Tests\Feature;

use LaravelMail\Models\ApiToken;
use LaravelMail\Models\Campaign;
use LaravelMail\Models\Subscriber;
use LaravelMail\Models\Message;
use LaravelMail\Tests\TestCase;

class ApiTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravelmail.routes.enabled', true);
        $app['config']->set('laravelmail.features.api', true);
    }

    private function h(array $abilities = ['*'], $ws = null): array
    {
        [, $plain] = ApiToken::issue('t', $abilities, $ws);

        return ['Authorization' => 'Bearer '.$plain];
    }

    public function test_requires_a_valid_token_and_ability(): void
    {
        $this->getJson('/laravelmail/api/v1/subscribers')->assertStatus(401);
        $this->getJson('/laravelmail/api/v1/subscribers', ['Authorization' => 'Bearer nope'])->assertStatus(401);
        $this->getJson('/laravelmail/api/v1/subscribers', $this->h(['tags:read']))->assertStatus(403);
        $this->getJson('/laravelmail/api/v1/subscribers', $this->h(['subscribers:read']))->assertOk();
    }

    public function test_expired_token_is_rejected_and_hash_only_is_stored(): void
    {
        [$t, $plain] = ApiToken::issue('x', ['*'], null, now()->subMinute());
        $this->assertNotSame($plain, $t->token_hash);
        $this->getJson('/laravelmail/api/v1/tags', ['Authorization' => 'Bearer '.$plain])->assertStatus(401);
        $this->expectException(\InvalidArgumentException::class);
        ApiToken::issue('bad', ['nonsense']);
    }

    public function test_subscriber_crud_tags_and_no_resubscribe(): void
    {
        $h = $this->h();
        $r = $this->postJson('/laravelmail/api/v1/subscribers', ['email' => 'A@Example.com', 'tags' => ['vip']], $h)->assertCreated();
        $id = $r->json('id');
        $this->assertSame('a@example.com', $r->json('email'));
        $this->assertSame(['vip'], $r->json('tags'));
        $this->postJson('/laravelmail/api/v1/subscribers/'.$id.'/tags', ['tags' => ['b']], $h)->assertOk();
        $this->deleteJson('/laravelmail/api/v1/subscribers/'.$id.'/tags', ['tags' => ['vip']], $h)->assertJsonPath('tags', ['b']);
        Subscriber::find($id)->update(['status' => 'unsubscribed']);
        $this->postJson('/laravelmail/api/v1/subscribers', ['email' => 'a@example.com'], $h)->assertOk()->assertJsonPath('status', 'unsubscribed');
        $this->getJson('/laravelmail/api/v1/subscribers?status=unsubscribed', $h)->assertJsonCount(1, 'data');
        $this->deleteJson('/laravelmail/api/v1/subscribers/'.$id, [], $h)->assertNoContent();
        $this->postJson('/laravelmail/api/v1/subscribers', ['email' => 'not-an-email'], $h)->assertStatus(422);
    }

    public function test_workspace_isolation_comes_from_the_token(): void
    {
        $a = $this->h(['*'], 'ws-a');
        $b = $this->h(['*'], 'ws-b');
        $id = $this->postJson('/laravelmail/api/v1/subscribers', ['email' => 'x@example.com'], $a)->json('id');
        $this->getJson('/laravelmail/api/v1/subscribers/'.$id, $b)->assertNotFound();
        $this->getJson('/laravelmail/api/v1/subscribers', $b)->assertJsonCount(0, 'data');
        $this->getJson('/laravelmail/api/v1/subscribers/'.$id, $a)->assertOk();
    }

    public function test_segments_validate_rules_and_templates_crud(): void
    {
        $h = $this->h();
        $this->postJson('/laravelmail/api/v1/segments', ['name' => 'bad', 'rules' => ['match' => 'all', 'rules' => [['condition' => 'no_such_thing']]]], $h)->assertStatus(422);
        $this->postJson('/laravelmail/api/v1/segments', ['name' => 'all', 'rules' => ['match' => 'all', 'rules' => []]], $h)->assertCreated();
        $t = $this->postJson('/laravelmail/api/v1/templates', ['name' => 'T', 'content' => '<p>hi</p>'], $h)->assertCreated()->json('id');
        $this->putJson('/laravelmail/api/v1/templates/'.$t, ['name' => 'T2', 'content' => 'x'], $h)->assertJsonPath('name', 'T2');
        $this->deleteJson('/laravelmail/api/v1/templates/'.$t, [], $h)->assertNoContent();
    }

    public function test_campaign_via_api_is_draft_and_dispatch_is_gated(): void
    {
        $h = $this->h();
        $this->postJson('/laravelmail/api/v1/subscribers', ['email' => 'p@example.com', 'tags' => ['news']], $h);
        $c = $this->postJson('/laravelmail/api/v1/campaigns', ['name' => 'C', 'subject' => 'S', 'content' => 'Hello', 'tags' => ['news'], 'status' => 'sent'], $h)
            ->assertCreated()->assertJsonPath('status', 'draft')->json('id');
        $this->getJson("/laravelmail/api/v1/campaigns/$c/audience", $h)->assertJsonPath('count', 1);

        // sending feature is off: 503, nothing queued
        $this->postJson("/laravelmail/api/v1/campaigns/$c/dispatch", [], $h)->assertStatus(503);
        $this->assertSame(0, Message::count());
        // a token without campaigns:send cannot even try
        $this->postJson("/laravelmail/api/v1/campaigns/$c/dispatch", [], $this->h(['campaigns:write']))->assertStatus(403);

        config(['laravelmail.features.sending' => true]);
        \Illuminate\Support\Facades\Mail::fake();
        $this->postJson("/laravelmail/api/v1/campaigns/$c/dispatch", [], $h)->assertStatus(202);
        $this->assertSame(1, Message::count());
        $this->patchJson("/laravelmail/api/v1/campaigns/$c", ['name' => 'new'], $h)->assertStatus(409);
        $this->assertNotSame('draft', Campaign::find($c)->status);
    }

    public function test_api_routes_registered_when_flag_on(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('laravelmail.api.tags.index'));
    }

    public function test_token_command_requires_ability(): void
    {
        $this->artisan('laravelmail:token', ['name' => 'k'])->assertFailed();
        $this->artisan('laravelmail:token', ['name' => 'k', '--ability' => ['tags:read']])->assertSuccessful();
        $this->assertSame(1, ApiToken::count());
    }
}
