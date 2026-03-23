<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductImportRequest;
use App\Jobs\ProcessPendingProduct;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductImportController extends Controller
{
    /**
     * Get all categories with their feature counts.
     */
    public function categories(): JsonResponse
    {
        $categories = Category::withCount('features')
            ->orderBy('name')
            ->get()
            ->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'features_count' => $category->features_count,
            ]);

        return response()->json([
            'success' => true,
            'categories' => $categories,
        ]);
    }

    /**
     * Get list of all existing external IDs (ASINs) to prevent duplicate scraping.
     */
    public function existingAsins(Request $request): JsonResponse
    {
        $query = Product::whereNotNull('external_id');

        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        $asins = $query->pluck('external_id');

        return response()->json([
            'success' => true,
            'asins' => $asins,
        ]);
    }

    /**
     * Import a single product: create a stub and queue AI processing.
     *
     * This endpoint creates the product record with status=pending_ai and
     * dispatches ProcessPendingProduct to handle Gemini scoring, brand
     * normalization, and image download asynchronously.
     */
    public function import(ProductImportRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $category = Category::with('features')->findOrFail($validated['category_id']);

        if ($category->features->isEmpty()) {
            return response()->json([
                'success' => false,
                'error'   => 'No Features',
                'message' => 'The selected category has no features defined.',
            ], 400);
        }

        $product = Product::updateOrCreate(
            ['external_id' => $validated['external_id'], 'category_id' => $category->id],
            [
                'tenant_id'            => $category->tenant_id,
                'name'                 => mb_substr($validated['title'], 0, 255),
                'slug'                 => Str::slug(Str::limit($validated['title'], 80)) . '-' . strtolower($validated['external_id']),
                'external_image_path'  => $validated['image_url'] ?? null,
                'amazon_rating'        => $validated['rating'] ?? null,
                'amazon_reviews_count' => $validated['reviews_count'] ?? 0,
                'scraped_price'        => $validated['price'] ?? null,
                'price_tier'           => $category->priceTierFor($validated['price'] ?? null),
                'status'               => 'pending_ai',
                'is_ignored'           => false,
            ]
        );

        ProcessPendingProduct::dispatch($product->id, $category->id);

        return response()->json([
            'success' => true,
            'action'  => $product->wasRecentlyCreated ? 'queued_new' : 'queued_rescan',
            'product' => [
                'id'          => $product->id,
                'external_id' => $product->external_id,
            ],
        ]);
    }
}
