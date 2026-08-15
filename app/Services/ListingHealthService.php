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
 * - `condition` = 'unknown' (029B-B3) → stamp-only: the extension COULD NOT verify the
 *   page (missing #productTitle / no buy-box after settle), so this is "we looked", not
 *   a clean bill of health. Stores 'unknown' + `health_checked_at`, but never clears
 *   `listing_flags`, never counts as clean, never dispatches the freshness audit.
 * - `condition` ∈ NEGATIVE_CONDITIONS (renewed/refurbished/open_box/used) → the condition
 *   + flags are ALWAYS stored on the OFFER. Whether the PRODUCT gets ignored depends on
 *   whether it has any OTHER "clean, purchasable" offer left (see {@see hasCleanOffer()}
 *   — priced, condition not negative, no pick-excluding flag):
 *     - No clean offer remains (this was the product's only/last viable listing) →
 *       product is ignored, {@see ACTION_FLAGGED_CONDITION} returned, landing-page-pick
 *       warning logged (only on the transition into is_ignored).
 *     - A clean offer remains (a multi-store product, e.g. a clean Amazon listing next
 *       to a refurbished Whole Latte Love one) → product stays visible,
 *       {@see ACTION_FLAGGED_OFFER_CONDITION} returned instead — the bad listing is
 *       recorded but never deletes a product that's still legitimately buyable
 *       elsewhere. Fix 2 (2026-08-15 live-QA hole).
 * - `condition` clean ('new') + non-empty `listing_flags` (e.g. `high_price`,
 *   `unavailable`) → flags stored on the OFFER only; the product stays visible.
 *   Returns null (base ingestion action stands).
 * - `condition` clean ('new') + no flags (a clean explicit DOM report) → clears
 *   `listing_flags` to `[]`: the extension affirmatively confirmed a standard listing.
 *   Flags are point-in-time listing state and must flip both ways as a listing recovers.
 *   Fix 2: if this offer was the one carrying a negative condition that had previously
 *   auto-ignored the product, is_ignored is deliberately left alone (Spec 029 non-goal —
 *   reversal stays a human decision) but a notice is logged so the owner can spot and
 *   manually review/un-ignore it.
 *
 * Every branch stamps `health_checked_at` — it's simply "the last time the extension
 * DOM-inspected this listing," regardless of the outcome.
 *
 * S1 (2026-08-10): the instant freshness-audit dispatch fires on ANY material change
 * to `listing_flags`/`condition` in the two clean branches below — both directions (a
 * flag being SET and a flag being CLEARED can each flip a product's landing-page pick
 * eligibility), not just a fresh `high_price`.
 *
 * `unavailable` (Spec 029, 2026-08-12): when the payload carries the flag WITHOUT an
 * explicit stock_status, the offer's stock_status is coerced to 'out_of_stock' here —
 * the one choke point every ingestion path already routes flags through. An explicit
 * payload stock_status always wins (the extension saw the page; trust it).
 */
class ListingHealthService
{
    /** The product's ONLY/last viable offer just went bad — product is ignored. */
    public const ACTION_FLAGGED_CONDITION = 'flagged_condition';

    /**
     * Fix 2 (2026-08-15): a negative condition landed on ONE offer of a
     * multi-store product that still has another clean, purchasable offer —
     * the offer is flagged but the product stays visible.
     */
    public const ACTION_FLAGGED_OFFER_CONDITION = 'flagged_offer_condition';

    /**
     * @param array<int, string> $listingFlags
     * @param string|null $payloadStockStatus The stock_status the PAYLOAD explicitly
     *   supplied (null when omitted) — NOT the offer's current column value, which by
     *   apply()-time already contains the fallback-preserved previous value.
     * @return string|null {@see ACTION_FLAGGED_CONDITION} or
     *   {@see ACTION_FLAGGED_OFFER_CONDITION} when the caller should override its
     *   response action; null when the base ingestion action stands.
     */
    public function apply(ProductOffer $offer, Product $product, ?string $condition, array $listingFlags, ?string $payloadStockStatus = null): ?string
    {
        if ($condition === null) {
            return null;
        }

        $now = now();

        // Fix 2: captured BEFORE any update() call below mutates the offer's
        // in-memory `condition` attribute — used by the clean branch at the bottom
        // to detect "this offer is recovering from a negative condition" so it can
        // log (never auto-un-ignore) a product that this rule previously flagged.
        $wasNegativeCondition = in_array($offer->condition, ListingHealth::NEGATIVE_CONDITIONS, true);

        // `unavailable` semantics: a listing with nothing to buy IS out of stock. Only
        // when the payload didn't explicitly say otherwise — and never in the 'unknown'
        // branch, where reported flags are untrusted and not stored either.
        $stockCoercion = ($payloadStockStatus === null && in_array('unavailable', $listingFlags, true))
            ? ['stock_status' => 'out_of_stock']
            : [];

        if ($condition === 'unknown') {
            // 029B-B3: 'unknown' = "the extension looked but could not verify the
            // page" — stamp-only. Reported flags off an unverified page are not
            // trusted either; previously stored flags stay exactly as they were.
            $offer->update([
                'condition'         => 'unknown',
                'health_checked_at' => $now,
            ]);

            return null;
        }

        if (in_array($condition, ListingHealth::NEGATIVE_CONDITIONS, true)) {
            $offer->update([
                'condition'         => $condition,
                'listing_flags'     => $listingFlags,
                'health_checked_at' => $now,
                ...$stockCoercion,
            ]);

            // Fix 2: always store the condition on the offer (above); only ignore
            // the PRODUCT when it has no other clean, purchasable offer left. A
            // refurbished Whole Latte Love listing must not delete a product that
            // still has a clean Amazon offer.
            if ($this->hasCleanOffer($product)) {
                return self::ACTION_FLAGGED_OFFER_CONDITION;
            }

            if (!$product->is_ignored) {
                $product->update(['is_ignored' => true]);
                $this->warnIfLandingPagePick($product, $condition);
            }

            return self::ACTION_FLAGGED_CONDITION;
        }

        if (!empty($listingFlags)) {
            $offer->update([
                'condition'         => $condition,
                'listing_flags'     => $listingFlags,
                'health_checked_at' => $now,
                ...$stockCoercion,
            ]);

            // Spec 030 §B3 / S1: `high_price` (or any future flag) doesn't flip
            // is_ignored/category_id, so ProductObserver never sees this change — the
            // instant freshness-audit trigger has to live here instead, at the one
            // place flags are set. Dispatch on ANY material flags/condition change,
            // not just a fresh `high_price` value.
            if ($offer->wasChanged('listing_flags') || $offer->wasChanged('condition')) {
                AuditLandingPageFreshnessJob::dispatchForProduct($product);
            }

            $this->logIfRecoveringWhileIgnored($product, $wasNegativeCondition);

            return null;
        }

        // Clean explicit report ('new', affirmatively verified) — clears listing_flags.
        // 'unknown' never reaches this branch (stamp-only above, 029B-B3), so a clean
        // wipe of flags always reflects a page the extension actually verified.
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

        $this->logIfRecoveringWhileIgnored($product, $wasNegativeCondition);

        return null;
    }

    /**
     * Fix 2 (2026-08-15): a rescan that clears the negative condition which
     * previously drove {@see ACTION_FLAGGED_CONDITION}'s is_ignored=true must NOT
     * silently un-ignore the product — Spec 029's explicit non-goal is that
     * reversal stays a human decision in Filament. This only makes the now-stale
     * is_ignored visible in the logs so the owner can find and review it.
     *
     * Fires whenever THIS offer's condition transitions away from a negative
     * value while the product is (still) ignored — regardless of whether this
     * rule or a human originally set is_ignored, since the two are
     * indistinguishable from the stored data alone. A false-positive log entry
     * (product ignored for an unrelated reason) is harmless noise; a silent
     * miss is not.
     */
    private function logIfRecoveringWhileIgnored(Product $product, bool $wasNegativeCondition): void
    {
        if ($wasNegativeCondition && $product->is_ignored) {
            Log::notice('ListingHealthService: an offer condition cleared but the product remains ignored — reversal is a human decision, review in Filament', [
                'product_id' => $product->id,
            ]);
        }
    }

    /**
     * Fix 2: true if ANY of the product's offers is simultaneously priced
     * (`scraped_price` non-null and > 0), free of a negative condition, and free
     * of a pick-excluding listing flag — i.e. an offer a reader could actually
     * buy today. Queried fresh from the DB (not the possibly stale in-memory
     * `offers` relation some callers eager-load before this offer's own update()
     * above lands) so a just-created/just-refreshed offer is always reflected.
     *
     * Mirrors SelectLandingPagePicks::hasEligibleOffer() /
     * AuditLandingPageFreshness::hasEligibleOffer() — kept as a separate copy
     * here (rather than extracted to ListingHealth) since those two already
     * layer an additional condition-marker/raw_title check this one doesn't need.
     */
    private function hasCleanOffer(Product $product): bool
    {
        return $product->offers()
            ->get(['id', 'scraped_price', 'condition', 'listing_flags'])
            ->contains(
                fn (ProductOffer $o) => $o->scraped_price !== null
                    && (float) $o->scraped_price > 0
                    && !in_array($o->condition, ListingHealth::NEGATIVE_CONDITIONS, true)
                    && array_intersect(ListingHealth::PICK_EXCLUDING_FLAGS, $o->listing_flags ?? []) === []
            );
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
