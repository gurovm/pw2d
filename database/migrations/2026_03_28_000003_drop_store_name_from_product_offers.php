<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_offers', function (Blueprint $table) {
            if (Schema::hasColumn('product_offers', 'store_name')) {
                $table->dropColumn('store_name');
            }
        });

        // Separate call — SQLite requires schema changes in separate statements
        Schema::table('product_offers', function (Blueprint $table) {
            $table->unique(['product_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::table('product_offers', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'store_id']);
            $table->string('store_name', 100)->nullable()->after('store_id');
        });
    }
};
