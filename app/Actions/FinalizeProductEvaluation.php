<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\FinalizeOutcome;
use App\Models\AiCategoryRejection;
use App\Models\AiMatchingDecision;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Store;
use App\Services\AiService;
use App\Services\ImageOptimizer;
use App\Support\ProductEvaluation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Spec 039 T2 — the single finalize path for BOTH evaluation producers: the
 * Gemini Bouncer ({@see \App\Jobs\ProcessPendingProduct}) and, from Spec 039
 * T4 onward, the operator-session overflow path (`pw2d:products:apply-
 * evaluations`, not yet built). One evaluation schema
 * ({@see \App\Support\ProductEvaluation}), one finalize path: this class.
 *
 * Moved verbatim (behaviour-identical extraction) from
 * `ProcessPendingProduct::handle()` — including `capProductName()` and
 * `downloadAndStoreImage()`, which the job no longer keeps private copies of.
 * Two intentional differences from the pre-extraction behaviour:
 *
 * 1. A `wrong_category` reason (only possible when `$eval->isIgnored()` is
 *    true — see {@see ProductEvaluation}'s docblock) is routed to
 *    {@see self::rejectFromCategory()} instead of the ordinary ignore-flag
 *    branch: it creates an {@see AiCategoryRejection} for (product, category)
 *    if one doesn't already exist, detaches the product (`category_id` +
 *    `status` both null), and leaves `is_ignored` untouched — mirroring what
 *    `AiSweepCategory` already does for the same detach outcome.
 * 2. The feature-score loop is {@see self::applyFeatureScores()}, a public
 *    static method also called by {@see \App\Jobs\RescanProductFeatures} —
 *    one implementation instead of two copies (todo L2).
 *
 * Every write below that mutates a `Product` uses a model-level `update()`/
 * `save()`, never a mass `Builder::update()` — required so
 * `ProductObserver::saved()` fires (Spec 035: a mass update silently skips
 * Eloquent events, which is how stale feature values and missed freshness
 * audits reached production on 2026-08-21).
 */
class FinalizeProductEvaluation
{
    public function __construct(private readonly AiService $aiService) {}

