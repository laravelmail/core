<?php

namespace LaravelMail\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use LaravelMail\Tests\TestCase;

class MigrationsTest extends TestCase
{
    public function test_every_migration_rolls_back_cleanly(): void
    {
        $this->assertTrue(Schema::hasTable('laravelmail_messages'));
        $this->artisan('migrate:rollback', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();
        foreach (['subscribers', 'tags', 'campaigns', 'messages', 'segments', 'automations', 'pending_subscriptions'] as $t) {
            $this->assertFalse(Schema::hasTable('laravelmail_'.$t), $t);
        }
    }
}
