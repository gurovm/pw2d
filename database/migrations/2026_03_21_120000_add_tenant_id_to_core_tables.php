<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds tenant_id to all core tables for single-database multi-tenancy.
 *
 * Production-safe: tenant_id is nullable so existing rows (990 products) are untouched.
 * Unique constraints are re-scoped to include tenant_id.
 * Composite indexes lead with tenant_id for optimal query performance.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── categories ───────────────────────────────────────────────
        Schema::table('categories', function (Blueprint $table) {
            $table->string('tenant_id')->nullable()->after('id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            // Re-scope: slug must be unique per tenant, not globally
            $table->dropUnique(['slug']);
            $table->unique(['tenant_id', 'slug']);

            // Composite index for parent lookups within a tenant
            $table->index(['tenant_id', 'parent_id']);
        });

        // ── brands ───────────────────────────────────────────────────
        Schema::table('brands', function (Blueprint $table) {
            $table->string('tenant_id')->nullable()->after('id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->index(['tenant_id', 'name']);
        });

        // ── products ─────────────────────────────────────────────────
        Schema::table('products', function (Blueprint $table) {
            $table->string('tenant_id')->nullable()->after('id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            // Re-scope: slug unique per tenant
            $table->dropUnique(['slug']);
            $table->unique(['tenant_id', 'slug']);

            // Re-scope: external_id + category_id unique per tenant
            $table->dropUnique(['external_id', 'category_id']);
            $table->unique(['tenant_id', 'external_id', 'category_id']);

            // Composite indexes for filtered queries within a tenant
            $table->index(['tenant_id', 'category_id']);
            $table->index(['tenant_id', 'brand_id']);
            $table->index(['tenant_id', 'status']);
        });

        // ── features ─────────────────────────────────────────────────
        Schema::table('features', function (Blueprint $table) {
            $table->string('tenant_id')->nullable()->after('id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->index(['tenant_id', 'category_id']);
        });

        // ── presets ──────────────────────────────────────────────────
        Schema::table('presets', function (Blueprint $table) {
            $table->string('tenant_id')->nullable()->after('id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->index(['tenant_id', 'category_id']);
        });

        // ── search_logs ──────────────────────────────────────────────
        Schema::table('search_logs', function (Blueprint $table) {
            $table->string('tenant_id')->nullable()->after('id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->index(['tenant_id', 'type']);
        });

        // ── settings ─────────────────────────────────────────────────
        Schema::table('settings', function (Blueprint $table) {
            $table->string('tenant_id')->nullable()->after('id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            // Re-scope: key unique per tenant
            $table->dropUnique(['key']);
            $table->unique(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        // ── settings ─────────────────────────────────────────────────
        Schema::table('settings', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'key']);
            $table->string('key')->unique()->change();
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
        });

        // ── search_logs ──────────────────────────────────────────────
        Schema::table('search_logs', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'type']);
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
        });

        // ── presets ──────────────────────────────────────────────────
        Schema::table('presets', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'category_id']);
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
        });

        // ── features ─────────────────────────────────────────────────
        Schema::table('features', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'category_id']);
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
        });

        // ── products ─────────────────────────────────────────────────
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropIndex(['tenant_id', 'brand_id']);
            $table->dropIndex(['tenant_id', 'category_id']);
            $table->dropUnique(['tenant_id', 'external_id', 'category_id']);
            $table->dropUnique(['tenant_id', 'slug']);
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
            $table->unique(['external_id', 'category_id']);
            $table->string('slug')->nullable()->unique()->change();
        });

        // ── brands ───────────────────────────────────────────────────
        Schema::table('brands', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'name']);
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
        });

        // ── categories ───────────────────────────────────────────────
        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'parent_id']);
            $table->dropUnique(['tenant_id', 'slug']);
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
            $table->string('slug')->unique()->change();
        });
    }
};
