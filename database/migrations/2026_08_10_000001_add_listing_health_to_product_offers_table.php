<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 029 §A2 — listing-health fields, populated by the Chrome extension's DOM
 * inspection (Phase B) once shipped. Nullable/absent means "never DOM-checked"
 * (today's behavior for every existing row).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_offers', function (Blueprint $table) {
            // new|renewed|refurbished|open_box|used|unknown
            $table->string('condition', 20)->nullable()->after('stock_status')->index();
            // e.g. ["high_price"] — Amazon buy-box "High price" warning
            $table->json('listing_flags')->nullable()->after('condition');
            // Last time the extension DOM-inspected this listing for condition/flags.
            $table->timestamp('health_checked_at')->nullable()->after('listing_flags');
        });
    }

    public function down(): void
    {
        Schema::table('product_offers', function (Blueprint $table) {
            $table->dropColumn(['condition', 'listing_flags', 'health_checked_at']);
        });
    }
};
