<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\AuditLandingPageFreshnessJob;
use App\Models\LandingPage;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Support\ListingHealth;
use Illuminate\Support\Facades\Log;

/**
 * Applies Spec 029 §A2 (amended 2026-08-10) listing-health semantics to a freshly
 * ingested/refreshed offer + its product. Single source of truth for all three
 * ingestion paths (OfferIngestionService, BatchImportController, ProductImportController)
 * so the condition/high_price rules never drift between them.
 *
 * Rules:
 * - `condition` absent (null) → no-op, today's behavior. Nothing is touched.
 * - `condition` ∈ NEGATIVE_CONDITIONS (renewed/refurbished/open_box/used) → offer gets
 *   the condition + flags stored, product is ignored. Returns 'flagged_condition' so the
 *   caller can override its response `action`. A landing-page-pick warning is logged only
 *   on the transition into is_ignored (not on every repeat report of an already-flagged
 *   listing).
 * - `condition` clean (new/unknown) + non-empty `listing_flags` (e.g. `high_price`) →
 *   flags stored on the OFFER only; the product stays visible. Returns null (base
 *   ingestion action stands).
 * - `condition` clean + no flags (a clean explicit DOM report) → clears `listing_flags`
 *   to `[]` and stores the REPORTED clean value as-is (S4, 2026-08-10): 'new' means the
 *   extension affirmatively confirmed a standard listing; 'unknown' means it couldn't
 *   tell — coercing 'unknown' to 'new' would overstate what was actually verified.
 *   Flags are point-in-time listing state and must flip both ways as a listing recovers.
 *
 * Every branch stamps `health_checked_at` — it's simply "the last time the extension
 * DOM-inspected this listing," regardless of the outcome.
 *
 * S1 (2026-08-10): the instant freshness-audit dispatch fires on ANY material change
 * to `listing_flags`/`condition` in the two clean branches below — both directions (a
 * flag being SET and a flag being CLEARED can each flip a product's landing-page pick
 * eligibility), not just a fresh `high_price`.
 */
class ListingHealthService
{
    /**
     * @param array<int, string> $listingFlags
     * @return string|null 'flagged_condition' when the caller should override its
     *   response action; null when the base ingestion action stands.
     */
    public function apply(ProductOffer $offer, Product $product, ?string $condition, array $listingFlags): ?string
    {
        if ($condition === null) {
            return null;
        }

        $now = now();

        if (in_array($condition, ListingHealth::NEGATIVE_CONDITIONS, true)) {
            $offer->update([
                'condition'         => $condition,
                'listing_flags'     => $listingFlags,
                'health_checked_at' => $now,
            ]);

            if (!$product->is_ignored) {
                $product->update(['is_ignored' => true]);
                $this->warnIfLandingPagePick($product, $condition);
            }

            return 'flagged_condition';
        }

        if (!empty($listingFlags)) {
            $offer->update([
                'condition'         => $condition,
                'listing_flags'     => $listingFlags,
                'health_checked_at' => $now,
            ]);

            // Spec 030 §B3 / S1: `high_price` (or any future flag) doesn't flip
            // is_ignored/category_id, so ProductObserver never sees this change — the
            // instant freshness-audit trigger has to live here instead, at the one
            // place flags are set. Dispatch on ANY material flags/condition change,
            // not just a fresh `high_price` value.
            if ($offer->wasChanged('listing_flags') || $offer->wasChanged('condition')) {
                AuditLandingPageFreshnessJob::dispatchForProduct($product);
            }

            return null;
        }

        // Clean explicit report — clears listing_flags and stores the REPORTED clean
        // condition value as-is (S4): 'new' vs 'unknown' are both valid here; coercing
        // 'unknown' to 'new' would overstate what was actually verified.
        $offer->update([
            'condition'         => $condition,
            'listing_flags'     => [],
            'health_checked_at' => $now,
        ]);

        // S1: a listing recovering (e.g. a previously-set `high_price` clearing) is
        // just as pick-eligibility-relevant as a flag being set — dispatch both ways.
        if ($offer->wasChanged('listing_flags') || $offer->wasChanged('condition')) {
            AuditLandingPageFreshnessJob::dispatchForProduct($product);
        }

        return null;
    }

    /**
     * Spec 029 §A2: "When a newly-flagged product is a current landing-page pick (any
     * status), log a warning naming the landing page slug(s)." A landing page's `picks`
     * JSON only ever draws from its own category (unique per tenant+category), so this
     * is a small, bounded lookup — never more than a handful of rows.
     */
    private function warnIfLandingPagePick(Product $product, string $condition): void
    {
        if ($product->category_id === null) {
            return;
        }

        $slugs = LandingPage::where('category_id', $product->category_id)
            ->get(['slug', 'picks'])
            ->filter(fn (LandingPage $page) => collect($page->picks ?? [])->contains(
                fn ($pick) => (int) ($pick['product_id'] ?? 0) === $product->id
            ))
            ->pluck('slug');

        if ($slugs->isNotEmpty()) {
            Log::warning('ListingHealthService: condition-flagged product is a current landing-page pick', [
                'product_id'         => $product->id,
                'condition'          => $condition,
                'landing_page_slugs' => $slugs->values()->all(),
            ]);
        }
    }
}
