<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Drop standalone name index — replaced by composite
            $table->dropIndex('products_name_index');
            // Composite index for tenant-scoped search by name
            $table->index(['tenant_id', 'name'], 'products_tenant_id_name_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_tenant_id_name_index');
            $table->index('name', 'products_name_index');
        });
    }
};
