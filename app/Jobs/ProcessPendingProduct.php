<?php

namespace App\Jobs;

use App\Models\AiCategoryRejection;
use App\Models\AiMatchingDecision;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\AiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProcessPendingProduct implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;
    public array $backoff = [10, 60, 300]; // 10s, 1min, 5min

    public function __construct(
        private readonly int $productId,
        private readonly int $categoryId,
    ) {}

    public function handle(): void
    {
        $product  = Product::with('offers.store')->find($this->productId);
        $category = Category::with('features')->find($this->categoryId);

        if (!$product || !$category || $category->features->isEmpty()) {
            Log::warning('ProcessPendingProduct: product or category not found', [
                'product_id'  => $this->productId,
                'category_id' => $this->categoryId,
            ]);
            return;
        }

        // Log a warning if status is not pending_ai (e.g., already processed by a duplicate job),
        // but continue anyway — the database queue prevents duplicate job execution.
        if ($product->status !== 'pending_ai') {
            Log::warning('ProcessPendingProduct: unexpected status, processing anyway', [
                'product_id' => $this->productId,
                'status'     => $product->status,
            ]);
        }

        try {
            $featureMap = $category->features->mapWithKeys(fn($f) => [
                $f->name => ['unit' => $f->unit, 'is_higher_better' => $f->is_higher_better],
            ])->toArray();

            $budgetMax   = $category->budget_max ?? 50;
            $midrangeMax = $category->midrange_max ?? 150;
            $priceNote = match ($product->price_tier) {
                1       => "Budget (under \${$budgetMax})",
                2       => "Mid-range (\${$budgetMax}–\${$midrangeMax})",
                3       => "Premium (over \${$midrangeMax})",
                default => 'unknown price',
            };
            $ratingNote = $product->amazon_rating
                ? "{$product->amazon_rating}/5 stars ({$product->amazon_reviews_count} reviews)"
                : 'no rating data available';

            $aiService = app(AiService::class);
            $result = $aiService->evaluateProduct(
                $product->name, $product->best_price, $priceNote, $ratingNote, $category->name, $featureMap
            );
            $parsed = $result['parsed'];

            // AI identified this as an accessory — suppress it
            if (($parsed['status'] ?? null) === 'ignored') {
                $product->update(['status' => null, 'is_ignored' => true]);
                Log::info('ProcessPendingProduct: marked as ignored', [
                    'product_id' => $product->id,
                    'reason'     => $parsed['reason'] ?? '',
                ]);
                return;
            }

            if (empty($parsed['name']) || empty($parsed['brand'])) {
                throw new \Exception('Invalid AI response: missing name or brand field');
            }

            // Guard: if AI returned just the brand name (e.g. "Breville") instead of a
            // real product name, keep the original scraped title which has more detail.
            $aiName = $parsed['name'];
            $originalName = $product->name;
            if (mb_strlen($aiName) < 20 && mb_strlen($originalName) > mb_strlen($aiName)) {
                $aiName = mb_substr($originalName, 0, 255);
            }

            // AI Memory Matching: check if this product already exists under a different ASIN/offer.
            // Uses cached decisions first, then asks AI only when needed.
            $matchedProductId = $aiService->matchProduct($originalName, $parsed['brand'], $product->tenant_id, $product->id);

            if ($matchedProductId && $matchedProductId !== $product->id) {
                // Merge: transfer this product's offers to the matched product, then delete the duplicate stub.
                // Handle unique constraint (product_id, store_id) — if matched product already has
                // an offer from the same store, keep the cheaper one.
                $existingOfferStores = \App\Models\ProductOffer::where('product_id', $matchedProductId)
                    ->pluck('scraped_price', 'store_id');

                foreach ($product->offers as $offer) {
                    if ($existingOfferStores->has($offer->store_id)) {
                        // Same store already exists on matched product — keep cheaper, delete other
                        if ($offer->scraped_price < $existingOfferStores[$offer->store_id]) {
                            \App\Models\ProductOffer::where('product_id', $matchedProductId)
                                ->where('store_id', $offer->store_id)
                                ->update([
                                    'scraped_price' => $offer->scraped_price,
                                    'url'           => $offer->url,
                                    'raw_title'     => $offer->raw_title,
                                    'image_url'     => $offer->image_url,
                                ]);
                        }
                        $offer->delete();
                    } else {
                        $offer->update(['product_id' => $matchedProductId]);
                    }
                }

                $product->forceDelete();

                Log::info('ProcessPendingProduct: merged duplicate into existing product', [
                    'duplicate_id' => $product->id,
                    'matched_id'   => $matchedProductId,
                    'raw_title'    => $originalName,
                ]);
                return;
            }

            // Category rejection check: if this product was previously swept out
            // of this category, detach it and leave category_id null for future re-assignment.
            $rejected = AiCategoryRejection::where('product_id', $product->id)
                ->where('category_id', $this->categoryId)
                ->exists();

            if ($rejected) {
                $product->update(['category_id' => null, 'status' => null]);
                Log::info('ProcessPendingProduct: skipped — product was rejected from this category', [
                    'product_id'  => $product->id,
                    'category_id' => $this->categoryId,
                ]);
                return;
            }

            $brand = Brand::firstOrCreate(
                ['name' => $parsed['brand'], 'tenant_id' => $product->tenant_id],
                ['name' => $parsed['brand'], 'tenant_id' => $product->tenant_id]
            );

            $product->update([
                'name'                 => $aiName,
                'slug'                 => Str::slug($aiName . '-' . Str::random(5)),
                'brand_id'             => $brand->id,
                'ai_summary'           => $parsed['ai_summary'] ?? null,
                'price_tier'           => $parsed['price_tier']           ?? $product->price_tier,
                'amazon_rating'        => $parsed['amazon_rating']        ?? $product->amazon_rating,
                'amazon_reviews_count' => $parsed['amazon_reviews_count'] ?? $product->amazon_reviews_count,
                'status'               => null, // fully processed
            ]);

            // Invalidate stale negative matching decisions so future imports re-evaluate
            AiMatchingDecision::withoutGlobalScopes()
                ->where('tenant_id', $product->tenant_id)
                ->where('is_match', false)
                ->delete();

            foreach ($category->features as $feature) {
                $value = $parsed['features'][$feature->name] ?? null;
                if ($value === null) continue;

                $score  = is_array($value) ? (float) ($value['score'] ?? 0) : (float) $value;
                $reason = is_array($value) ? ($value['reason'] ?? null)      : null;

                if ($score > 0) {
                    $product->featureValues()->updateOrCreate(
                        ['feature_id' => $feature->id],
                        ['raw_value' => $score, 'explanation' => $reason]
                    );
                }
            }

            // Download and store the product image locally (non-fatal — wrapped in its own try/catch)
            $this->downloadAndStoreImage($product, $parsed['brand'], $parsed['name']);

            Log::info('ProcessPendingProduct: completed', [
                'product_id' => $product->id,
                'name'       => $product->name,
            ]);
        } catch (\Exception $e) {
            Log::error('ProcessPendingProduct: failed', [
                'product_id' => $this->productId,
                'error'      => $e->getMessage(),
            ]);

            if ($this->attempts() >= $this->tries) {
                $product->update(['status' => 'failed']);
            } else {
                throw $e; // Trigger queue retry with backoff
            }
        }
    }

    /**
     * Download the product image from Amazon and store it locally.
     * Failures are logged but never propagate — a missing image must not abort the AI job.
     *
     * Filename format: {brand-slug-max-4-words}-{asin}.{ext}
     * Example: razer-seiren-mini-usb-B0D3MB36XV.jpg
     */
    private function downloadAndStoreImage(Product $product, string $brandName, string $productName): void
    {
        $amazonOffer = $product->offers->first(fn ($o) => $o->store?->slug === 'amazon') ?? $product->offers->first();
        $imageUrl = $amazonOffer?->image_url;

        if (empty($imageUrl)) {
            return;
        }

        try {
            // SSRF protection: allow known CDN domains + any domain from active stores
            $host = parse_url($imageUrl, PHP_URL_HOST);
            $allowedHosts = config('services.allowed_image_hosts', []);

            // Auto-allow: if the image host matches any store's domain (or its CDN)
            if (!in_array($host, $allowedHosts)) {
                $storeMatch = \App\Models\Store::withoutGlobalScopes()
                    ->where('is_active', true)
                    ->get(['slug'])
                    ->contains(fn ($s) => str_contains($host, str_replace('-', '', $s->slug)));

                if (!$storeMatch && !str_ends_with($host, '.shopify.com') && !str_ends_with($host, '.cloudfront.net')) {
                    Log::warning('ProcessPendingProduct: image host not allowed', ['host' => $host]);
                    return;
                }
            }

            $response = Http::timeout(15)->get($imageUrl);

            if (!$response->successful()) {
                Log::warning('ProcessPendingProduct: image download failed', ['status' => $response->status(), 'url' => $imageUrl]);
                return;
            }

            $contentType = $response->header('Content-Type');
            if (!str_starts_with($contentType, 'image/')) {
                Log::warning('ProcessPendingProduct: URL did not return an image', ['content_type' => $contentType]);
                return;
            }

            $extension = match (true) {
                str_contains($contentType, 'png')  => 'png',
                str_contains($contentType, 'webp') => 'webp',
                default                            => 'jpg',
            };

            // Build a short, meaningful filename from brand + first words of product name
            $allWords  = array_filter(explode(' ', "{$brandName} {$productName}"));
            $slugWords = array_slice($allWords, 0, 4);
            $stem      = Str::slug(implode(' ', $slugWords));
            $asin      = $amazonOffer ? basename(parse_url($amazonOffer->url, PHP_URL_PATH)) : Str::random(10);
            $filename  = "{$stem}-{$asin}.{$extension}";
            $path      = "products/images/{$filename}";

            Storage::disk('public')->put($path, $response->body());

            // Optimize: convert to WebP, resize to 800px max width
            $absolutePath = Storage::disk('public')->path($path);
            $webpPath = \App\Services\ImageOptimizer::toWebp($absolutePath);
            $path = str_replace(Storage::disk('public')->path(''), '', $webpPath);

            $product->update(['image_path' => $path]);

            Log::info('ProcessPendingProduct: image stored', ['path' => $path]);
        } catch (\Exception $e) {
            Log::warning('ProcessPendingProduct: image download skipped', [
                'product_id' => $product->id,
                'url'        => $imageUrl,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
