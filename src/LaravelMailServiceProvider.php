<?php

declare(strict_types=1);

namespace LaravelMail;

use Illuminate\Support\ServiceProvider;
use LaravelMail\Console\AutomationRunCommand;
use LaravelMail\Console\DoctorCommand;
use LaravelMail\Console\InstallCommand;
use LaravelMail\Console\WarmingAdvanceCommand;
use LaravelMail\Contracts\UserResolver;
use LaravelMail\Contracts\WorkspaceResolver;

/**
 * Registers only inert things: config, bindings, opt-in migrations and commands.
 * It schedules nothing, registers no routes unless enabled, and never touches host mail/auth config.
 */
class LaravelMailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravelmail.php', 'laravelmail');

        $this->app->bind(UserResolver::class, fn ($app) => $app->make(config('laravelmail.user_resolver')));
        $this->app->bind(WorkspaceResolver::class, fn ($app) => $app->make(config('laravelmail.workspace_resolver')));
    }

    public function boot(): void
    {
        if (config('laravelmail.load_migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if (config('laravelmail.routes.enabled')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class, DoctorCommand::class, AutomationRunCommand::class, WarmingAdvanceCommand::class]);
            $this->publishes([__DIR__.'/../config/laravelmail.php' => config_path('laravelmail.php')], 'laravelmail-config');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'laravelmail-migrations');
        }
    }
}
