<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_offers', function (Blueprint $table) {
            $table->text('image_url')->nullable()->after('raw_title');
        });

        // Backfill from products.external_image_path for existing Amazon offers
        if (Schema::hasColumn('products', 'external_image_path')) {
            DB::statement('
                UPDATE product_offers
                SET image_url = (
                    SELECT external_image_path FROM products
                    WHERE products.id = product_offers.product_id
                )
                WHERE store_name = "Amazon"
                AND EXISTS (
                    SELECT 1 FROM products
                    WHERE products.id = product_offers.product_id
                    AND products.external_image_path IS NOT NULL
                )
            ');
        }
    }

    public function down(): void
    {
        Schema::table('product_offers', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });
    }
};
