<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            // Nullable: console/central calls (queued jobs, artisan commands) run
            // outside an initialized tenant context and legitimately have no tenant.
            $table->string('tenant_id')->nullable();
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
            $table->string('purpose');
            $table->string('model');
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('thinking_tokens')->nullable();
            // Null when the model isn't in the pricing table — accounting must never
            // block ingestion just because a new/unpriced model was used.
            $table->decimal('estimated_cost_usd', 10, 6)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'purpose', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
    }
};
