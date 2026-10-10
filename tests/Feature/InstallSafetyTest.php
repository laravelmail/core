<?php

namespace LaravelMail\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Queue;
use LaravelMail\Tests\TestCase;

class InstallSafetyTest extends TestCase
{
    public function test_sending_is_disabled_by_default(): void
    {
        $this->assertFalse(config('laravelmail.features.sending'));
    }

    public function test_install_schedules_nothing_and_registers_no_routes(): void
    {
        $this->assertCount(0, app(Schedule::class)->events());
        $this->assertFalse(Route::has('laravelmail.unsubscribe'));
    }

    public function test_install_sends_nothing(): void
    {
        Mail::fake();
        Queue::fake();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
    }

    public function test_doctor_reports_ok_and_disabled(): void
    {
        $this->artisan('laravelmail:doctor')->expectsOutputToContain('disabled (safe default)')->assertSuccessful();
    }

    public function test_host_mail_and_auth_config_untouched(): void
    {
        $this->assertSame('array', config('mail.default'));
        $this->assertNull(config('auth.laravelmail'));
    }
}
