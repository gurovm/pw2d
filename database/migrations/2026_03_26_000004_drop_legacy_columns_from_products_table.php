<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 cleanup: drop legacy vendor-specific columns from products.
 * These are now stored in product_offers (scraped_price, external_id → url, external_image_path → image_url).
 *
 * IMPORTANT: This migration first copies all existing product data into
 * product_offers (created by the 000001 migration) using raw SQL, then
 * seeds ai_matching_decisions, THEN drops the old columns. This ensures
 * no data loss when running `php artisan migrate` in a single pass.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Populate product_offers from existing products (if old columns still exist)
        // Uses MySQL-specific INSERT IGNORE — SQLite (tests) skips this safely
        // since test factories create offers directly.
        if (Schema::hasColumn('products', 'external_id') && DB::getDriverName() === 'mysql') {
            DB::statement("
                INSERT IGNORE INTO product_offers (product_id, tenant_id, store_name, url, scraped_price, raw_title, created_at, updated_at)
                SELECT
                    id,
                    tenant_id,
                    'Amazon',
                    CONCAT('https://www.amazon.com/dp/', external_id),
                    scraped_price,
                    SUBSTRING(name, 1, 500),
                    NOW(),
                    NOW()
                FROM products
                WHERE external_id IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1 FROM product_offers WHERE product_offers.product_id = products.id
                )
            ");

            // Backfill image_url from external_image_path (if the column was added by 000003)
            if (Schema::hasColumn('product_offers', 'image_url') && Schema::hasColumn('products', 'external_image_path')) {
                DB::statement("
                    UPDATE product_offers
                    SET image_url = (
                        SELECT external_image_path FROM products
                        WHERE products.id = product_offers.product_id
                        AND products.external_image_path IS NOT NULL
                    )
                    WHERE image_url IS NULL
                    AND store_name = 'Amazon'
                ");
            }

            // Seed ai_matching_decisions from existing products
            if (Schema::hasTable('ai_matching_decisions')) {
                DB::statement("
                    INSERT IGNORE INTO ai_matching_decisions (tenant_id, scraped_raw_name, existing_product_id, is_match, created_at, updated_at)
                    SELECT
                        tenant_id,
                        SUBSTRING(name, 1, 500),
                        id,
                        1,
                        NOW(),
                        NOW()
                    FROM products
                    WHERE external_id IS NOT NULL
                    AND NOT EXISTS (
                        SELECT 1 FROM ai_matching_decisions
                        WHERE ai_matching_decisions.existing_product_id = products.id
                    )
                ");
            }
        }

        // Step 2: Drop the legacy columns
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'external_id')) {
                try {
                    $table->dropUnique(['tenant_id', 'external_id', 'category_id']);
                } catch (\Exception) {
                    // Index may not exist or have different name
                }
                $table->dropColumn('external_id');
            }

            if (Schema::hasColumn('products', 'scraped_price')) {
                $table->dropColumn('scraped_price');
            }

            if (Schema::hasColumn('products', 'external_image_path')) {
                $table->dropColumn('external_image_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('external_id')->nullable()->after('id');
            $table->decimal('scraped_price', 8, 2)->nullable()->after('price_tier');
            $table->string('external_image_path')->nullable()->after('affiliate_url');
        });
    }
};
