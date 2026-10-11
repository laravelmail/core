<?php

namespace LaravelMail\Tests\Feature;

use Illuminate\Support\Facades\Event;
use LaravelMail\Events\SendingIpListingChanged;
use LaravelMail\Models\ApiToken;
use LaravelMail\Models\IpPool;
use LaravelMail\Models\SendingIp;
use LaravelMail\Services\Deliverability\BlacklistMonitor;
use LaravelMail\Services\Deliverability\DnsblResolver;
use LaravelMail\Tests\TestCase;

class BlacklistMonitorTest extends TestCase
{
    public static bool $listed = false;

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravelmail.routes.enabled', true);
        $app['config']->set('laravelmail.features.api', true);
        $app['config']->set('laravelmail.features.deliverability', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$listed = false;
        $this->app->instance(DnsblResolver::class, new class extends DnsblResolver {
            public function lookup(string $hostname): array
            {
                return BlacklistMonitorTest::$listed && str_ends_with($hostname, 'zen.spamhaus.org') ? ['127.0.0.2'] : [];
            }
        });
    }

    public function test_check_records_history_and_flips_status_with_events(): void
    {
        Event::fake([SendingIpListingChanged::class]);
        $ip = SendingIp::create(['ip' => '203.0.113.5']);
        $m = app(BlacklistMonitor::class);

        $m->check($ip);
        $this->assertSame('active', $ip->fresh()->status);
        Event::assertNotDispatched(SendingIpListingChanged::class);

        self::$listed = true;
        $c = $m->check($ip->fresh());
        $this->assertSame(1, $c->listed_count);
        $this->assertSame('listed', $ip->fresh()->status);
        $this->assertSame(['zen.spamhaus.org'], $ip->fresh()->listed_zones);
        Event::assertDispatchedTimes(SendingIpListingChanged::class, 1);

        $m->check($ip->fresh()); // still listed: no repeat event
        Event::assertDispatchedTimes(SendingIpListingChanged::class, 1);

        self::$listed = false;
        $m->check($ip->fresh());
        $this->assertSame('active', $ip->fresh()->status);
        Event::assertDispatchedTimes(SendingIpListingChanged::class, 2);
        $this->assertSame(4, $ip->checks()->count());
    }

    public function test_paused_ip_stays_paused_and_is_skipped_by_check_all(): void
    {
        $ip = SendingIp::create(['ip' => '203.0.113.6', 'status' => 'paused']);
        SendingIp::create(['ip' => '203.0.113.7']);
        self::$listed = true;
        $this->assertSame(1, app(BlacklistMonitor::class)->checkAll());
        $this->assertSame('paused', $ip->fresh()->status);
    }

    public function test_command_is_a_noop_with_flag_off_and_works_with_it_on(): void
    {
        SendingIp::create(['ip' => '203.0.113.8']);
        $this->artisan('laravelmail:blacklist-check')->expectsOutputToContain('Checked 1')->assertSuccessful();
        config(['laravelmail.features.deliverability' => false]);
        $this->artisan('laravelmail:blacklist-check')->expectsOutputToContain('off')->assertSuccessful();
        $this->assertSame(1, \LaravelMail\Models\BlacklistCheck::count());
    }

    public function test_api(): void
    {
        $h = ['Authorization' => 'Bearer '.ApiToken::issue('t', ['*'])[1]];
        $pool = $this->postJson('/laravelmail/api/v1/ip-pools', ['name' => 'warm'], $h)->assertCreated()->json('id');
        $this->postJson('/laravelmail/api/v1/ips', ['ip' => 'not-an-ip'], $h)->assertStatus(422);
        $this->postJson('/laravelmail/api/v1/ips', ['ip' => '2001:db8::1'], $h)->assertStatus(422);
        $id = $this->postJson('/laravelmail/api/v1/ips', ['ip' => '198.51.100.9', 'ip_pool_id' => $pool], $h)->assertCreated()->json('id');
        self::$listed = true;
        $this->postJson("/laravelmail/api/v1/ips/$id/check", [], $h)->assertOk()->assertJsonPath('status', 'listed')->assertJsonPath('listed_count', 1);
        $this->getJson("/laravelmail/api/v1/ips/$id/history", $h)->assertJsonCount(1);
        $this->patchJson("/laravelmail/api/v1/ips/$id", ['status' => 'listed'], $h)->assertStatus(422); // status is managed by checks
        $this->deleteJson("/laravelmail/api/v1/ip-pools/$pool", [], $h)->assertNoContent();
        $this->assertNull(SendingIp::find($id)->ip_pool_id);
        $ro = ['Authorization' => 'Bearer '.ApiToken::issue('r', ['deliverability:read'])[1]];
        $this->getJson('/laravelmail/api/v1/ips', $ro)->assertOk();
        $this->postJson("/laravelmail/api/v1/ips/$id/check", [], $ro)->assertStatus(403);
    }
}
