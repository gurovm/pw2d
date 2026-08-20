<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ProcessPendingProduct;
use App\Jobs\RecalculateCategoryPriceTiers;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Store;
use App\Support\ProductConditionGuard;
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
    public function __construct(
        private AiService $aiService,
        private ListingHealthService $listingHealth,
    ) {}

    /**
     * @param array{
     *   url: string,
     *   store_slug: string,
     *   raw_title: string,
     *   brand: string,
     *   scraped_price: ?float,
     *   image_url: ?string,
     *   stock_status: ?string,
     *   category_id: int,
     *   rating: ?float,
     *   reviews_count: ?int,
     *   condition: ?string,
     *   listing_flags: ?array,
     *   offer_id: ?int,
     * } $data
     * @return array{action: string, product_id: int|null}
     */
    public function processIncomingOffer(array $data): array
    {
        $tenantId     = tenant('id');
        $condition    = $data['condition'] ?? null;
        $listingFlags = $data['listing_flags'] ?? [];

        // 1. Resolve store
        $store = Store::firstOrCreate(
            ['slug' => $data['store_slug'], 'tenant_id' => $tenantId],
            ['name' => Str::title(str_replace('-', ' ', $data['store_slug']))]
        );

        // 2. Resolve the offer being refreshed/rescanned (Spec 033 precedence).
        $existingOffer = $this->resolveExistingOffer($data, $store, $tenantId);

        if ($existingOffer) {
            // Reviewer B2: a title marker is condition EVIDENCE for a listing we
            // already track — a rescan of an existing offer must heal (raw_title +
            // flag), never be silently skipped like a brand-new listing would be
            // (see the create-new guard at the bottom of this method).
            // 029B-B4: canonical ListingHealth vocabulary, never a raw marker string.
            // Fix 1 (2026-08-15): resolveEffectiveCondition() — a negative title
            // marker beats an explicit payload `'new'` (see docblock); an explicit
            // negative payload condition and `'unknown'` are never overridden.
            $effectiveCondition = ProductConditionGuard::resolveEffectiveCondition($condition, $data['raw_title']);

            // A1 (F38): scraped_price + raw_title always refresh; image_url/stock_status
            // only overwrite when the payload actually supplies a non-null value — an
            // omitted field must never blow away a previously known-good one.
            $existingOffer->update([
                'scraped_price' => $data['scraped_price'],
                'raw_title'     => mb_substr($data['raw_title'], 0, 500),
                'image_url'     => $data['image_url'] ?? $existingOffer->image_url,
                'stock_status'  => $data['stock_status'] ?? $existingOffer->stock_status,
            ]);

            $action = 'refreshed';

            $product = Product::find($existingOffer->product_id);
            if ($product) {
                $updates = [];
                // A1: reviews_count refreshes to the latest reported value whenever the
                // scraper provides one — never backfill-only, never coerce a missing
                // report into 0 (leaves the stored value untouched instead).
                if (array_key_exists('reviews_count', $data) && $data['reviews_count'] !== null) {
                    $updates['amazon_reviews_count'] = $data['reviews_count'];
                }
                if (!empty($data['rating']) && empty($product->amazon_rating)) {
                    $updates['amazon_rating'] = $data['rating'];
                }
                if (!empty($updates)) {
                    $product->update($updates);
                }

                $override = $this->listingHealth->apply($existingOffer, $product, $effectiveCondition, $listingFlags, $data['stock_status'] ?? null);
                if ($override !== null) {
                    $action = $override;
                }

                // Perf H1: only queue a (chunked, category-wide) tier recalc when the
                // price actually moved — a rescan walk that refreshes hundreds of
                // unchanged offers must not queue hundreds of redundant jobs.
                if ($existingOffer->wasChanged('scraped_price')) {
                    RecalculateCategoryPriceTiers::dispatch($tenantId, $product->category_id ?? $data['category_id']);
                }
            }

            Log::info('OfferIngestion: refreshed existing offer', [
                'offer_id' => $existingOffer->id,
                'store'    => $store->name,
                'action'   => $action,
            ]);

            return ['action' => $action, 'product_id' => $existingOffer->product_id];
        }

        // Server-side condition guard (Spec 027 Addendum A §2b) — the extension's
        // client-side title filter is version-dependent and must not be trusted alone.
        // Reviewer B2: reached ONLY once we know there's no existing offer to heal
        // (handled above); its job is to keep marker-titled junk out of the catalog
        // before a Product row is ever created, so it must run BEFORE the AI-match
        // attempt below (never spend an AI call on a listing we're about to discard).
        // An explicit, DOM-verified `condition` from the payload always overrides this
        // text heuristic — proceed to normal (possibly flagged) creation instead.
        if ($condition === null && ProductConditionGuard::matchesTitle($data['raw_title'])) {
            Log::info('OfferIngestion: skipped condition-marked listing', [
                'raw_title' => $data['raw_title'],
            ]);

            return ['action' => 'skipped_condition', 'product_id' => null];
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

        // Verify matched product still exists AND belongs to this tenant — Security
        // M2: the AI-match cache is keyed on title text alone, so a stale/poisoned
        // row (or a future AiService::matchProduct regression) must never be able to
        // attach this tenant's offer to another tenant's product.
        if ($matchedProductId && !Product::withoutGlobalScopes()
                ->where('id', $matchedProductId)
                ->where('tenant_id', $tenantId)
                ->exists()) {
            $matchedProductId = null;
        }

        if ($matchedProductId) {
            // 4. Match found → attach (or update) offer on existing product.
            // Use updateOrCreate to respect (product_id, store_id) unique constraint —
            // if this product already has an offer from this store, refresh it.
            // A1 (F38): fetch the pre-existing offer (if any) first so image_url/
            // stock_status fall back to their PREVIOUS value rather than being wiped
            // to null when this payload doesn't supply them.
            $priorOffer = ProductOffer::where('product_id', $matchedProductId)
                ->where('store_id', $store->id)
                ->first();

            $offer = ProductOffer::updateOrCreate(
                ['product_id' => $matchedProductId, 'store_id' => $store->id],
                [
                    'tenant_id'     => $tenantId,
                    'url'           => $data['url'],
                    'scraped_price' => $data['scraped_price'],
                    'raw_title'     => mb_substr($data['raw_title'], 0, 500),
                    'image_url'     => $data['image_url'] ?? $priorOffer?->image_url,
                    'stock_status'  => $data['stock_status'] ?? $priorOffer?->stock_status,
                ]
            );

            $action = 'matched';

            // Update rating/reviews on the matched product if the scraper provided them
            // and they improve on what's stored. Only overwrites 0/null values.
            // Perf H2 (2026-08-16 audit): 'offers.store' — best_price/best_offer
            // (read a few lines below via priceTierFor()) now reads $offer->store
            // for the commission/priority tiebreak; without this eager load, every
            // offer on this product triggered its own Store lazy-load on this
            // matched-offer ingest hot path.
            $product = Product::with(['offers.store', 'category'])->find($matchedProductId);
            if ($product) {
                $updates = [];
                // A1: reviews_count always refreshes to the latest reported value —
                // never backfill-only, never coerce a missing report into 0.
                if (array_key_exists('reviews_count', $data) && $data['reviews_count'] !== null) {
                    $updates['amazon_reviews_count'] = $data['reviews_count'];
                }
                if (!empty($data['rating']) && empty($product->amazon_rating)) {
                    $updates['amazon_rating'] = $data['rating'];
                }
                // Recalculate price tier with new offer
                if ($product->category) {
                    $updates['price_tier'] = $product->category->priceTierFor((float) $product->best_price);
                }
                if (!empty($updates)) {
                    $product->update($updates);
                }

                $override = $this->listingHealth->apply($offer, $product, $condition, $listingFlags, $data['stock_status'] ?? null);
                if ($override !== null) {
                    $action = $override;
                }

                // Perf H1: only when there WAS a prior offer (i.e. this is a refresh,
                // not attaching a brand-new store offer) AND its price actually moved.
                if ($priorOffer && $offer->wasChanged('scraped_price')) {
                    RecalculateCategoryPriceTiers::dispatch($tenantId, $product->category_id ?? $data['category_id']);
                }
            }

            Log::info('OfferIngestion: matched to existing product', [
                'product_id' => $matchedProductId,
                'store'      => $store->name,
                'raw_title'  => $data['raw_title'],
                'action'     => $action,
            ]);

            return ['action' => $action, 'product_id' => $matchedProductId];
        }

        // 5. No match → create new product stub + offer, queue AI evaluation
        $category = Category::with('features')->findOrFail($data['category_id']);

        $product = Product::create([
            'tenant_id'            => $tenantId,
            'category_id'          => $category->id,
            'name'                 => mb_substr($data['raw_title'], 0, 255),
            'slug'                 => Str::slug(Str::words($data['raw_title'], 8, '')) . '-' . Str::random(5),
            'amazon_rating'        => $data['rating'] ?? null,
            // A1: never coerce a missing review count to 0 — null means "unknown", not zero.
            'amazon_reviews_count' => $data['reviews_count'] ?? null,
            'price_tier'           => $category->priceTierFor($data['scraped_price']),
            'status'               => 'pending_ai',
            'is_ignored'           => false,
        ]);

        // Q1: updateOrCreate (not raw create()) keyed on (product_id, store_id) —
        // defense in depth even though a brand-new product can't yet collide.
        $offer = ProductOffer::updateOrCreate(
            ['product_id' => $product->id, 'store_id' => $store->id],
            [
                'tenant_id'     => $tenantId,
                'url'           => $data['url'],
                'scraped_price' => $data['scraped_price'],
                'raw_title'     => mb_substr($data['raw_title'], 0, 500),
                'image_url'     => $data['image_url'] ?? null,
                'stock_status'  => $data['stock_status'] ?? null,
            ]
        );

        $action = 'created';

        $override = $this->listingHealth->apply($offer, $product, $condition, $listingFlags, $data['stock_status'] ?? null);
        if ($override !== null) {
            $action = $override;
        }

        // Fix 2: ACTION_FLAGGED_OFFER_CONDITION deliberately falls through to the
        // dispatch below (can't actually occur here — a brand-new product's first
        // offer has no sibling to be "clean" — but the check stays generic so the
        // product-stays-visible case would always get its normal AI pass).
        if ($action !== ListingHealthService::ACTION_FLAGGED_CONDITION && $category->features->isNotEmpty()) {
            ProcessPendingProduct::dispatch($product->id, $category->id);
        }

        Log::info('OfferIngestion: created new product', [
            'product_id' => $product->id,
            'store'      => $store->name,
            'action'     => $action,
        ]);

        return ['action' => $action, 'product_id' => $product->id];
    }

    /**
     * Spec 033 — resolve the offer this payload is refreshing/rescanning.
     *
     * Precedence:
     *   (a) `offer_id`, when supplied, explicitly re-scoped `where('tenant_id', ...)`.
     *       The controller's `Rule::exists` already enforces tenant ownership at the
     *       HTTP boundary, but this is an API controller running outside domain
     *       tenancy middleware, so the service re-scopes independently rather than
     *       trusting the caller (CLAUDE.md multi-tenant data access rule).
     *   (b) the targeted offer's store must agree with the store already resolved
     *       from `store_slug` — on mismatch (e.g. a stale work-list row), ignore the
     *       offer_id, log it, and fall through to the URL lookup.
     *   (c) absent, or unresolvable (deleted between rescan-list and this POST) →
     *       today's (store_id, url) lookup, unchanged — now ordered by id, and
     *       logging a warning naming the ids when it matches more than one offer
     *       (previously-invisible cross-category duplicates, made greppable).
     *
     * Never hard-fails on an unresolvable offer_id: a ~100-offer rescan walk must
     * not stall because one row was deleted mid-run.
     */
    private function resolveExistingOffer(array $data, Store $store, ?string $tenantId): ?ProductOffer
    {
        if (!empty($data['offer_id'])) {
            $targetedOffer = ProductOffer::where('id', $data['offer_id'])
                ->where('tenant_id', $tenantId)
                ->first();

            if ($targetedOffer) {
                if ($targetedOffer->store_id === $store->id) {
                    return $targetedOffer;
                }

                Log::warning('OfferIngestion: offer_id store mismatch, falling back to URL match', [
                    'offer_id'          => $data['offer_id'],
                    'offer_store_id'    => $targetedOffer->store_id,
                    'resolved_store_id' => $store->id,
                ]);
            }
            // else: offer_id didn't resolve (deleted mid-walk) — degrade silently to
            // the URL lookup below rather than hard-failing this offer.
        }

        $urlMatches = ProductOffer::where('store_id', $store->id)
            ->where('url', $data['url'])
            ->orderBy('id')
            ->get();

        if ($urlMatches->count() > 1) {
            Log::warning('OfferIngestion: URL lookup matched multiple offers', [
                'store_id'  => $store->id,
                'url'       => $data['url'],
                'offer_ids' => $urlMatches->pluck('id')->all(),
            ]);
        }

        return $urlMatches->first();
    }
}
