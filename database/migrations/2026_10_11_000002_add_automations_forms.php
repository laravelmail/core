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
        Schema::create($this->p('automations'), function (Blueprint $t) {
            $t->id();
            $t->string('workspace_id')->nullable()->index();
            $t->string('name');
            $t->boolean('active')->default(false); // never runs until switched on
            $t->timestamps();
        });

        Schema::create($this->p('automation_steps'), function (Blueprint $t) {
            $t->id();
            $t->foreignId('automation_id')->constrained($this->p('automations'))->cascadeOnDelete();
            $t->string('node_key');
            $t->string('type');
            $t->string('name')->nullable();
            $t->json('config')->nullable();
            $t->string('next_key')->nullable();
            $t->string('alt_key')->nullable();
            $t->boolean('is_start')->default(false);
            $t->timestamps();
            $t->unique(['automation_id', 'node_key']);
        });

        Schema::create($this->p('automation_journeys'), function (Blueprint $t) {
            $t->id();
            $t->foreignId('automation_id')->constrained($this->p('automations'))->cascadeOnDelete();
            $t->foreignId('subscriber_id')->constrained($this->p('subscribers'))->cascadeOnDelete();
            $t->string('current_key')->nullable();
            $t->string('status')->default('active'); // active|completed|exited|failed
            $t->timestamp('wake_at')->nullable()->index();
            $t->json('log')->nullable();
            $t->timestamps();
            $t->unique(['automation_id', 'subscriber_id']); // one journey per subscriber per automation
        });

        Schema::table($this->p('tags'), function (Blueprint $t) {
            $t->uuid('public_uuid')->nullable()->unique();
            $t->boolean('public_subscribe_enabled')->default(false);
            $t->boolean('double_opt_in')->default(true);
            $t->boolean('preference_topic')->default(false);
            $t->json('allowed_origins')->nullable();
        });

        Schema::create($this->p('pending_subscriptions'), function (Blueprint $t) {
            $t->id();
            $t->string('workspace_id')->nullable();
            $t->foreignId('tag_id')->constrained($this->p('tags'))->cascadeOnDelete();
            $t->string('email');
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('token_hash', 64)->unique();
            $t->timestamp('expires_at');
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->p('pending_subscriptions'));
        Schema::table($this->p('tags'), fn (Blueprint $t) => $t->dropUnique($this->p('tags').'_public_uuid_unique'));
        Schema::table($this->p('tags'), fn (Blueprint $t) => $t->dropColumn(['public_uuid', 'public_subscribe_enabled', 'double_opt_in', 'preference_topic', 'allowed_origins']));
        Schema::dropIfExists($this->p('automation_journeys'));
        Schema::dropIfExists($this->p('automation_steps'));
        Schema::dropIfExists($this->p('automations'));
    }
};
