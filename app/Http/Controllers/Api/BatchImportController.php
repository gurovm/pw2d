<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BatchImportRequest;
use App\Jobs\ProcessPendingProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BatchImportController extends Controller
{
    public function import(BatchImportRequest $request)
    {
        $validated = $request->validated();

        $category = Category::with('features')->findOrFail($validated['category_id']);

        if ($category->features->isEmpty()) {
            return response()->json([
                'success' => false,
                'error'   => 'No Features',
                'message' => 'The selected category has no features defined. Add features before importing.',
            ], 400);
        }

        // Resolve the Amazon store (create if first import for this tenant)
        $store = Store::firstOrCreate(
            ['slug' => 'amazon', 'tenant_id' => $category->tenant_id],
            ['name' => 'Amazon']
        );

        $incomingAsins = collect($validated['products'])->pluck('asin');

        // Find existing products by matching Amazon offers
        $existingProducts = Product::where('category_id', $category->id)
            ->whereHas('offers', fn ($q) => $q->where('store_id', $store->id)
                ->whereIn(DB::raw("SUBSTRING_INDEX(SUBSTRING_INDEX(url, '/dp/', -1), '?', 1)"), $incomingAsins))
            ->with(['offers' => fn ($q) => $q->where('store_id', $store->id)])
            ->get();

        $existingMap = collect();
        foreach ($existingProducts as $product) {
            foreach ($product->offers as $offer) {
                $asin = basename(parse_url($offer->url, PHP_URL_PATH));
                $existingMap[$asin] = $product;
            }
        }

        $created   = 0;
        $refreshed = 0;
        $now       = now();

        foreach ($validated['products'] as $p) {
            try {
                $existing = $existingMap->get($p['asin']);

                if ($existing) {
                    if (empty($p['price'])) {
                        $existing->update(['is_ignored' => true]);
                        $refreshed++;
                        continue;
                    }

                    ProductOffer::where('product_id', $existing->id)
                        ->where('store_id', $store->id)
                        ->update([
                            'scraped_price' => $p['price'],
                            'raw_title'     => mb_substr($p['title'], 0, 500),
                            'updated_at'    => $now,
                        ]);

                    $existing->update([
                        'amazon_rating'        => $p['rating'] ?? null,
                        'amazon_reviews_count' => $p['reviews_count'] ?? 0,
                        'price_tier'           => $category->priceTierFor($p['price']),
                    ]);

                    $refreshed++;
                } else {
                    $price = $p['price'] ?? null;
                    if ($price !== null && $price > 0) {
                        $budgetMax = $category->budget_max ?? 50;
                        if ($price < $budgetMax * 0.5) {
                            continue;
                        }
                    }

                    $product = Product::create([
                        'tenant_id'            => $category->tenant_id,
                        'category_id'          => $category->id,
                        'name'                 => mb_substr($p['title'], 0, 255),
                        'slug'                 => Str::slug(Str::limit($p['title'], 80)) . '-' . strtolower($p['asin']),
                        'amazon_rating'        => $p['rating'] ?? null,
                        'amazon_reviews_count' => $p['reviews_count'] ?? 0,
                        'price_tier'           => $category->priceTierFor($p['price'] ?? null),
                        'status'               => 'pending_ai',
                        'is_ignored'           => false,
                    ]);

                    ProductOffer::create([
                        'tenant_id'     => $category->tenant_id,
                        'product_id'    => $product->id,
                        'store_id'      => $store->id,
                        'url'           => "https://www.amazon.com/dp/{$p['asin']}",
                        'scraped_price' => $p['price'] ?? null,
                        'raw_title'     => mb_substr($p['title'], 0, 500),
                        'image_url'     => $p['image_url'] ?? null,
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

        Log::info("BatchImport: {$created} created, {$refreshed} refreshed for category {$category->id}");

        return response()->json([
            'success'   => true,
            'created'   => $created,
            'refreshed' => $refreshed,
            'message'   => "Queued {$created} new product(s) for AI processing. Refreshed data for {$refreshed} existing product(s).",
        ]);
    }
}
