<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductImportRequest;
use App\Jobs\ProcessPendingProduct;
use App\Jobs\RecalculateCategoryPriceTiers;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Store;
use App\Services\ListingHealthService;
use App\Support\ProductConditionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductImportController extends Controller
{
    public function __construct(private readonly ListingHealthService $listingHealth) {}

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

        $condition    = $validated['condition'] ?? null;
        $listingFlags = $validated['listing_flags'] ?? [];

        if ($existingOffer) {
            $product = $existingOffer->product;

            // Security M1: a re-import/re-scan of an already-ignored product (condition
            // flag or a human Filament decision) must NEVER silently un-ignore it — Spec
            // 029's explicit non-goal: "reversal stays a human decision in Filament."
            if ($product->is_ignored) {
                return response()->json([
                    'success' => true,
                    'action'  => 'skipped_ignored',
                    'message' => 'Product is ignored (condition/human decision) — un-ignore in Filament first.',
                    'product' => ['id' => $product->id],
                ]);
            }

            // Reviewer B2: a title marker is condition EVIDENCE for a listing we
            // already track — a rescan of an existing offer must heal (raw_title +
            // flag), never be silently skipped like a brand-new listing would be
            // (see the guard in the `else` branch below).
            // 029B-B4: canonical ListingHealth vocabulary, never a raw marker string.
            // Fix 1 (2026-08-15): resolveEffectiveCondition() — a negative title
            // marker beats an explicit payload `'new'`; an explicit negative payload
            // condition and `'unknown'` are never overridden.
            $effectiveCondition = ProductConditionGuard::resolveEffectiveCondition($condition, $validated['title']);

            // A1 (F38): image_url/stock_status only overwrite when the payload actually
            // supplies a non-null value — an omitted field must never blow away a
            // previously known-good one.
            $existingOffer->update([
                'scraped_price' => $validated['price'] ?? null,
                'raw_title'     => mb_substr($validated['title'], 0, 500),
                'image_url'     => $validated['image_url'] ?? $existingOffer->image_url,
                'stock_status'  => $validated['stock_status'] ?? $existingOffer->stock_status,
            ]);

            // Update product
            $productUpdates = [
                'name'       => mb_substr($validated['title'], 0, 255),
                'slug'       => Str::slug(Str::words($validated['title'], 8, '')) . '-' . strtolower($asin),
                'price_tier' => $category->priceTierFor($validated['price'] ?? null),
                'status'     => 'pending_ai',
            ];
            // S2: only refresh amazon_rating when the scraper actually reported one AND
            // we don't already know it — never wipe a known rating with a rating-less
            // rescan (mirrors OfferIngestionService's guard).
            if (!empty($validated['rating']) && empty($product->amazon_rating)) {
                $productUpdates['amazon_rating'] = $validated['rating'];
            }
            // A1: reviews_count refreshes to the latest reported value — never coerce
            // a missing report into 0 (leaves the stored value untouched).
            if (array_key_exists('reviews_count', $validated) && $validated['reviews_count'] !== null) {
                $productUpdates['amazon_reviews_count'] = $validated['reviews_count'];
            }
            $product->update($productUpdates);

            $wasNew = false;
        } else {
            // Server-side condition guard (Spec 027 Addendum A §2b) — the extension's
            // client-side title filter is version-dependent and must not be trusted
            // alone. Reviewer B2: reached ONLY for a genuinely NEW product (no existing
            // offer above) — never spend a create on a listing we're about to discard.
            // An explicit, DOM-verified `condition` from the payload overrides this
            // text heuristic.
            if ($condition === null && ProductConditionGuard::matchesTitle($validated['title'])) {
                return response()->json([
                    'success' => true,
                    'action'  => 'skipped_condition',
                    'message' => 'Listing title indicates a renewed/refurbished/open-box/used condition — not imported.',
                ]);
            }

            $effectiveCondition = $condition;

            // Create new product
            $product = Product::create([
                'tenant_id'            => $category->tenant_id,
                'category_id'          => $category->id,
                'name'                 => mb_substr($validated['title'], 0, 255),
                'slug'                 => Str::slug(Str::words($validated['title'], 8, '')) . '-' . strtolower($asin),
                'amazon_rating'        => $validated['rating'] ?? null,
                // A1: never coerce a missing review count to 0.
                'amazon_reviews_count' => $validated['reviews_count'] ?? null,
                'price_tier'           => $category->priceTierFor($validated['price'] ?? null),
                'status'               => 'pending_ai',
                'is_ignored'           => false,
            ]);

            // Create Amazon offer — Q1: updateOrCreate keyed on (product_id, store_id).
            $existingOffer = ProductOffer::updateOrCreate(
                ['product_id' => $product->id, 'store_id' => $store->id],
                [
                    'tenant_id'     => $category->tenant_id,
                    'url'           => $amazonUrl,
                    'scraped_price' => $validated['price'] ?? null,
                    'raw_title'     => mb_substr($validated['title'], 0, 500),
                    'image_url'     => $validated['image_url'] ?? null,
                    'stock_status'  => $validated['stock_status'] ?? null,
                ]
            );

            $wasNew = true;
        }

        $listingOverride = $this->listingHealth->apply($existingOffer, $product, $effectiveCondition, $listingFlags, $validated['stock_status'] ?? null);

        // Spec 038 B3 (review fix M1, 2026-08-28): whenever the guard above
        // ignores the listing for condition, nothing gets dispatched below — so
        // the 'pending_ai' status must be cleared regardless of $wasNew. For an
        // existing product this status was just written by THIS SAME request at
        // ~:134, not left over from an earlier import; ProcessPendingProduct
        // unconditionally overwrites 'status' on every outcome it produces
        // (ProcessPendingProduct.php:81, :153, :182, :236), so there is no
        // in-flight job this could ever strand — clearing here is always safe.
        if ($listingOverride === ListingHealthService::ACTION_FLAGGED_CONDITION) {
            $product->update(['status' => null]);
        }

        // Don't burn an AI evaluation on a listing we just ignored for condition.
        // Fix 2: ACTION_FLAGGED_OFFER_CONDITION deliberately falls through to the
        // dispatch below — the product stays visible (a clean offer survives
        // elsewhere), so it should still get its normal AI pass.
        if ($listingOverride !== ListingHealthService::ACTION_FLAGGED_CONDITION) {
            ProcessPendingProduct::dispatch($product->id, $category->id);
        }

        // Perf H1: only queue a (chunked, category-wide) tier recalc when the price
        // actually moved — a rescan/re-import of an unchanged listing must not queue
        // a redundant job.
        if (!$wasNew && $existingOffer->wasChanged('scraped_price')) {
            RecalculateCategoryPriceTiers::dispatch($category->tenant_id, $category->id);
        }

        return response()->json([
            'success' => true,
            'action'  => $listingOverride ?? ($wasNew ? 'queued_new' : 'queued_rescan'),
            'product' => ['id' => $product->id],
        ]);
    }
}
