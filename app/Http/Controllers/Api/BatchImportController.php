<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPendingProduct;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'products.*.title'           => 'required|string|min:3|max:500',
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

        $incomingAsins = collect($validated['products'])->pluck('asin');

        // One query to find all already-imported ASINs — avoids 1 SELECT per product.
        $existingMap = Product::where('category_id', $category->id)
            ->whereIn('external_id', $incomingAsins)
            ->get(['id', 'external_id'])
            ->keyBy('external_id');

        $created     = 0;
        $refreshed   = 0;
        $refreshRows = [];
        $now         = now();

        foreach ($validated['products'] as $p) {
            try {
                $existing = $existingMap->get($p['asin']);

                if ($existing) {
                    // Scenario B: Existing product — collect for a single batch UPDATE below.
                    $refreshRows[] = [
                        'id'                   => $existing->id,
                        'scraped_price'        => $p['price'] ?? null,
                        'amazon_rating'        => $p['rating'] ?? null,
                        'amazon_reviews_count' => $p['reviews_count'] ?? 0,
                        'updated_at'           => $now,
                    ];
                    $refreshed++;
                } else {
                    // Scenario A: New product — create stub and queue for AI scoring.
                    $product = Product::create([
                        'external_id'          => $p['asin'],
                        'category_id'          => $category->id,
                        'name'                 => mb_substr($p['title'], 0, 255),
                        'slug'                 => Str::slug(Str::limit($p['title'], 80)) . '-' . strtolower($p['asin']),
                        'external_image_path'  => $p['image_url'] ?? null,
                        'amazon_rating'        => $p['rating'] ?? null,
                        'amazon_reviews_count' => $p['reviews_count'] ?? 0,
                        'scraped_price'        => $p['price'] ?? null,
                        'price_tier'           => $category->priceTierFor($p['price'] ?? null),
                        'status'               => 'pending_ai',
                        'is_ignored'           => false,
                    ]);

                    ProcessPendingProduct::dispatch($product->id, $category->id);
                    $created++;
                }
            } catch (\Exception $e) {
                Log::warning('BatchImport: failed to process product', [
                    'asin'  => $p['asin'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Update refreshed products. We use individual UPDATE statements because we already
        // confirmed these IDs exist (from $existingMap), so upsert's INSERT fallback is wrong.
        foreach ($refreshRows as $row) {
            DB::table('products')
                ->where('id', $row['id'])
                ->update([
                    'scraped_price'        => $row['scraped_price'],
                    'amazon_rating'        => $row['amazon_rating'],
                    'amazon_reviews_count' => $row['amazon_reviews_count'],
                    'updated_at'           => $row['updated_at'],
                ]);
        }

        Log::info("BatchImport: {$created} created, {$refreshed} refreshed for category {$category->id}");

        return response()->json([
            'success'   => true,
            'created'   => $created,
            'refreshed' => $refreshed,
            'message'   => "Queued {$created} new product(s) for AI processing. Refreshed data for {$refreshed} existing product(s).",
        ]);
    }

}
