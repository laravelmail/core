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
        Schema::create($this->p('rss_feeds'), function (Blueprint $t) {
            $t->id();
            $t->string('workspace_id')->nullable()->index();
            $t->string('name');
            $t->string('url', 2000);
            $t->boolean('active')->default(true);
            $t->timestamp('last_fetched_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamps();
        });

        Schema::create($this->p('rss_items'), function (Blueprint $t) {
            $t->id();
            $t->string('workspace_id')->nullable()->index();
            $t->foreignId('rss_feed_id')->constrained($this->p('rss_feeds'))->cascadeOnDelete();
            $t->string('guid_hash', 64);
            $t->string('title', 500);
            $t->string('link', 2000)->nullable();
            $t->text('summary')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->unsignedBigInteger('campaign_id')->nullable(); // set once the item went into a draft campaign
            $t->timestamps();
            $t->unique(['rss_feed_id', 'guid_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->p('rss_items'));
        Schema::dropIfExists($this->p('rss_feeds'));
    }
};
