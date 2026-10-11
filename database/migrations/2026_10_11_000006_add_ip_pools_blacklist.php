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
        Schema::create($this->p('ip_pools'), function (Blueprint $t) {
            $t->id();
            $t->string('workspace_id')->nullable()->index();
            $t->string('name');
            $t->string('description')->nullable();
            $t->timestamps();
            $t->unique(['workspace_id', 'name']);
        });

        Schema::create($this->p('sending_ips'), function (Blueprint $t) {
            $t->id();
            $t->string('workspace_id')->nullable()->index();
            $t->foreignId('ip_pool_id')->nullable()->constrained($this->p('ip_pools'))->nullOnDelete();
            $t->string('ip', 15); // IPv4 (DNSBL checks are IPv4 only)
            $t->string('hostname')->nullable();
            $t->string('status')->default('active'); // active | paused | listed
            $t->json('listed_zones')->nullable();
            $t->timestamp('last_checked_at')->nullable();
            $t->timestamps();
            $t->unique(['workspace_id', 'ip']);
        });

        Schema::create($this->p('blacklist_checks'), function (Blueprint $t) {
            $t->id();
            $t->string('workspace_id')->nullable()->index();
            $t->foreignId('sending_ip_id')->constrained($this->p('sending_ips'))->cascadeOnDelete();
            $t->json('results'); // {zone: listed}
            $t->unsignedSmallInteger('listed_count')->default(0);
            $t->timestamp('checked_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->p('blacklist_checks'));
        Schema::dropIfExists($this->p('sending_ips'));
        Schema::dropIfExists($this->p('ip_pools'));
    }
};
