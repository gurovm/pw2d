<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Drop the single unique index
            $table->dropUnique('products_external_id_unique');

            // Add composite unique index for (external_id + category_id)
            // This allows the same external_id to exist in multiple categories
            $table->unique(['external_id', 'category_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Drop composite index
            $table->dropUnique(['external_id', 'category_id']);

            // Restore single unique index
            $table->unique('external_id');
        });
    }
};