    /**
     * @param string $source Producer identifier ('gemini' from
     *   `ProcessPendingProduct`; `claude-code-session` (or similar) from the
     *   Spec 039 T4 apply command). Not used for any branching decision here
     *   — the caller also uses it for its own `ai_usage` recording (T4 step
     *   5) — but IS threaded into this action's own log context below so the
     *   `ProcessPendingProduct: …` prefix (kept for Gemini-path log
     *   continuity, see class docblock) doesn't misattribute a T4-applied row
     *   as a Gemini one.
     */
    public function execute(Product $product, Category $category, ProductEvaluation $eval, string $source): FinalizeOutcome
    {
        if ($eval->isIgnored()) {
            if ($eval->reason() === 'wrong_category') {
                return $this->rejectFromCategory($product, $category);
            }

            $product->update(['status' => null, 'is_ignored' => true]);
            Log::info('ProcessPendingProduct: marked as ignored', [
                'product_id' => $product->id,
                'reason'     => $eval->reason() ?? '',
                'source'     => $source,
            ]);
            return FinalizeOutcome::Ignored;
        }

        // Guard: if AI returned just the brand name (e.g. "Breville") instead of a
        // real product name, keep the original scraped title which has more detail.
        $aiName       = $eval->name();
        $originalName = $product->name;
        if (mb_strlen($aiName) < 20 && mb_strlen($originalName) > mb_strlen($aiName)) {
            $aiName = mb_substr($originalName, 0, 255);
        }

        // Defensive cap: regardless of source (AI-returned name or the raw-title
        // fallback above), never let a verbose marketing title become the stored
        // name/slug. Keeps name and slug in agreement.
        $aiName = self::capProductName($aiName);

        // AI Memory Matching: check if this product already exists under a different ASIN/offer.
        // Uses cached decisions first, then asks AI only when needed.
        $matchedProductId = $this->aiService->matchProduct($originalName, $eval->brand(), $product->tenant_id, $product->id);

        if ($matchedProductId && $matchedProductId !== $product->id) {
            // Merge: transfer this product's offers to the matched product, then delete the duplicate stub.
            // Handle unique constraint (product_id, store_id) — if matched product already has
            // an offer from the same store, keep the cheaper one.
            $existingOfferStores = ProductOffer::where('product_id', $matchedProductId)
                ->pluck('scraped_price', 'store_id');

            foreach ($product->offers as $offer) {
                if ($existingOfferStores->has($offer->store_id)) {
                    // Same store already exists on matched product — keep cheaper, delete other
                    if ($offer->scraped_price < $existingOfferStores[$offer->store_id]) {
                        ProductOffer::where('product_id', $matchedProductId)
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
                'source'       => $source,
            ]);
            return FinalizeOutcome::Merged;
        }

        // Category rejection check: if this product was previously swept out
        // of this category, detach it and leave category_id null for future re-assignment.
        $rejected = AiCategoryRejection::where('product_id', $product->id)
            ->where('category_id', $category->id)
            ->exists();

        if ($rejected) {
            $product->update(['category_id' => null, 'status' => null]);
            Log::info('ProcessPendingProduct: skipped — product was rejected from this category', [
                'product_id'  => $product->id,
                'category_id' => $category->id,
            ]);
            return FinalizeOutcome::RejectedFromCategory;
        }

        // Reuse existing brand if one matches (fuzzy: ignore apostrophes/case/accents).
        // Prevents brand record splits when the AI returns "DeLonghi" vs "De'Longhi".
        $normalizedIncoming = AiService::normalizeBrandForComparison($eval->brand());

        $brand = Brand::withoutGlobalScopes()
            ->where('tenant_id', $product->tenant_id)
            ->get(['id', 'name'])
            ->first(fn ($b) => AiService::normalizeBrandForComparison($b->name) === $normalizedIncoming)
            ?? Brand::create([
                'name'      => $eval->brand(),
                'tenant_id' => $product->tenant_id,
            ]);

        $product->update([
            'name'                 => $aiName,
            'slug'                 => Str::slug($aiName . '-' . Str::random(5)),
            'brand_id'             => $brand->id,
            'ai_summary'           => $eval->aiSummary(),
            'price_tier'           => $eval->priceTier()          ?? $product->price_tier,
            'amazon_rating'        => $eval->amazonRating()       ?? $product->amazon_rating,
            'amazon_reviews_count' => $eval->amazonReviewsCount() ?? $product->amazon_reviews_count,
            'status'               => null, // fully processed
        ]);

        // Invalidate stale negative matching decisions for ALL spelling variants of this brand
        // so future imports re-evaluate. Covers "De'Longhi", "DeLonghi", "de longhi", etc.
        $normalizedBrand = AiService::normalizeBrandForComparison($eval->brand());

        $brandVariants = Brand::withoutGlobalScopes()
            ->where('tenant_id', $product->tenant_id)
            ->get(['name'])
            ->filter(fn ($b) => AiService::normalizeBrandForComparison($b->name) === $normalizedBrand)
            ->pluck('name');

        if ($brandVariants->isNotEmpty()) {
            AiMatchingDecision::withoutGlobalScopes()
                ->where('tenant_id', $product->tenant_id)
                ->where('is_match', false)
                ->where(function ($q) use ($brandVariants) {
                    foreach ($brandVariants as $variant) {
                        $q->orWhere('scraped_raw_name', 'LIKE', '%' . str_replace(['%', '_'], ['\%', '\_'], $variant) . '%');
                    }
                })
                ->delete();
        }

        self::applyFeatureScores($product, $category, $eval->features());

        // Download and store the product image locally (non-fatal — wrapped in its own try/catch)
        self::downloadAndStoreImage($product, $eval->brand(), $eval->name());

        Log::info('ProcessPendingProduct: completed', [
            'product_id' => $product->id,
            'name'       => $product->name,
            'source'     => $source,
        ]);

        return FinalizeOutcome::Scored;
    }

    /**
     * Spec 039 §2 T1/T2 — a `wrong_category` verdict maps to what
     * `AiSweepCategory` already does for the same detach outcome: create the
     * rejection row (idempotent via `firstOrCreate`, in case this evaluation
     * is re-applied), null out `category_id` and `status`, and leave
     * `is_ignored` untouched — the product may still belong somewhere else.
     */
    private function rejectFromCategory(Product $product, Category $category): FinalizeOutcome
    {
        AiCategoryRejection::firstOrCreate(
            ['product_id' => $product->id, 'category_id' => $category->id],
            ['rejection_reason' => 'wrong_category']
        );

        $product->update(['category_id' => null, 'status' => null]);

        Log::info('ProcessPendingProduct: rejected from category (wrong_category)', [
            'product_id'  => $product->id,
            'category_id' => $category->id,
        ]);

        return FinalizeOutcome::RejectedFromCategory;
    }

    /**
     * Writes a `ProductFeatureValue` for every category feature the evaluation
     * scored (`score > 0`), skipping anything absent. Shared by
     * `ProcessPendingProduct` (via `execute()` above, fed `$eval->features()`)
     * and `RescanProductFeatures` (fed the raw parsed AI response directly —
     * that path never builds a `ProductEvaluation`, so entries may still be a
     * bare numeric score rather than the `{score, reason}` shape; both are
     * handled here exactly as the original duplicated loop did).
     *
     * @param array<string, mixed> $features
     * @return int Number of feature values written.
     */
    public static function applyFeatureScores(Product $product, Category $category, array $features): int
    {
        $written = 0;

        foreach ($category->features as $feature) {
            $value = $features[$feature->name] ?? null;
            if ($value === null) continue;

            $score  = is_array($value) ? (float) ($value['score'] ?? 0) : (float) $value;
            $reason = is_array($value) ? ($value['reason'] ?? null)     : null;

            if ($score > 0) {
                $product->featureValues()->updateOrCreate(
                    ['feature_id' => $feature->id],
                    ['raw_value' => $score, 'explanation' => $reason]
                );
                $written++;
            }
        }

        return $written;
    }

    /**
     * Cap an AI/scraped product name to a concise product identity, used for both
     * the stored `name` and the slug stem so they always agree.
     *
     * Truncates at the first comma or opening parenthesis (these typically start a
     * spec/compatibility/bundle list in verbose marketing titles), falls back to the
     * first 8 words, and strips a trailing stopword left dangling by truncation.
     */
    private static function capProductName(string $name): string
    {
        $name = trim($name);

        $cutPos = null;
        foreach ([',', '('] as $delimiter) {
            $pos = mb_strpos($name, $delimiter);
            if ($pos !== false && $pos > 0 && ($cutPos === null || $pos < $cutPos)) {
                $cutPos = $pos;
            }
        }
        if ($cutPos !== null) {
            $name = mb_substr($name, 0, $cutPos);
        }

        $words = preg_split('/\s+/', trim($name)) ?: [];
        if (count($words) > 8) {
            $words = array_slice($words, 0, 8);
        }

        $stopwords = ['with', 'for', 'and', 'the', 'of', 'in'];
        while (!empty($words) && in_array(mb_strtolower(end($words)), $stopwords, true)) {
            array_pop($words);
        }

        return trim(implode(' ', $words));
    }

    /**
     * Download the product image from Amazon and store it locally.
     * Failures are logged but never propagate — a missing image must not abort the AI job.
     *
     * Filename format: {brand-slug-max-4-words}-{asin}.{ext}
     * Example: razer-seiren-mini-usb-B0D3MB36XV.jpg
     */
    private static function downloadAndStoreImage(Product $product, string $brandName, string $productName): void
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
                $storeMatch = Store::withoutGlobalScopes()
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
            $webpPath = ImageOptimizer::toWebp($absolutePath);
            $path = str_replace(Storage::disk('public')->path(''), '', $webpPath);

            $product->update(['image_path' => $path]);

            Log::info('ProcessPendingProduct: image stored', ['path' => $path]);
        } catch (\Throwable $e) {
            // \Throwable, not \Exception (review LOW-4): parse_url()'s
            // ?string|false / ?string returns feeding str_contains()/
            // str_ends_with()/basename() below are TypeErrors under
            // declare(strict_types=1), which \Exception does not catch. This
            // helper is documented non-fatal — it must never let a TypeError
            // escape and cause the already-fully-written product above to
            // retry the whole (paid) evaluation over again.
            Log::warning('ProcessPendingProduct: image download skipped', [
                'product_id' => $product->id,
                'url'        => $imageUrl,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
