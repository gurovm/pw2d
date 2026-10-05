<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Product;
use App\Models\VsPage;
use App\Support\ListingHealth;

/**
 * Spec 043 — computes and persists a VS page's staleness reasons (same contract as
 * AuditLandingPageFreshness; assumes tenancy is initialized for the page's tenant).
 *
 * Reasons: `pick_ineligible` (either product gone, hidden, detached or without a
 * pick-eligible offer) and `price_drift` (either product's estimated_price moved
 * beyond AuditLandingPageFreshness::PRICE_DRIFT_THRESHOLD from the saved snapshot).
 */
final class AuditVsPageFreshness
{
    /**
     * @return list<string> stale reasons, empty when fresh
     */
    public function handle(VsPage $page): array
    {
        $products = Product::whereIn('id', [$page->product_a_id, $page->product_b_id])
            ->with('offers.store')
            ->get()
            ->keyBy('id');

        $a = $products->get($page->product_a_id);
        $b = $products->get($page->product_b_id);

        $reasons = [];

        if (!$this->isEligible($a, $page) || !$this->isEligible($b, $page)) {
            $reasons[] = 'pick_ineligible';
        }

        if ($this->drifted($a, $page->price_snapshot_a) || $this->drifted($b, $page->price_snapshot_b)) {
            $reasons[] = 'price_drift';
        }

        // updateQuietly on a no-op re-audit skips the cache-busting `saved` hook
        // (same reasoning as AuditLandingPageFreshness).
        $changed    = ($page->stale_reasons ?? []) !== $reasons;
        $attributes = ['stale_reasons' => $reasons, 'freshness_checked_at' => now()];

        $changed ? $page->update($attributes) : $page->updateQuietly($attributes);

        return $reasons;
    }

    private function isEligible(?Product $product, VsPage $page): bool
    {
        return $product !== null
            && !$product->is_ignored
            && $product->status === null
            && $product->category_id !== null
            && $product->category_id === $page->category_id
            && $product->offers->contains(fn ($offer) => ListingHealth::isPickEligible($offer));
    }

    private function drifted(?Product $product, ?int $snapshot): bool
    {
        $current = $product?->estimated_price;

        if ($snapshot === null || $snapshot <= 0 || $current === null) {
            return false;
        }

        return abs($current - $snapshot) / $snapshot > AuditLandingPageFreshness::PRICE_DRIFT_THRESHOLD;
    }
}
