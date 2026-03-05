<?php

use App\Models\Category;
use App\Models\Feature;
use App\Models\Product;
use App\Models\ProductFeatureValue;

// 1. Get Categories
$microphones = Category::where('name', 'Computer Microphones')->first();
$headsets = Category::where('name', 'Computer Headsets')->first();

if (!$microphones) {
    echo "Microphones category not found.\n";
    exit;
}

echo "Seeding Computer Microphones (ID: {$microphones->id})...\n";

// 2. Copy Features from Headsets if Microphones has none
if ($microphones->features()->count() === 0 && $headsets) {
    echo "Copying features from Headsets...\n";
    $featuresToCopy = $headsets->features;
    
    foreach ($featuresToCopy as $feature) {
        $newFeature = $feature->replicate();
        $newFeature->category_id = $microphones->id;
        $newFeature->save();
        echo " - Copied feature: {$newFeature->name}\n";
    }
} else {
    echo "Microphones already has features (or Headsets not found).\n";
}

// 3. Create/Move Products if Microphones has none
if ($microphones->products()->count() === 0) {
    echo "Creating dummy products for Microphones...\n";
    
    // Create a few dummy products
    $dummyProducts = [
        [
            'name' => 'Blue Yeti USB Microphone',
            'brand_id' => 1, // Assuming Logitech/Blue is 1, or just pick first
            'price_tier' => 2,
            'amazon_rating' => 4.6,
            'image_url' => 'https://m.media-amazon.com/images/I/71u9t2-B7GL._AC_SL1500_.jpg',
        ],
        [
            'name' => 'HyperX QuadCast S',
            'brand_id' => 2, // Assuming HyperX
            'price_tier' => 2,
            'amazon_rating' => 4.8,
            'image_url' => 'https://m.media-amazon.com/images/I/61M6+3pN1SL._AC_SL1500_.jpg',
        ],
        [
            'name' => 'Fifine K669B',
            'brand_id' => 3, // Assuming Fifine
            'price_tier' => 1,
            'amazon_rating' => 4.5,
            'image_url' => 'https://m.media-amazon.com/images/I/61k8f-t60gL._AC_SL1500_.jpg',
        ],
    ];

    foreach ($dummyProducts as $data) {
        $product = Product::create([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']),
            'brand_id' => \App\Models\Brand::first()->id ?? 1, // Fallback
            'price_tier' => $data['price_tier'],
            'amazon_rating' => $data['amazon_rating'],
            'amazon_reviews_count' => 1000,
            'affiliate_url' => 'https://amazon.com',
            'image_url' => $data['image_url'],
            'is_active' => true,
        ]);
        
        // Attach to category
        $product->categories()->attach($microphones->id);
        
        // Add random feature values
        foreach ($microphones->features as $feature) {
             ProductFeatureValue::create([
                'product_id' => $product->id,
                'feature_id' => $feature->id,
                'raw_value' => rand(70, 95), // Random good stats
            ]);
        }
        
        echo " - Created product: {$product->name}\n";
    }
} else {
    echo "Microphones already has products.\n";
}

echo "Done.\n";
