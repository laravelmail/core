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
        Schema::create($this->p('gateways'), function (Blueprint $t) {
            $t->id();
            $t->string('workspace_id')->nullable()->index();
            $t->string('name');
            $t->text('mailer'); // encrypted: a named host mailer, or a JSON transport array (may hold credentials)
            $t->boolean('active')->default(true);
            $t->boolean('failover_enabled')->default(false);
            $t->unsignedInteger('priority')->default(100);
            $t->unsignedInteger('hourly_limit')->nullable();
            $t->unsignedInteger('daily_limit')->nullable();
            $t->timestamps();
        });

        Schema::create($this->p('warming_plans'), function (Blueprint $t) {
            $t->id();
            $t->string('workspace_id')->nullable()->index();
            $t->foreignId('gateway_id')->constrained($this->p('gateways'))->cascadeOnDelete();
            $t->string('name');
            $t->json('schedule'); // daily send caps, day 1..N
            $t->unsignedInteger('current_day')->default(1);
            $t->string('status')->default('active'); // active|paused|completed
            $t->decimal('pause_complaint_rate', 6, 4)->default(0.003);
            $t->string('paused_reason')->nullable();
            $t->date('started_on')->nullable();
            $t->timestamp('last_advanced_at')->nullable();
            $t->timestamps();
        });

        Schema::create($this->p('gateway_failovers'), function (Blueprint $t) {
            $t->id();
            $t->string('workspace_id')->nullable()->index();
            $t->unsignedBigInteger('message_id')->nullable()->index();
            $t->unsignedBigInteger('from_gateway_id')->nullable();
            $t->unsignedBigInteger('to_gateway_id')->nullable();
            $t->string('reason');
            $t->text('error')->nullable();
            $t->timestamps();
        });

        Schema::table($this->p('messages'), function (Blueprint $t) {
            $t->unsignedBigInteger('gateway_id')->nullable()->index();
            $t->timestamp('complained_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->p('messages'), function (Blueprint $t) {
            $t->dropIndex($this->p('messages').'_gateway_id_index');
        });
        Schema::table($this->p('messages'), fn (Blueprint $t) => $t->dropColumn(['gateway_id', 'complained_at']));
        Schema::dropIfExists($this->p('gateway_failovers'));
        Schema::dropIfExists($this->p('warming_plans'));
        Schema::dropIfExists($this->p('gateways'));
    }
};
