<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LandingPage;
use App\Models\ProductOffer;
use App\Services\OfferIngestionService;
use App\Support\ListingHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class OfferIngestionController extends Controller
{
    public function ingest(Request $request, OfferIngestionService $service): JsonResponse
    {
        $validated = $request->validate([
            'url'                 => 'required|url|max:2000',
            'store_slug'          => 'required|string|max:100',
            'raw_title'           => 'required|string|min:3|max:500',
            'brand'               => 'nullable|string|max:100',
            'scraped_price'       => 'nullable|numeric|min:0',
            'image_url'           => 'nullable|url|max:2000',
            'stock_status'        => 'nullable|string|max:50',
            'category_id'         => ['required', Rule::exists('categories', 'id')->where('tenant_id', tenant('id'))],
            'rating'              => 'nullable|numeric|min:0|max:5',
            'reviews_count'       => 'nullable|integer|min:0',
            'condition'           => ['nullable', Rule::in(ListingHealth::CONDITIONS)],
            // Security L2: bound the array so a repeated-element payload can't bloat
            // the listing_flags JSON column / later in_array() scans.
            'listing_flags'       => 'nullable|array|max:5',
            'listing_flags.*'     => ['distinct', 'string', Rule::in(ListingHealth::RECOGNIZED_FLAGS)],
        ]);

        $result = $service->processIncomingOffer($validated);

        return response()->json([
            'success' => true,
            ...$result,
        ]);
    }

    /**
     * Spec 029 §A3 / Spec 031 — the extension's rescan work-list.
     *
     * `scope=category` (default, today's behaviour unchanged): this tenant's
     * non-ignored, fully-processed products in a `category_id`, oldest
     * health_checked_at/updated_at first (never-DOM-checked offers are
     * prioritized via the updated_at fallback).
     *
     * `scope=picks`: every offer of every product that is a pick (any role) on
     * ANY of this tenant's landing pages — any status (draft or published), any
     * category — so the weekly "verify live picks" pass covers exactly what
     * readers can click. `category_id` is accepted but deliberately IGNORED in
     * this mode (not validated, not used to filter) — see docs/questions.md:
     * a stale/leftover client-side category_id must never silently narrow a
     * picks sweep.
     */
    public function rescanList(Request $request): JsonResponse
    {
        $scope = $request->input('scope', 'category');

        $rules = ['scope' => ['nullable', Rule::in(['category', 'picks'])]];

        if ($scope !== 'picks') {
            $rules['category_id'] = ['required', Rule::exists('categories', 'id')->where('tenant_id', tenant('id'))];
        }

        $validated = $request->validate($rules);

        $offers = $scope === 'picks'
            ? $this->picksScopeOffers()
            : $this->categoryScopeOffers((int) $validated['category_id']);

        return response()->json(['success' => true, 'offers' => $offers]);
    }

    private function categoryScopeOffers(int $categoryId): Collection
    {
        return ProductOffer::query()
            ->whereHas('product', fn ($q) => $q
                ->where('category_id', $categoryId)
                ->where('is_ignored', false)
                ->whereNull('status'))
            ->orderByRaw('COALESCE(health_checked_at, updated_at) asc')
            // Security L3: hygiene cap — bounded in practice by category size (today
            // ~hundreds), but the extension walks sequentially anyway and can simply
            // re-request as health_checked_at advances, so this never loses coverage.
            ->limit(500)
            ->get(['id', 'product_id', 'url', 'health_checked_at'])
            ->map(fn (ProductOffer $offer) => [
                'offer_id'        => $offer->id,
                'product_id'      => $offer->product_id,
                'url'             => $offer->url,
                'asin'            => basename(parse_url($offer->url, PHP_URL_PATH)),
                'last_scanned_at' => $offer->health_checked_at,
            ]);
    }

    /**
     * All offers (every store, not just `best_offer`) of every product that
     * appears in ANY of this tenant's landing pages' `picks` JSON — no
     * is_ignored/status/category filter, unlike the category scope: a pick
     * that has since gone ignored is exactly the kind of drift this pass
     * exists to catch, not something to silently skip.
     *
     * Each row also carries `category_id` (the product's own, NOT the ignored
     * request param): `POST /api/extension/ingest-offer` requires category_id,
     * and a picks run spans every category, so the extension has no single
     * value to send back for a re-scanned pick without it (T2 contract
     * addendum, docs/specs/031-content-maintenance-cadence.md).
     */
    private function picksScopeOffers(): Collection
    {
        $slugsByProductId = $this->pickProductSlugs();

        if ($slugsByProductId->isEmpty()) {
            return collect();
        }

        return ProductOffer::query()
            // Security: API controllers running outside domain-based tenancy
            // middleware must scope explicitly (CLAUDE.md) — product IDs already
            // came from this tenant's own landing pages, but this is defense in
            // depth rather than relying solely on that upstream filter.
            ->where('tenant_id', tenant('id'))
            ->whereIn('product_id', $slugsByProductId->keys())
            ->with('product:id,category_id')
            ->orderByRaw('COALESCE(health_checked_at, updated_at) asc')
            ->limit(500)
            ->get(['id', 'product_id', 'url', 'health_checked_at'])
            ->map(fn (ProductOffer $offer) => [
                'offer_id'          => $offer->id,
                'product_id'        => $offer->product_id,
                'category_id'       => $offer->product?->category_id,
                'url'               => $offer->url,
                'asin'              => basename(parse_url($offer->url, PHP_URL_PATH)),
                'last_scanned_at'   => $offer->health_checked_at,
                'landing_page_slug' => $slugsByProductId->get($offer->product_id),
            ]);
    }

    /**
     * Every distinct pick product_id across this tenant's landing pages
     * (any status), mapped to a single deterministic landing_page_slug. When a
     * product is a pick on more than one page, the first page by slug ASC wins
     * — arbitrary but deterministic, and avoids duplicating the offer row per
     * pick-page (see docs/questions.md).
     *
     * Filtered in PHP over the tenant's own bounded landing-page set — NEVER a
     * `LIKE` against the `picks` JSON column. Same pattern, same reason, as
     * {@see \App\Jobs\AuditLandingPageFreshnessJob::dispatchForProduct()}: MySQL
     * normalises a native `json` column to a space-padded string on cast to
     * text, so a space-free `LIKE '%"product_id":N,%'` pattern silently never
     * matches in production even though it passes on sqlite (Spec 030 §B1).
     */
    private function pickProductSlugs(): Collection
    {
        return LandingPage::query()
            ->where('tenant_id', tenant('id'))
            ->orderBy('slug')
            ->get(['slug', 'picks'])
            ->reduce(function (Collection $slugsByProductId, LandingPage $page) {
                foreach ($page->picks ?? [] as $pick) {
                    $productId = (int) ($pick['product_id'] ?? 0);

                    if ($productId > 0 && !$slugsByProductId->has($productId)) {
                        $slugsByProductId->put($productId, $page->slug);
                    }
                }

                return $slugsByProductId;
            }, collect());
    }
}
