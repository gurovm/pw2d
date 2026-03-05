<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // products: category_id is the #1 query filter — no index existed
        Schema::table('products', function (Blueprint $table) {
            $table->index('category_id', 'idx_products_category_id');
            $table->index(['category_id', 'brand_id'], 'idx_products_category_brand');
            $table->index(['category_id', 'price_tier'], 'idx_products_category_price');
        });

        // search_logs: queried heavily by type, user, and created_at in analytics
        Schema::table('search_logs', function (Blueprint $table) {
            $table->index('type', 'idx_search_logs_type');
            $table->index(['user_id', 'created_at'], 'idx_search_logs_user_time');
            $table->index(['type', 'created_at'], 'idx_search_logs_type_time');
        });

        // settings: always queried by key
        Schema::table('settings', function (Blueprint $table) {
            // key column already has unique, which doubles as an index — no change needed
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_category_id');
            $table->dropIndex('idx_products_category_brand');
            $table->dropIndex('idx_products_category_price');
        });

        Schema::table('search_logs', function (Blueprint $table) {
            $table->dropIndex('idx_search_logs_type');
            $table->dropIndex('idx_search_logs_user_time');
            $table->dropIndex('idx_search_logs_type_time');
        });
    }
};
