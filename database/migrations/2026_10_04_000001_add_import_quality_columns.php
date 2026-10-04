<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 041 — `categories.bouncer_notes` (what does NOT belong in the category,
 * fed to the Bouncer prompt) and `products.model` (the model identity the
 * Bouncer writes at import; drives the pick guard's duplicate check). No index:
 * both are read in PHP over a bounded category pool.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->text('bouncer_notes')->nullable();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('model', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('model');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('bouncer_notes');
        });
    }
};
