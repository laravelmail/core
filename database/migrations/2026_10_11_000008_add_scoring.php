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
        Schema::table($this->p('subscribers'), function (Blueprint $t) {
            $t->integer('score')->default(0);
        });

        Schema::create($this->p('scoring_rules'), function (Blueprint $t) {
            $t->id();
            $t->string('workspace_id')->nullable()->index();
            $t->string('name');
            $t->string('event', 30);                          // email_opened | link_clicked | subscribed | tag_added | custom
            $t->foreignId('tag_id')->nullable()->constrained($this->p('tags'))->nullOnDelete(); // for tag_added
            $t->string('custom_event', 50)->nullable();       // for custom
            $t->integer('points');                            // may be negative
            $t->integer('max_total')->nullable();             // cap on points this rule can give one subscriber
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create($this->p('score_events'), function (Blueprint $t) {
            $t->id();
            $t->string('workspace_id')->nullable()->index();
            $t->foreignId('subscriber_id')->constrained($this->p('subscribers'))->cascadeOnDelete();
            $t->unsignedBigInteger('rule_id')->nullable();
            $t->string('event', 30);
            $t->string('reference', 100)->nullable();         // e.g. message:12; one award per rule+subscriber+reference
            $t->integer('points');
            $t->string('note')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['subscriber_id', 'created_at']);
            $t->unique(['rule_id', 'subscriber_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->p('score_events'));
        Schema::dropIfExists($this->p('scoring_rules'));
        Schema::table($this->p('subscribers'), fn (Blueprint $t) => $t->dropColumn('score'));
    }
};
