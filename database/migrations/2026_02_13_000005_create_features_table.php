<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('unit')->nullable(); // e.g., 'grams', 'hours', 'GB'
            $table->boolean('is_higher_better')->default(true); // Critical for normalization
            $table->float('min_value')->nullable(); // For normalization range
            $table->float('max_value')->nullable(); // For normalization range
            $table->timestamps();

            $table->index('category_id');
            $table->index('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('features');
    }
};
