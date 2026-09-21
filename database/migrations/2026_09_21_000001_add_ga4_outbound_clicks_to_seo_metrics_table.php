<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 040 — outbound (buy-button) clicks, read from GA4's automatic Enhanced
 * Measurement `click` event. Default 0 (not nullable) because "no clicks that
 * day" is the normal case, not an unknown value — unlike the other ga4_* columns,
 * which stay nullable because they come from a different report entirely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_metrics', function (Blueprint $table) {
            $table->unsignedInteger('ga4_outbound_clicks')->default(0)->after('ga4_bounce_rate');
        });
    }

    public function down(): void
    {
        Schema::table('seo_metrics', function (Blueprint $table) {
            $table->dropColumn('ga4_outbound_clicks');
        });
    }
};
