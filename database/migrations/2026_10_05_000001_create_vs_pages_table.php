<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Head-to-head "A vs B" pages (Spec 043). Products are ordered alphabetically by name
 * on save (not by id), so the unique index is (tenant_id, product_a_id, product_b_id)
 * and the save command also checks the swapped pair before inserting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vs_pages', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->nullable();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_a_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_b_id')->constrained('products')->cascadeOnDelete();
            $table->string('slug');
            $table->string('title');
            $table->text('intro')->nullable();
            $table->text('verdict')->nullable();
            // {a_wins, b_wins, who_should_buy} — HTML strings
            $table->json('sections')->nullable();
            // [{q, a}]
            $table->json('faqs')->nullable();
            $table->unsignedInteger('price_snapshot_a')->nullable();
            $table->unsignedInteger('price_snapshot_b')->nullable();
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('freshness_checked_at')->nullable();
            $table->json('stale_reasons')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->unique(['tenant_id', 'slug']);
            $table->unique(['tenant_id', 'product_a_id', 'product_b_id']);
            $table->index(['tenant_id', 'category_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vs_pages');
    }
};
