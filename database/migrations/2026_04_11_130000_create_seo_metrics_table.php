<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->enum('source', ['gsc', 'ga4']);
            $table->string('url', 500);
            $table->char('url_hash', 64);   // sha256(url) — fast indexed equality
            $table->date('metric_date');

            // GSC fields — null for non-GSC rows
            $table->unsignedInteger('gsc_impressions')->nullable();
            $table->unsignedInteger('gsc_clicks')->nullable();
            $table->decimal('gsc_ctr', 6, 4)->nullable();        // 0.0000 – 1.0000
            $table->decimal('gsc_position', 6, 2)->nullable();   // average position
            $table->string('gsc_top_query', 500)->nullable();    // single highest-impression query

            // GA4 fields — null for non-GA4 rows
            $table->unsignedInteger('ga4_sessions')->nullable();
            $table->unsignedInteger('ga4_users')->nullable();
            $table->unsignedInteger('ga4_engaged_sess')->nullable();
            $table->unsignedInteger('ga4_conversions')->nullable();
            $table->decimal('ga4_bounce_rate', 6, 4)->nullable();

            $table->timestamps();

            // Unique constraint — drives idempotent upserts
            $table->unique(['tenant_id', 'source', 'url_hash', 'metric_date'], 'uniq_tenant_source_urlhash_date');

            // Query indexes
            $table->index(['tenant_id', 'metric_date'], 'idx_tenant_date');
            $table->index(['tenant_id', 'source', 'metric_date'], 'idx_tenant_source_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_metrics');
    }
};
