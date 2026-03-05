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
        // 1. Add nullable column first
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('brand_id')->constrained()->cascadeOnDelete();
        });

        // 2. Migrate Data
        $pivotRecords = \Illuminate\Support\Facades\DB::table('category_product')->get();
        foreach ($pivotRecords as $record) {
            // Assign the first category found to the product
            \Illuminate\Support\Facades\DB::table('products')
                ->where('id', $record->product_id)
                ->whereNull('category_id')
                ->update(['category_id' => $record->category_id]);
        }
        
        // 3. Delete orphans (strict mode)
        \Illuminate\Support\Facades\DB::table('products')->whereNull('category_id')->delete();

        // 4. Make column required
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('category_id')->nullable(false)->change();
        });

        // 5. Drop pivot table
        Schema::dropIfExists('category_product');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 1. Re-create pivot table
        Schema::create('category_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        // 2. Migrate Data Back
        $products = \Illuminate\Support\Facades\DB::table('products')->whereNotNull('category_id')->get();
        foreach ($products as $product) {
            \Illuminate\Support\Facades\DB::table('category_product')->insert([
                'product_id' => $product->id,
                'category_id' => $product->category_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 3. Drop column
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
        });
    }
};
