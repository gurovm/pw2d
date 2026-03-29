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
        // Step 1: Drop old unique constraint that references store_name
        // (must happen before store_name can be dropped in a later migration)
        Schema::table('product_offers', function (Blueprint $table) {
            try {
                $table->dropUnique(['product_id', 'store_name']);
            } catch (\Exception) {}
            try {
                $table->dropIndex(['tenant_id', 'store_name']);
            } catch (\Exception) {}
        });

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
