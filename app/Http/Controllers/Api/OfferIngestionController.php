<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductOffer;
use App\Services\OfferIngestionService;
use App\Support\ListingHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
     * Spec 029 §A3 — the extension's rescan work-list: this tenant's non-ignored,
     * fully-processed products in a category, oldest health_checked_at/updated_at
     * first (never-DOM-checked offers are prioritized via the updated_at fallback).
     */
    public function rescanList(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['required', Rule::exists('categories', 'id')->where('tenant_id', tenant('id'))],
        ]);

        $offers = ProductOffer::query()
            ->whereHas('product', fn ($q) => $q
                ->where('category_id', $validated['category_id'])
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

        return response()->json(['success' => true, 'offers' => $offers]);
    }
}
