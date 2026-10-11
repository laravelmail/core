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
        Schema::create($this->p('campaign_variants'), function (Blueprint $t) {
            $t->id();
            $t->string('workspace_id')->nullable()->index();
            $t->foreignId('campaign_id')->constrained($this->p('campaigns'))->cascadeOnDelete();
            $t->string('label', 50);
            $t->string('subject')->nullable();   // null = use the campaign's
            $t->longText('content')->nullable(); // null = use the campaign's
            $t->unsignedSmallInteger('weight')->default(50);
            $t->timestamps();
            $t->unique(['campaign_id', 'label']);
        });

        Schema::table($this->p('messages'), function (Blueprint $t) {
            $t->unsignedBigInteger('variant_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table($this->p('messages'), function (Blueprint $t) {
            $t->dropIndex([ 'variant_id' ]);
            $t->dropColumn('variant_id');
        });
        Schema::dropIfExists($this->p('campaign_variants'));
    }
};
