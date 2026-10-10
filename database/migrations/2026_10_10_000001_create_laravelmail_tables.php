<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function p(string $t): string
    {
        return config('laravelmail.table_prefix', 'laravelmail_').$t;
    }

    public function up(): void
    {
        Schema::create($this->p('subscribers'), function (Blueprint $t) {
            $t->id();
            $t->string('workspace_id')->nullable()->index();
            $t->string('email');
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('status')->default('subscribed'); // subscribed | unsubscribed
            $t->timestamp('unsubscribed_at')->nullable();
            $t->json('meta')->nullable();
            $t->timestamps();
            $t->unique(['workspace_id', 'email']);
        });

        Schema::create($this->p('tags'), function (Blueprint $t) {
            $t->id();
            $t->string('workspace_id')->nullable()->index();
            $t->string('name');
            $t->timestamps();
            $t->unique(['workspace_id', 'name']);
        });

        Schema::create($this->p('subscriber_tag'), function (Blueprint $t) {
            $t->foreignId('subscriber_id')->constrained($this->p('subscribers'))->cascadeOnDelete();
            $t->foreignId('tag_id')->constrained($this->p('tags'))->cascadeOnDelete();
            $t->primary(['subscriber_id', 'tag_id']);
        });

        Schema::create($this->p('templates'), function (Blueprint $t) {
            $t->id();
            $t->string('workspace_id')->nullable()->index();
            $t->string('name');
            $t->longText('content');
            $t->timestamps();
        });

        Schema::create($this->p('campaigns'), function (Blueprint $t) {
            $t->id();
            $t->string('workspace_id')->nullable()->index();
            $t->string('name');
            $t->string('status')->default('draft'); // draft|queued|sending|sent|cancelled
            $t->foreignId('template_id')->nullable()->constrained($this->p('templates'))->nullOnDelete();
            $t->string('subject')->nullable();
            $t->longText('content')->nullable();
            $t->string('from_name')->nullable();
            $t->string('from_email')->nullable();
            $t->boolean('send_to_all')->default(false);
            $t->timestamp('scheduled_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
        });

        Schema::create($this->p('campaign_tag'), function (Blueprint $t) {
            $t->foreignId('campaign_id')->constrained($this->p('campaigns'))->cascadeOnDelete();
            $t->foreignId('tag_id')->constrained($this->p('tags'))->cascadeOnDelete();
            $t->primary(['campaign_id', 'tag_id']);
        });

        Schema::create($this->p('messages'), function (Blueprint $t) {
            $t->id();
            $t->string('workspace_id')->nullable()->index();
            $t->foreignId('campaign_id')->constrained($this->p('campaigns'))->cascadeOnDelete();
            $t->foreignId('subscriber_id')->constrained($this->p('subscribers'))->cascadeOnDelete();
            $t->string('recipient_email');
            $t->string('subject')->nullable();
            $t->string('status')->default('pending'); // pending|sent|failed
            $t->timestamp('sent_at')->nullable();
            $t->text('error')->nullable();
            $t->timestamps();
            $t->unique(['campaign_id', 'subscriber_id']); // re-dispatch never double-sends
        });

        Schema::create($this->p('suppressions'), function (Blueprint $t) {
            $t->id();
            $t->string('workspace_id')->nullable()->index();
            $t->string('email');
            $t->string('reason')->nullable();
            $t->timestamps();
            $t->unique(['workspace_id', 'email']);
        });
    }

    public function down(): void
    {
        foreach (['suppressions', 'messages', 'campaign_tag', 'campaigns', 'templates', 'subscriber_tag', 'tags', 'subscribers'] as $t) {
            Schema::dropIfExists($this->p($t));
        }
    }
};
