<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ProcessPendingProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Store;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Processes incoming product offers from any store (extension-first pipeline).
 *
 * Flow:
 * 1. Resolve store from slug
 * 2. Check if offer already exists for this URL → update price if so
 * 3. Ask AiService::matchProduct() to find an existing canonical product
 * 4. If match → attach offer to existing product
 * 5. If no match → create product stub + offer, dispatch AI evaluation
 */
class OfferIngestionService
{
    public function __construct(private AiService $aiService) {}

    /**
     * @param array{
     *   url: string,
     *   store_slug: string,
     *   raw_title: string,
     *   brand: string,
     *   scraped_price: ?float,
     *   image_url: ?string,
     *   category_id: int,
     *   rating: ?float,
     *   reviews_count: ?int,
     * } $data
     * @return array{action: string, product_id: int}
     */
    public function processIncomingOffer(array $data): array
    {
        $tenantId = tenant('id');

        // 1. Resolve store
        $store = Store::firstOrCreate(
            ['slug' => $data['store_slug'], 'tenant_id' => $tenantId],
            ['name' => Str::title(str_replace('-', ' ', $data['store_slug']))]
        );

        // 2. Check if this exact offer URL already exists → price refresh
        $existingOffer = ProductOffer::where('store_id', $store->id)
            ->where('url', $data['url'])
            ->first();

        if ($existingOffer) {
            $existingOffer->update([
                'scraped_price' => $data['scraped_price'],
                'raw_title'     => mb_substr($data['raw_title'], 0, 500),
                'image_url'     => $data['image_url'] ?? $existingOffer->image_url,
            ]);

            Log::info('OfferIngestion: refreshed existing offer', [
                'offer_id' => $existingOffer->id,
                'store'    => $store->name,
            ]);

            return ['action' => 'refreshed', 'product_id' => $existingOffer->product_id];
        }

        // 3. AI Memory Matching — does this title match an existing product?
        // Wrapped in try/catch: if AI fails, treat as new product (queue will handle it)
        $matchedProductId = null;
        if (!empty($data['brand'])) {
            try {
                $matchedProductId = $this->aiService->matchProduct(
                    $data['raw_title'],
                    $data['brand'],
                    $tenantId
                );
            } catch (\Throwable $e) {
                Log::warning('OfferIngestion: AI matching failed, treating as new product', [
                    'raw_title' => $data['raw_title'],
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        // Heuristic fallback: exact normalized title match in same category
        if (!$matchedProductId) {
            $heuristic = Product::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('category_id', $data['category_id'])
                ->whereNull('status')
                ->where('is_ignored', false)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($data['raw_title'])])
                ->first();

            if ($heuristic) {
                $matchedProductId = $heuristic->id;
            }
        }

        // Verify matched product still exists (cache may reference a deleted product)
        if ($matchedProductId && !Product::withoutGlobalScopes()->where('id', $matchedProductId)->exists()) {
            $matchedProductId = null;
        }

        if ($matchedProductId) {
            // 4. Match found → attach offer to existing product
            ProductOffer::create([
                'tenant_id'     => $tenantId,
                'product_id'    => $matchedProductId,
                'store_id'      => $store->id,
                'url'           => $data['url'],
                'scraped_price' => $data['scraped_price'],
                'raw_title'     => mb_substr($data['raw_title'], 0, 500),
                'image_url'     => $data['image_url'] ?? null,
            ]);

            // Recalculate price tier with new offer
            $product = Product::with('offers')->find($matchedProductId);
            if ($product?->category) {
                $product->update(['price_tier' => $product->category->priceTierFor((float) $product->best_price)]);
            }

            Log::info('OfferIngestion: matched to existing product', [
                'product_id' => $matchedProductId,
                'store'      => $store->name,
                'raw_title'  => $data['raw_title'],
            ]);

            return ['action' => 'matched', 'product_id' => $matchedProductId];
        }

        // 5. No match → create new product stub + offer, queue AI evaluation
        $category = Category::with('features')->findOrFail($data['category_id']);

        $product = Product::create([
            'tenant_id'            => $tenantId,
            'category_id'          => $category->id,
            'name'                 => mb_substr($data['raw_title'], 0, 255),
            'slug'                 => Str::slug(Str::limit($data['raw_title'], 80)) . '-' . Str::random(5),
            'amazon_rating'        => $data['rating'] ?? null,
            'amazon_reviews_count' => $data['reviews_count'] ?? 0,
            'price_tier'           => $category->priceTierFor($data['scraped_price']),
            'status'               => 'pending_ai',
            'is_ignored'           => false,
        ]);

        ProductOffer::create([
            'tenant_id'     => $tenantId,
            'product_id'    => $product->id,
            'store_id'      => $store->id,
            'url'           => $data['url'],
            'scraped_price' => $data['scraped_price'],
            'raw_title'     => mb_substr($data['raw_title'], 0, 500),
            'image_url'     => $data['image_url'] ?? null,
        ]);

        if ($category->features->isNotEmpty()) {
            ProcessPendingProduct::dispatch($product->id, $category->id);
        }

        Log::info('OfferIngestion: created new product', [
            'product_id' => $product->id,
            'store'      => $store->name,
        ]);

        return ['action' => 'created', 'product_id' => $product->id];
    }
}
