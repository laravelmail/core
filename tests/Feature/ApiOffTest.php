<?php

namespace LaravelMail\Tests\Feature;

use LaravelMail\Tests\TestCase;

class ApiOffTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravelmail.routes.enabled', true); // routes on, api flag left at its default
    }

    public function test_api_is_off_by_default_even_with_routes_enabled(): void
    {
        $this->assertFalse(config('laravelmail.features.api'));
        $this->getJson('/laravelmail/api/v1/subscribers')->assertNotFound();
    }
}
