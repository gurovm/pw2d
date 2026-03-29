<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Drop old unique/index constraints that reference store_name.
        // On MySQL, the FK on product_id may use the unique index as backing —
        // must drop FK first, then the unique, then re-add FK with a plain index.
        if (DB::getDriverName() === 'mysql') {
            // Drop FK so unique index can be released
            try {
                Schema::table('product_offers', fn (Blueprint $t) => $t->dropForeign(['product_id']));
            } catch (\Exception) {}

            // Drop the stale unique and composite indexes
            try {
                Schema::table('product_offers', fn (Blueprint $t) => $t->dropUnique(['product_id', 'store_name']));
            } catch (\Exception) {}
            try {
                Schema::table('product_offers', fn (Blueprint $t) => $t->dropIndex(['tenant_id', 'store_name']));
            } catch (\Exception) {}

            // Re-add FK with its own index
            Schema::table('product_offers', fn (Blueprint $t) => $t->foreign('product_id')->references('id')->on('products')->cascadeOnDelete());
        } else {
            // SQLite: indexes are auto-dropped with columns, just attempt cleanup
            Schema::table('product_offers', function (Blueprint $table) {
                try { $table->dropUnique(['product_id', 'store_name']); } catch (\Exception) {}
                try { $table->dropIndex(['tenant_id', 'store_name']); } catch (\Exception) {}
            });
        }

        // Step 2: Add store_id column (nullable temporarily)
        Schema::table('product_offers', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('product_id')->constrained()->cascadeOnDelete();
        });

        // Step 2: Create Store records from existing store_name values and backfill store_id
        if (Schema::hasColumn('product_offers', 'store_name')) {
            $distinctStores = DB::table('product_offers')
                ->select('store_name', 'tenant_id')
                ->distinct()
                ->whereNotNull('store_name')
                ->get();

            foreach ($distinctStores as $row) {
                $storeId = DB::table('stores')->insertGetId([
                    'tenant_id'  => $row->tenant_id,
                    'name'       => $row->store_name,
                    'slug'       => Str::slug($row->store_name),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('product_offers')
                    ->where('store_name', $row->store_name)
                    ->where(function ($q) use ($row) {
                        if ($row->tenant_id) {
                            $q->where('tenant_id', $row->tenant_id);
                        } else {
                            $q->whereNull('tenant_id');
                        }
                    })
                    ->update(['store_id' => $storeId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('product_offers', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
            $table->dropColumn('store_id');
        });

        DB::table('stores')->truncate();
    }
};
