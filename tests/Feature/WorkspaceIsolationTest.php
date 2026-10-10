<?php

namespace LaravelMail\Tests\Feature;

use LaravelMail\Contracts\WorkspaceResolver;
use LaravelMail\Models\Subscriber;
use LaravelMail\Tests\TestCase;

class WorkspaceIsolationTest extends TestCase
{
    public function test_data_is_scoped_to_resolved_workspace(): void
    {
        $current = 1;
        $this->app->bind(WorkspaceResolver::class, fn () => new class($current) implements WorkspaceResolver {
            public function __construct(public int $id) {}
            public function currentId(): int|string|null { return $this->id; }
        });
        Subscriber::create(['email' => 'one@example.com']);

        $this->app->bind(WorkspaceResolver::class, fn () => new class implements WorkspaceResolver {
            public function currentId(): int|string|null { return 2; }
        });
        $this->assertSame(0, Subscriber::count());
        Subscriber::create(['email' => 'one@example.com']); // same email, other workspace: allowed
        $this->assertSame(1, Subscriber::count());
    }
}
