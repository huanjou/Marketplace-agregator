<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('provider_id')->nullable();
            $table->string('provider_code', 64)->nullable();
            // search, catalog_sync, product_refresh, health_check
            $table->string('operation', 64);
            // running, succeeded, failed, partial
            $table->string('status', 32);
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->jsonb('request_summary')->nullable();
            $table->jsonb('response_summary')->nullable();
            $table->string('error_class', 255)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('provider_id')
                ->references('id')
                ->on('providers')
                ->nullOnDelete();

            $table->index(['provider_code', 'operation']);
            $table->index('status');
        });

        // Descending index for "latest runs first" listings; not expressible via Blueprint.
        DB::statement('CREATE INDEX sync_logs_started_at_desc_idx ON sync_logs (started_at DESC)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sync_logs_started_at_desc_idx');

        Schema::dropIfExists('sync_logs');
    }
};
