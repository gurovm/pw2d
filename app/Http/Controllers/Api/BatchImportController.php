<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPendingProduct;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BatchImportController extends Controller
{
    /**
     * Accept a bulk list of lightweight products scraped from an Amazon SERP.
     * Saves each as a stub record with status='pending_ai' and dispatches a
     * queue job to run AI feature scoring for each one.
     */
    public function import(Request $request)
    {
        $validated = $request->validate([
            'category_id'                => 'required|exists:categories,id',
            'products'                   => 'required|array|min:1|max:100',
            'products.*.asin'            => 'required|string|max:20',
            'products.*.title'           => 'required|string|max:500',
            'products.*.price'           => 'nullable|numeric|min:0',
            'products.*.rating'          => 'nullable|numeric|min:0|max:5',
            'products.*.reviews_count'   => 'nullable|integer|min:0',
            'products.*.image_url'       => 'nullable|url|max:1000',
        ]);

        $category = Category::with('features')->findOrFail($validated['category_id']);

        if ($category->features->isEmpty()) {
            return response()->json([
                'success' => false,
                'error'   => 'No Features',
                'message' => 'The selected category has no features defined. Add features before importing.',
            ], 400);
        }

        $saved = 0;

        foreach ($validated['products'] as $p) {
            try {
                $product = Product::updateOrCreate(
                    [
                        'external_id' => $p['asin'],
                        'category_id' => $category->id,
                    ],
                    [
                        'name'                 => Str::limit($p['title'], 255),
                        'slug'                 => Str::slug(Str::limit($p['title'], 80)) . '-' . strtolower($p['asin']),
                        'external_image_path'  => $p['image_url'] ?? null,
                        'amazon_rating'        => $p['rating'] ?? null,
                        'amazon_reviews_count' => $p['reviews_count'] ?? 0,
                        'price_tier'           => $this->guessPriceTier($p['price'] ?? null),
                        'status'               => 'pending_ai',
                        'is_ignored'           => false,
                    ]
                );

                // Dispatch AI scoring job for this product
                ProcessPendingProduct::dispatch($product->id, $category->id);

                $saved++;
            } catch (\Exception $e) {
                Log::warning('BatchImport: failed to save product', [
                    'asin'  => $p['asin'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info("BatchImport: saved {$saved} products for category {$category->id}");

        return response()->json([
            'success' => true,
            'saved'   => $saved,
            'message' => "Successfully queued {$saved} products for AI processing.",
        ]);
    }

    /**
     * Infer a price tier (1=Budget, 2=Mid, 3=Premium) from a raw price.
     * Returns null if price is unavailable so the field stays unset.
     */
    private function guessPriceTier(?float $price): ?int
    {
        if ($price === null) return null;
        return match (true) {
            $price < 50  => 1,
            $price < 150 => 2,
            default      => 3,
        };
    }
}
