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
        Schema::create($this->p('deals'), function (Blueprint $t) {
            $t->id();
            $t->string('workspace_id')->nullable()->index();
            $t->foreignId('subscriber_id')->nullable()->constrained($this->p('subscribers'))->nullOnDelete();
            $t->string('title');
            $t->decimal('value', 12, 2)->default(0);
            $t->string('currency', 3)->default('USD');
            $t->string('stage', 20)->default('lead')->index(); // lead|qualified|proposal|won|lost
            $t->date('expected_close_on')->nullable();
            $t->text('notes')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
        });

        Schema::create($this->p('conversations'), function (Blueprint $t) {
            $t->id();
            $t->string('workspace_id')->nullable()->index();
            $t->foreignId('subscriber_id')->constrained($this->p('subscribers'))->cascadeOnDelete();
            $t->string('subject');
            $t->string('status', 20)->default('open')->index(); // open|closed
            $t->timestamp('last_reply_at')->nullable();
            $t->string('created_by')->nullable(); // host user id, opaque: no FK to the host's users table
            $t->timestamps();
        });

        Schema::create($this->p('conversation_messages'), function (Blueprint $t) {
            $t->id();
            $t->string('workspace_id')->nullable()->index();
            $t->foreignId('conversation_id')->constrained($this->p('conversations'))->cascadeOnDelete();
            $t->string('sender_type', 20);                 // user | subscriber
            $t->string('sender_id')->nullable();           // host user id when sender_type=user
            $t->text('body');
            $t->string('provider_message_id')->nullable(); // dedupes inbound replays
            $t->timestamp('sent_at')->nullable();          // set only when the reply was actually emailed
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->unique(['conversation_id', 'provider_message_id']);
        });

        // Maps ids from an adopted app's schema to this package's ids, so imports are repeatable and never duplicate.
        Schema::create($this->p('import_map'), function (Blueprint $t) {
            $t->id();
            $t->string('source', 50);   // e.g. "app"
            $t->string('entity', 50);   // subscribers | tags | deals | conversations | conversation_messages
            $t->string('source_id', 50);
            $t->unsignedBigInteger('target_id');
            $t->timestamps();
            $t->unique(['source', 'entity', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->p('import_map'));
        Schema::dropIfExists($this->p('conversation_messages'));
        Schema::dropIfExists($this->p('conversations'));
        Schema::dropIfExists($this->p('deals'));
    }
};
