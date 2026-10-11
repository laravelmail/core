<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function t(): string
    {
        return config('laravelmail.table_prefix', 'laravelmail_').'api_tokens';
    }

    public function up(): void
    {
        Schema::create($this->t(), function (Blueprint $t) {
            $t->id();
            $t->string('workspace_id')->nullable()->index();
            $t->string('name');
            $t->string('token_hash', 64)->unique(); // sha256; the plain token is shown once at creation
            $t->json('abilities')->nullable();      // null/[] = no access; ["*"] = all
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->t());
    }
};
