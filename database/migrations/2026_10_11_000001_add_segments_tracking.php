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
        Schema::create($this->p('segments'), function (Blueprint $t) {
            $t->id();
            $t->string('workspace_id')->nullable()->index();
            $t->string('name');
            $t->json('rules')->nullable(); // {"match":"all|any","not":false,"rules":[...]}
            $t->timestamps();
        });

        Schema::create($this->p('campaign_segment'), function (Blueprint $t) {
            $t->foreignId('campaign_id')->constrained($this->p('campaigns'))->cascadeOnDelete();
            $t->foreignId('segment_id')->constrained($this->p('segments'))->cascadeOnDelete();
            $t->boolean('excluded')->default(false);
            $t->primary(['campaign_id', 'segment_id', 'excluded']);
        });

        Schema::table($this->p('messages'), function (Blueprint $t) {
            $t->string('hash', 36)->nullable()->unique();
            $t->timestamp('opened_at')->nullable();
            $t->timestamp('clicked_at')->nullable();
            $t->unsignedInteger('open_count')->default(0);
            $t->unsignedInteger('click_count')->default(0);
        });

        Schema::create($this->p('message_urls'), function (Blueprint $t) {
            $t->id();
            $t->foreignId('message_id')->constrained($this->p('messages'))->cascadeOnDelete();
            $t->string('hash', 32)->index();
            $t->text('url');
            $t->unsignedInteger('click_count')->default(0);
            $t->timestamps();
            $t->unique(['message_id', 'hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->p('message_urls'));
        Schema::table($this->p('messages'), function (Blueprint $t) {
            $t->dropColumn(['hash', 'opened_at', 'clicked_at', 'open_count', 'click_count']);
        });
        Schema::dropIfExists($this->p('campaign_segment'));
        Schema::dropIfExists($this->p('segments'));
    }
};
