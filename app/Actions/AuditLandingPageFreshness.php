<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Category;
use App\Models\LandingPage;
use App\Models\Product;
use App\Support\ListingHealth;
use Illuminate\Support\Collection;

/**
 * Spec 030 §B2 — computes and persists a LandingPage's staleness reasons.
 *
 * Pure read logic over Products/Categories with exactly ONE write: the
 * landing_pages row itself (`stale_reasons` + `freshness_checked_at`). Never
 * calls AI, never mutates a product/offer.
 *
 * Tenancy: like every other Action in this codebase (see SelectLandingPagePicks),
 * this assumes tenancy is already initialized for `$page->tenant_id` by the
 * caller — the instant-path job and the nightly command are both responsible
 * for that.
 */
final class AuditLandingPageFreshness
{
    /** Spec 030 "Staleness reasons" §3: >15% current-vs-snapshot deviation. */
    private const PRICE_DRIFT_THRESHOLD = 0.15;

    /**
     * @return list<string> Reason codes; empty = fresh. Always persisted.
     */
    public function execute(LandingPage $page): array
    {
        $picks = collect($page->picks ?? [])
            ->filter(fn (array $pick) => isset($pick['product_id']))
            ->values();

        $productIds = $picks->pluck('product_id')->all();

        $products = Product::whereIn('id', $productIds)
            ->with('offers.store')
            ->get()
            ->keyBy('id');

        $reasons = [];

        if ($this->hasIneligiblePick($picks, $products, $page)) {
            $reasons[] = 'pick_ineligible';
        }

        if ($this->hasSelectionDrift($page, $picks)) {
            $reasons[] = 'selection_drift';
        }

        if ($this->hasPriceDrift($picks, $products)) {
            $reasons[] = 'price_drift';
        }

        if ($this->rendersShort($picks, $products)) {
            $reasons[] = 'render_short';
        }

        $page->update([
            'stale_reasons'        => $reasons,
            'freshness_checked_at' => now(),
        ]);

        return $reasons;
    }

    /**
     * A stored pick is deleted, detached (category_id null/changed away from the
     * page's own category), is_ignored, or its best offer carries a negative
     * condition (Spec 029) or the `high_price` listing flag.
     */
    private function hasIneligiblePick(Collection $picks, Collection $products, LandingPage $page): bool
    {
        return $picks->contains(function (array $pick) use ($products, $page) {
            $product = $products->get($pick['product_id']);

            if ($product === null) {
                return true; // deleted
            }

            if ($product->category_id === null || $product->category_id !== $page->category_id) {
                return true; // detached, or moved to a different category
            }

            if ($product->is_ignored) {
                return true;
            }

            $bestOffer = $product->best_offer;

            if ($bestOffer === null) {
                return false;
            }

            if (in_array($bestOffer->condition, ListingHealth::NEGATIVE_CONDITIONS, true)) {
                return true;
            }

            return in_array('high_price', $bestOffer->listing_flags ?? [], true);
        });
    }

    /**
     * Re-runs SelectLandingPagePicks and compares the resulting id SET (order-
     * insensitive) against the stored picks. A below-minimum-picks throw counts
     * as drift too (the category can no longer support the page as generated).
     */
    private function hasSelectionDrift(LandingPage $page, Collection $picks): bool
    {
        $category = Category::find($page->category_id);

        if ($category === null) {
            return true;
        }

        try {
            $fresh = (new SelectLandingPagePicks())->execute($category);
        } catch (\RuntimeException) {
            return true;
        }

        $freshIds  = collect($fresh)->pluck('product_id')->sort()->values()->all();
        $storedIds = $picks->pluck('product_id')->sort()->values()->all();

        return $freshIds !== $storedIds;
    }

    /**
     * Any pick with a snapshot whose current estimated_price deviates >15%.
     * Picks without a snapshot (pre-Spec-030 pages, or a backfill that found no
     * price) are skipped for this reason — no baseline to compare against.
     */
    private function hasPriceDrift(Collection $picks, Collection $products): bool
    {
        return $picks->contains(function (array $pick) use ($products) {
            $snapshot = $pick['est_price_snapshot'] ?? null;

            if ($snapshot === null || (float) $snapshot <= 0.0) {
                return false;
            }

            $product = $products->get($pick['product_id']);
            $current = $product?->estimated_price;

            if ($current === null) {
                return false;
            }

            $deviation = abs((float) $current - (float) $snapshot) / (float) $snapshot;

            return $deviation > self::PRICE_DRIFT_THRESHOLD;
        });
    }

    /**
     * Replicates LandingPageController::buildViewModel()'s render-time pick
     * filter (is_ignored=false, status=null, category_id not null) and compares
     * the survivor count against the stored pick count — the current silent
     * self-heal, made visible.
     */
    private function rendersShort(Collection $picks, Collection $products): bool
    {
        $renderable = $picks->filter(function (array $pick) use ($products) {
            $product = $products->get($pick['product_id']);

            return $product !== null
                && $product->is_ignored === false
                && $product->status === null
                && $product->category_id !== null;
        })->count();

        return $renderable < $picks->count();
    }
}
