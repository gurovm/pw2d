<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 030 §B1 — landing-page freshness engine fields.
 *
 * `stale_reasons`: null (never checked) or a JSON list of reason strings
 * (`pick_ineligible`, `selection_drift`, `price_drift`, `render_short`).
 * An empty array `[]` means "checked and fresh" — the audit ALWAYS writes
 * an array, never leaves it null after a first run.
 *
 * `freshness_checked_at`: last time {@see \App\Actions\AuditLandingPageFreshness}
 * ran against this page (instant observer path or nightly `pw2d:landing-pages:audit`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landing_pages', function (Blueprint $table) {
            $table->json('stale_reasons')->nullable()->after('status');
            $table->timestamp('freshness_checked_at')->nullable()->after('stale_reasons');
        });
    }

    public function down(): void
    {
        Schema::table('landing_pages', function (Blueprint $table) {
            $table->dropColumn(['stale_reasons', 'freshness_checked_at']);
        });
    }
};
