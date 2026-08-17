<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 64)->unique();
            $table->string('name', 160);
            $table->string('provider_class', 255);
            $table->boolean('enabled')->default(true);
            $table->boolean('supports_realtime_search')->default(false);
            $table->boolean('supports_catalog_sync')->default(true);
            $table->jsonb('capabilities')->nullable();
            $table->integer('rate_limit_per_minute')->nullable();
            $table->integer('cache_ttl_seconds')->default(900);
            $table->string('last_health_status', 32)->nullable();
            $table->timestampTz('last_checked_at')->nullable();
            $table->timestamps();

            $table->index('enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('providers');
    }
};
