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
        Schema::create('products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('canonical_sku', 128)->nullable();
            $table->string('name', 512);
            $table->string('slug', 768)->nullable();
            $table->string('brand', 255)->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->text('image_url')->nullable();
            $table->jsonb('attributes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('category_id')
                ->references('id')
                ->on('categories')
                ->nullOnDelete();

            $table->index('brand');
            $table->index('category_id');
            $table->index('is_active');
        });

        DB::statement("CREATE INDEX products_name_search_idx ON products USING gin(to_tsvector('simple', name))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_name_search_idx');

        Schema::dropIfExists('products');
    }
};
