<?php

namespace LaravelMail\Tests\Feature;

use Illuminate\Support\Facades\DB;
use LaravelMail\Models\{Conversation, ConversationMessage, Deal, Subscriber, Tag};
use LaravelMail\Services\Import\AppImporter;
use LaravelMail\Tests\TestCase;

class AppImportTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.connections.appsrc', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('laravelmail.import.connection', 'appsrc');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $s = DB::connection('appsrc')->getSchemaBuilder();
        $s->create('tags', function ($t) { $t->id(); $t->unsignedBigInteger('workspace_id'); $t->string('name'); });
        $s->create('subscribers', function ($t) {
            $t->id(); $t->unsignedBigInteger('workspace_id'); $t->string('email'); $t->string('first_name')->nullable(); $t->string('last_name')->nullable();
            $t->text('meta')->nullable(); $t->timestamp('unsubscribed_at')->nullable(); $t->string('phone')->nullable(); $t->timestamp('sms_consent_at')->nullable(); $t->timestamps();
        });
        $s->create('tag_subscriber', function ($t) { $t->unsignedBigInteger('tag_id'); $t->unsignedBigInteger('subscriber_id'); });
        $s->create('deals', function ($t) {
            $t->id(); $t->unsignedBigInteger('workspace_id'); $t->unsignedBigInteger('subscriber_id')->nullable(); $t->string('title'); $t->decimal('value', 12, 2)->default(0);
            $t->string('currency', 3)->default('USD'); $t->string('stage')->default('lead'); $t->date('expected_close_on')->nullable(); $t->text('notes')->nullable(); $t->timestamp('closed_at')->nullable(); $t->timestamps();
        });
        $s->create('conversations', function ($t) {
            $t->id(); $t->unsignedBigInteger('workspace_id'); $t->unsignedBigInteger('subscriber_id'); $t->string('subject'); $t->string('status')->default('open');
            $t->timestamp('last_reply_at')->nullable(); $t->unsignedBigInteger('created_by')->nullable(); $t->timestamps();
        });
        $s->create('conversation_messages', function ($t) {
            $t->id(); $t->unsignedBigInteger('conversation_id'); $t->string('sender_type'); $t->unsignedBigInteger('sender_id')->nullable(); $t->text('body');
            $t->string('message_id')->nullable(); $t->timestamp('read_at')->nullable(); $t->timestamps();
        });
        $c = DB::connection('appsrc');
        foreach ([['workspace_id' => 1, 'name' => 'vip'], ['workspace_id' => 2, 'name' => 'other-tenant']] as $row) { $c->table('tags')->insert($row); }
        foreach ([['workspace_id' => 1, 'email' => 'A@Example.com', 'first_name' => 'Ann', 'phone' => '+447700900123', 'sms_consent_at' => '2026-01-01 00:00:00', 'meta' => '{"k":"v"}'],
            ['workspace_id' => 1, 'email' => 'b@example.com', 'unsubscribed_at' => '2026-02-01 00:00:00', 'phone' => '+447700900124', 'sms_consent_at' => null],
            ['workspace_id' => 1, 'email' => 'not-an-email'],
            ['workspace_id' => 2, 'email' => 'tenant2@example.com'],
        ] as $row) { $c->table('subscribers')->insert($row); }
        $c->table('tag_subscriber')->insert(['tag_id' => 1, 'subscriber_id' => 1]);
        foreach ([['workspace_id' => 1, 'subscriber_id' => 1, 'title' => 'D1', 'value' => 10, 'stage' => 'won', 'closed_at' => '2026-03-01 00:00:00'],
            ['workspace_id' => 1, 'subscriber_id' => 3, 'title' => 'orphan', 'stage' => 'lead'],
            ['workspace_id' => 1, 'subscriber_id' => null, 'title' => 'badstage', 'stage' => 'weird'],
        ] as $row) { $c->table('deals')->insert($row); }
        $c->table('conversations')->insert(['workspace_id' => 1, 'subscriber_id' => 1, 'subject' => 'Hi', 'status' => 'closed']);
        foreach ([['conversation_id' => 1, 'sender_type' => 'user', 'sender_id' => 5, 'body' => 'hello', 'message_id' => null],
            ['conversation_id' => 1, 'sender_type' => 'subscriber', 'body' => 'reply', 'message_id' => 'm-1'],
        ] as $row) { $c->table('conversation_messages')->insert($row); }
    }

    public function test_dry_run_writes_nothing(): void
    {
        $stats = (new AppImporter('1'))->run();
        $this->assertSame(0, Subscriber::withoutGlobalScopes()->count());
        $this->assertSame(2, $stats['subscribers']['created']); // invalid email counted as skipped
        $this->assertSame(1, $stats['subscribers']['skipped']);
        $this->assertSame(0, \LaravelMail\Models\ImportMap::count());
    }

    public function test_commit_imports_one_workspace_and_is_repeatable(): void
    {
        (new AppImporter('1', null, true))->run();
        $this->assertSame(2, Subscriber::withoutGlobalScopes()->count());
        $this->assertSame(1, Tag::withoutGlobalScopes()->count()); // other tenant's tag not copied
        $a = Subscriber::withoutGlobalScopes()->where('email', 'a@example.com')->first();
        $this->assertSame('1', $a->workspace_id);
        $this->assertSame(['vip'], $a->tags->pluck('name')->all());
        $this->assertSame(['k' => 'v'], $a->meta);
        $this->assertNotNull($a->sms_consent_at);           // carried: the app recorded when
        $b = Subscriber::withoutGlobalScopes()->where('email', 'b@example.com')->first();
        $this->assertSame('unsubscribed', $b->status);
        $this->assertNull($b->sms_consent_at);              // no recorded consent date: never invented
        $this->assertSame(0, Subscriber::withoutGlobalScopes()->where('email', 'tenant2@example.com')->count());

        $this->assertSame(1, Deal::withoutGlobalScopes()->count()); // orphan + bad stage skipped
        $this->assertNotNull(Deal::withoutGlobalScopes()->first()->closed_at);
        $this->assertSame(1, Conversation::withoutGlobalScopes()->count());
        $this->assertSame(2, ConversationMessage::withoutGlobalScopes()->count());
        $this->assertSame('closed', Conversation::withoutGlobalScopes()->first()->status);

        $stats = (new AppImporter('1', null, true))->run();   // second run adds nothing
        $this->assertSame(2, $stats['subscribers']['existing']);
        $this->assertSame(0, $stats['conversation_messages']['created'] ?? 0);
        $this->assertSame(2, ConversationMessage::withoutGlobalScopes()->count());
        $this->assertSame(1, Deal::withoutGlobalScopes()->count());
    }

    public function test_existing_package_subscriber_is_reused_not_overwritten(): void
    {
        Subscriber::withoutGlobalScopes()->create(['workspace_id' => '1', 'email' => 'a@example.com', 'first_name' => 'Local']);
        (new AppImporter('1', null, true))->run(['subscribers']);
        $this->assertSame(1, Subscriber::withoutGlobalScopes()->where('email', 'a@example.com')->count());
        $this->assertSame('Local', Subscriber::withoutGlobalScopes()->where('email', 'a@example.com')->value('first_name'));
    }

    public function test_command_requires_workspace_and_dry_runs_by_default(): void
    {
        $this->artisan('laravelmail:import-app')->assertFailed();
        $this->artisan('laravelmail:import-app', ['--workspace' => '1'])->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertSame(0, Subscriber::withoutGlobalScopes()->count());
        $this->artisan('laravelmail:import-app', ['--workspace' => '1', '--entities' => ['nope']])->assertFailed();
    }
}
