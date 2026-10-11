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
            $t->string('phone', 20)->nullable();            // E.164
            $t->timestamp('sms_consent_at')->nullable();    // explicit opt-in; no consent = never texted
            $t->timestamp('sms_opted_out_at')->nullable();
        });

        Schema::create($this->p('sms_messages'), function (Blueprint $t) {
            $t->id();
            $t->string('workspace_id')->nullable()->index();
            $t->foreignId('subscriber_id')->nullable()->constrained($this->p('subscribers'))->nullOnDelete();
            $t->string('to', 20);
            $t->text('body');
            $t->string('status')->default('pending'); // pending | sent | failed | skipped
            $t->string('driver', 50)->nullable();
            $t->string('provider_id')->nullable();
            $t->text('error')->nullable();            // failure reason, or why it was skipped
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->index(['subscriber_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->p('sms_messages'));
        Schema::table($this->p('subscribers'), function (Blueprint $t) {
            $t->dropColumn(['phone', 'sms_consent_at', 'sms_opted_out_at']);
        });
    }
};
