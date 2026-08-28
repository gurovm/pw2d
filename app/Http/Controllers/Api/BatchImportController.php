<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BatchImportRequest;
use App\Jobs\ProcessPendingProduct;
use App\Jobs\RecalculateCategoryPriceTiers;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Store;
use App\Services\ListingHealthService;
use App\Support\ListingHealth;
use App\Support\ProductConditionGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BatchImportController extends Controller
{
    public function __construct(private readonly ListingHealthService $listingHealth) {}

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

        $created      = 0;
        $refreshed    = 0;
        $skipped      = 0;
        $flagged      = 0;
        $priceUpdated = false;

        foreach ($validated['products'] as $p) {
            try {
                $condition    = $p['condition'] ?? null;
                $listingFlags = $p['listing_flags'] ?? [];

                $existing = $existingMap->get($p['asin']);

                if ($existing) {
                    // Reviewer B2: a title marker is condition EVIDENCE for a listing
                    // we already track — a rescan of an existing offer must heal (raw_
                    // title + flag), never be silently skipped like a brand-new listing
                    // would be (see the guard in the `else` branch below).
                    // 029B-B4: canonical ListingHealth vocabulary, never a raw marker
                    // string. Computed BEFORE the dead-listing heuristic below so that
                    // heuristic can tell a genuinely dead listing apart from a
                    // condition-flagged one.
                    // Fix 1 (2026-08-15): resolveEffectiveCondition() — a negative title
                    // marker beats an explicit payload `'new'`; an explicit negative
                    // payload condition and `'unknown'` are never overridden.
                    $effectiveCondition = ProductConditionGuard::resolveEffectiveCondition($condition, $p['title']);

                    // 2026-08-16 live incident (product 3813, 1Zpresso K-Ultra): a
                    // missing SERP-tile price is NOT authoritative evidence of
                    // delisting — it's routine (sponsored placements, "see price in
                    // cart", variant-priced parents, plain extraction misses). The old
                    // "dead-listing heuristic" silently set is_ignored=true here on a
                    // healthy, $259, condition-new, that-morning-health-verified
                    // product on nothing but that absence, while leaving its offer
                    // completely untouched — the asymmetry (product ignored, offer
                    // clean) was the diagnostic signature. Spec 029's product-page
                    // detection (condition/listing_flags/stock_status, ownership below
                    // via ListingHealthService::apply() + ListingHealth::isPurchasable())
                    // is a strictly stronger, DOM-verified signal and already owns
                    // availability at the offer level — a SERP tile must never override
                    // it. So: when there's no price AND no condition/flag evidence at
                    // all, leave the product AND its offer completely untouched and just
                    // log it, so a pattern (e.g. one ASIN/category consistently missing
                    // its price on the SERP) is visible without ever writing anything.
                    if (empty($p['price']) && !in_array($effectiveCondition, ListingHealth::NEGATIVE_CONDITIONS, true) && empty($listingFlags)) {
                        Log::info('BatchImport: SERP row for existing product had no price — leaving product/offer untouched, listing-health path owns availability', [
                            'asin'        => $p['asin'],
                            'category_id' => $category->id,
                        ]);
                        $skipped++;
                        continue;
                    }

                    // A1 (F38): fetch the offer instance so image_url/stock_status can
                    // fall back to their previous value instead of being wiped to null.
                    $offer = ProductOffer::where('product_id', $existing->id)
                        ->where('store_id', $store->id)
                        ->first();

                    if ($offer) {
                        // L7: no 'updated_at' key here — it's not fillable, so mass
                        // assignment silently drops it and Eloquent touches it anyway.
                        $offer->update([
                            'scraped_price' => $p['price'] ?? null,
                            'raw_title'     => mb_substr($p['title'], 0, 500),
                            'image_url'     => $p['image_url'] ?? $offer->image_url,
                            'stock_status'  => $p['stock_status'] ?? $offer->stock_status,
                        ]);
                    }

                    $productUpdates = [
                        'price_tier' => $category->priceTierFor($p['price'] ?? null),
                    ];
                    // S2: only refresh amazon_rating when the scraper actually reported
                    // one AND we don't already know it — never wipe a known rating with
                    // a rating-less rescan (mirrors OfferIngestionService's guard).
                    if (!empty($p['rating']) && empty($existing->amazon_rating)) {
                        $productUpdates['amazon_rating'] = $p['rating'];
                    }
                    // A1: reviews_count refreshes to the latest reported value — never
                    // coerce a missing report into 0 (leaves the stored value untouched).
                    if (array_key_exists('reviews_count', $p) && $p['reviews_count'] !== null) {
                        $productUpdates['amazon_reviews_count'] = $p['reviews_count'];
                    }
                    $existing->update($productUpdates);

                    if ($offer) {
                        $override = $this->listingHealth->apply($offer, $existing, $effectiveCondition, $listingFlags, $p['stock_status'] ?? null);
                        // Fix 2: both the product-level ignore AND the offer-only flag
                        // (a clean offer survives elsewhere) count as "flagged" for the
                        // response message — the distinction only matters for whether
                        // the product got ignored, which the DB state already reflects.
                        if ($override === ListingHealthService::ACTION_FLAGGED_CONDITION
                            || $override === ListingHealthService::ACTION_FLAGGED_OFFER_CONDITION) {
                            $flagged++;
                        }
                    }

                    $priceUpdated = true;
                    $refreshed++;
                } else {
                    // Server-side condition guard (Spec 027 Addendum A §2b) — the
                    // extension's client-side title filter is version-dependent and
                    // must not be trusted alone. Reviewer B2: reached ONLY for a
                    // genuinely NEW product (no existing offer above) — never spend a
                    // create on a listing we're about to discard. An explicit, DOM-
                    // verified `condition` from the payload overrides this heuristic.
                    if ($condition === null && ProductConditionGuard::matchesTitle($p['title'])) {
                        $skipped++;
                        continue;
                    }

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
                        'slug'                 => Str::slug(Str::words($p['title'], 8, '')) . '-' . strtolower($p['asin']),
                        'amazon_rating'        => $p['rating'] ?? null,
                        // A1: never coerce a missing review count to 0.
                        'amazon_reviews_count' => $p['reviews_count'] ?? null,
                        'price_tier'           => $category->priceTierFor($p['price'] ?? null),
                        'status'               => 'pending_ai',
                        'is_ignored'           => false,
                    ]);

                    // Q1: updateOrCreate (not raw create()) keyed on (product_id, store_id).
                    $offer = ProductOffer::updateOrCreate(
                        ['product_id' => $product->id, 'store_id' => $store->id],
                        [
                            'tenant_id'     => $category->tenant_id,
                            'url'           => "https://www.amazon.com/dp/{$p['asin']}",
                            'scraped_price' => $p['price'] ?? null,
                            'raw_title'     => mb_substr($p['title'], 0, 500),
                            'image_url'     => $p['image_url'] ?? null,
                            'stock_status'  => $p['stock_status'] ?? null,
                        ]
                    );

                    $override = $this->listingHealth->apply($offer, $product, $condition, $listingFlags, $p['stock_status'] ?? null);
                    if ($override === ListingHealthService::ACTION_FLAGGED_CONDITION) {
                        // Product-level ignore — don't burn an AI evaluation on it.
                        // Spec 038 B3: never dispatched, so status must not stay
                        // stuck at 'pending_ai' forever.
                        $product->update(['status' => null]);
                        $flagged++;
                    } else {
                        // Fix 2: ACTION_FLAGGED_OFFER_CONDITION can't actually occur here
                        // (a brand-new product's first offer has no sibling offer to be
                        // "clean"), but if it ever did the product stays visible and
                        // should still get its AI pass like the null/other-flag cases.
                        if ($override === ListingHealthService::ACTION_FLAGGED_OFFER_CONDITION) {
                            $flagged++;
                        }
                        ProcessPendingProduct::dispatch($product->id, $category->id);
                    }

                    $created++;
                }
            } catch (\Exception $e) {
                Log::warning('BatchImport: failed to process product', [
                    'asin'  => $p['asin'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // A4: dispatch tier recalc once per request (not once per offer) when at least
        // one existing offer's price was refreshed.
        if ($priceUpdated) {
            RecalculateCategoryPriceTiers::dispatch($category->tenant_id, $category->id);
        }

        Log::info("BatchImport: {$created} created, {$refreshed} refreshed, {$skipped} skipped, {$flagged} flagged for category {$category->id}");

        return response()->json([
            'success'   => true,
            'created'   => $created,
            'refreshed' => $refreshed,
            'skipped'   => $skipped,
            'flagged'   => $flagged,
            'message'   => "Queued {$created} new product(s) for AI processing. Refreshed data for {$refreshed} existing product(s). Skipped {$skipped} listing(s) (condition-marked, or a priced existing offer with no corroborating price/condition evidence on the SERP). Flagged {$flagged} listing(s) for condition.",
        ]);
    }
}
