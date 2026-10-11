<?php

namespace LaravelMail\Tests\Feature;

use Illuminate\Support\Facades\Mail;
use LaravelMail\Models\{Campaign, Subscriber};
use LaravelMail\Services\CampaignDispatcher;
use LaravelMail\Tests\TestCase;

class RotationTest extends TestCase
{
    public function test_pool_rotates_named_mailers_without_touching_host_mail_config(): void
    {
        config(['laravelmail.features.sending' => true, 'laravelmail.sending.pool' => ['m1', 'm2'],
            'mail.mailers.m1' => ['transport' => 'array'], 'mail.mailers.m2' => ['transport' => 'array']]);
        foreach (['a', 'b', 'c', 'd'] as $n) {
            Subscriber::create(['email' => "$n@example.com"]);
        }
        $c = Campaign::create(['name' => 'c', 'subject' => 's', 'content' => 'b', 'send_to_all' => true]);
        app(CampaignDispatcher::class)->dispatch($c);

        $count = fn ($name) => count(Mail::mailer($name)->getSymfonyTransport()->messages());
        $this->assertSame(2, $count('m1'));
        $this->assertSame(2, $count('m2'));
        $this->assertSame('array', config('mail.default'));
    }
}
