<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductImportRequest;
use App\Jobs\ProcessPendingProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductImportController extends Controller
{
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

        return response()->json(['success' => true, 'categories' => $categories]);
    }

    /**
     * Get list of existing ASINs to prevent duplicate scraping.
     * Now reads from product_offers instead of products.external_id.
     */
    public function existingAsins(Request $request): JsonResponse
    {
        $amazonStore = Store::where('slug', 'amazon')->first();
        if (!$amazonStore) {
            return response()->json(['success' => true, 'asins' => []]);
        }
        $query = ProductOffer::where('store_id', $amazonStore->id);

        if ($request->has('category_id')) {
            $query->whereHas('product', fn ($q) => $q->where('category_id', $request->category_id));
        }

        // Extract ASIN from the Amazon URL (last path segment of /dp/{ASIN})
        $asins = $query->pluck('url')->map(fn ($url) => basename(parse_url($url, PHP_URL_PATH)));

        return response()->json(['success' => true, 'asins' => $asins]);
    }

    /**
     * Import a single product: create a stub and queue AI processing.
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

        $asin = $validated['external_id'];
        $amazonUrl = "https://www.amazon.com/dp/{$asin}";

        $store = Store::firstOrCreate(
            ['slug' => 'amazon', 'tenant_id' => $category->tenant_id],
            ['name' => 'Amazon']
        );

        $existingOffer = ProductOffer::where('store_id', $store->id)
            ->where('url', $amazonUrl)
            ->whereHas('product', fn ($q) => $q->where('category_id', $category->id))
            ->first();

        if ($existingOffer) {
            $product = $existingOffer->product;

            // Update offer
            $existingOffer->update([
                'scraped_price' => $validated['price'] ?? null,
                'raw_title'     => mb_substr($validated['title'], 0, 500),
            ]);

            // Update product
            $product->update([
                'name'                 => mb_substr($validated['title'], 0, 255),
                'slug'                 => Str::slug(Str::limit($validated['title'], 80)) . '-' . strtolower($asin),
                'amazon_rating'        => $validated['rating'] ?? null,
                'amazon_reviews_count' => $validated['reviews_count'] ?? 0,
                'price_tier'           => $category->priceTierFor($validated['price'] ?? null),
                'status'               => 'pending_ai',
                'is_ignored'           => false,
            ]);

            $wasNew = false;
        } else {
            // Create new product
            $product = Product::create([
                'tenant_id'            => $category->tenant_id,
                'category_id'          => $category->id,
                'name'                 => mb_substr($validated['title'], 0, 255),
                'slug'                 => Str::slug(Str::limit($validated['title'], 80)) . '-' . strtolower($asin),
                'amazon_rating'        => $validated['rating'] ?? null,
                'amazon_reviews_count' => $validated['reviews_count'] ?? 0,
                'price_tier'           => $category->priceTierFor($validated['price'] ?? null),
                'status'               => 'pending_ai',
                'is_ignored'           => false,
            ]);

            // Create Amazon offer
            ProductOffer::create([
                'tenant_id'     => $category->tenant_id,
                'product_id'    => $product->id,
                'store_id'      => $store->id,
                'url'           => $amazonUrl,
                'scraped_price' => $validated['price'] ?? null,
                'raw_title'     => mb_substr($validated['title'], 0, 500),
            ]);

            $wasNew = true;
        }

        ProcessPendingProduct::dispatch($product->id, $category->id);

        return response()->json([
            'success' => true,
            'action'  => $wasNew ? 'queued_new' : 'queued_rescan',
            'product' => ['id' => $product->id],
        ]);
    }
}
