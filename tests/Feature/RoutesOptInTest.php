<?php

namespace LaravelMail\Tests\Feature;

use LaravelMail\Models\Subscriber;
use LaravelMail\Tests\TestCase;

class RoutesOptInTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravelmail.routes.enabled', true);
    }

    public function test_unsubscribe_requires_signature(): void
    {
        $s = Subscriber::create(['email' => 'a@example.com']);
        $this->get('/laravelmail/unsubscribe/'.$s->id)->assertForbidden();
        $this->get($s->unsubscribeUrl())->assertOk();
        $this->assertSame('unsubscribed', $s->fresh()->status);
    }
}
