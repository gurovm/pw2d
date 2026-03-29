<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_matching_decisions', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->nullable();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->string('scraped_raw_name', 500);
            $table->foreignId('existing_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->boolean('is_match');
            $table->timestamps();

            $table->index(['tenant_id', 'scraped_raw_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_matching_decisions');
    }
};
