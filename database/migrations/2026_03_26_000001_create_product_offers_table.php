<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('tenant_id')->nullable();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->string('store_name', 100);
            $table->text('url');
            $table->decimal('scraped_price', 10, 2)->nullable();
            $table->string('raw_title', 500);
            $table->string('stock_status', 50)->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'store_name']);
            $table->index(['tenant_id', 'store_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_offers');
    }
};
