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
        Schema::table('presets', function (Blueprint $table) {
            $table->text('seo_description')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('presets', function (Blueprint $table) {
            $table->dropColumn('seo_description');
        });
    }
};
