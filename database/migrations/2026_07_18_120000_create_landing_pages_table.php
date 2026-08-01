<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Best X" landing pages (Spec 027) — one static-feeling listicle per leaf category,
 * built from deterministically-selected product picks + AI-written prose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_pages', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->nullable();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('title_template')->nullable();
            $table->text('intro')->nullable();
            // [{product_id, role, headline, body}] — role ∈ overall/budget/premium/preset:{slug}
            $table->json('picks');
            // [{question, answer}]
            $table->json('faqs')->nullable();
            $table->text('methodology_note')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            // One landing page per category, per tenant.
            $table->unique(['tenant_id', 'category_id']);
            // Slug lookup for the public /best/{slug} route.
            $table->unique(['tenant_id', 'slug']);
            // Sitemap/listing queries filter by status within a tenant.
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_pages');
    }
};
