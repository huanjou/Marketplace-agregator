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
        Schema::create('search_caches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('cache_key', 64)->unique();
            $table->string('query_text', 512);
            $table->jsonb('filters')->default('{}');
            $table->string('sort', 64)->nullable();
            $table->jsonb('providers')->default('[]');
            $table->integer('result_count')->default(0);
            $table->timestampTz('expires_at');
            $table->timestamps();

            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('search_caches');
    }
};
