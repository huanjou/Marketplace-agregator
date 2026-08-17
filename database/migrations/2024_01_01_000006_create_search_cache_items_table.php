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
        Schema::create('search_cache_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('search_cache_id');
            $table->unsignedBigInteger('provider_product_id')->nullable();
            $table->string('provider_code', 64);
            $table->string('external_product_id', 128)->nullable();
            $table->integer('rank');
            $table->decimal('score', 8, 4)->nullable();
            $table->jsonb('snapshot');
            $table->timestamps();

            $table->foreign('search_cache_id')
                ->references('id')
                ->on('search_caches')
                ->cascadeOnDelete();

            $table->foreign('provider_product_id')
                ->references('id')
                ->on('provider_products')
                ->nullOnDelete();

            $table->unique(['search_cache_id', 'rank']);
            $table->index('provider_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('search_cache_items');
    }
};
