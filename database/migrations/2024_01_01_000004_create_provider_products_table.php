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
        Schema::create('provider_products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('provider_id');
            $table->string('provider_code', 64);
            $table->string('external_product_id', 128)->nullable();
            $table->string('external_offer_id', 128)->nullable();
            $table->string('external_category_id', 128)->nullable();
            $table->text('external_url')->nullable();
            $table->string('title', 512);
            $table->string('brand', 255)->nullable();
            // Prices are stored in minor units (kopecks) to avoid float rounding.
            $table->bigInteger('price_amount')->nullable();
            $table->bigInteger('old_price_amount')->nullable();
            $table->char('currency', 3)->default('RUB');
            $table->string('availability_status', 64)->nullable();
            $table->integer('stock_quantity')->nullable();
            $table->decimal('rating_value', 3, 2)->nullable();
            $table->integer('rating_count')->nullable();
            $table->integer('sales_rank')->nullable();
            $table->jsonb('image_urls')->nullable();
            $table->jsonb('raw_payload')->default('{}');
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestamps();

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->nullOnDelete();

            $table->foreign('provider_id')
                ->references('id')
                ->on('providers')
                ->cascadeOnDelete();

            $table->unique(
                ['provider_id', 'external_product_id', 'external_offer_id'],
                'provider_products_external_unique'
            );

            $table->index('provider_code');
            $table->index('price_amount');
            $table->index('brand');
            $table->index('external_category_id');
            $table->index('availability_status');
        });

        DB::statement("CREATE INDEX provider_products_title_search_idx ON provider_products USING gin(to_tsvector('simple', title))");
        DB::statement('CREATE INDEX provider_products_raw_payload_idx ON provider_products USING gin(raw_payload)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS provider_products_title_search_idx');
        DB::statement('DROP INDEX IF EXISTS provider_products_raw_payload_idx');

        Schema::dropIfExists('provider_products');
    }
};
