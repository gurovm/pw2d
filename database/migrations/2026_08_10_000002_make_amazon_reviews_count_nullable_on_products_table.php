<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 029 §A1 — `amazon_reviews_count` was `unsignedInteger()->default(0)`, which made
 * it impossible to distinguish "scraper didn't report a review count" from "genuinely
 * zero reviews." Ingestion paths now store `null` for the former; the column must allow it.
 * Builder judgment call (not explicit in Spec 029) — logged in docs/questions.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('amazon_reviews_count')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        // Reviewer N (2026-08-10): backfill nulls BEFORE re-adding the NOT NULL
        // constraint — this column has genuinely stored nulls since Spec 029 shipped
        // ("scraper didn't report a count"), so a bare ->change() to NOT NULL DEFAULT 0
        // would fail on MySQL the moment any row has a null value.
        DB::table('products')->whereNull('amazon_reviews_count')->update(['amazon_reviews_count' => 0]);

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('amazon_reviews_count')->default(0)->change();
        });
    }
};
