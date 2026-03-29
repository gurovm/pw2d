<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix: The 2026_03_28_000003 migration dropped the `store_name` column but
 * did not drop the indexes that referenced it. MySQL kept the indexes alive
 * with just the remaining column (`product_id` / `tenant_id`), creating:
 *
 * - `product_offers_product_id_store_name_unique` → unique on product_id ONLY
 *   (blocks multi-store offers entirely)
 * - `product_offers_tenant_id_store_name_index` → index on tenant_id only
 *   (redundant but harmless)
 *
 * This migration drops both stale indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL-only fix: SQLite auto-drops indexes when columns are removed,
        // but MySQL leaves orphaned indexes behind.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $existing = DB::table('INFORMATION_SCHEMA.STATISTICS')
            ->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
            ->where('TABLE_NAME', 'product_offers')
            ->where('INDEX_NAME', 'product_offers_product_id_store_name_unique')
            ->exists();

        if ($existing) {
            DB::statement('ALTER TABLE `product_offers` DROP INDEX `product_offers_product_id_store_name_unique`');
        }
    }

    public function down(): void
    {
        // Cannot restore — the original store_name column no longer exists.
    }
};
