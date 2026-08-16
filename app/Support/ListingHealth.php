<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ProductOffer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single shared definition of Spec 029 §A2 listing-health vocabulary — the DOM-detected
 * `condition` + `listing_flags` values every ingestion path (extension ingest-offer,
 * batch-import, product-import) validates against and {@see \App\Services\ListingHealthService}
 * acts on.
 *
 * Distinct from {@see ProductConditionGuard}, which does text-marker matching against
 * scraped titles/AI prose (a weaker, server-side-only signal). `condition`/`listing_flags`
 * are a stronger signal reported by the extension after inspecting the live Amazon DOM.
 */
final class ListingHealth
{
    /** All valid values for `product_offers.condition`. */
    public const CONDITIONS = ['new', 'renewed', 'refurbished', 'open_box', 'used', 'unknown'];

    /** Conditions that mean "don't sell this listing" — the PRODUCT gets ignored. */
    public const NEGATIVE_CONDITIONS = ['renewed', 'refurbished', 'open_box', 'used'];

    /**
     * Recognized `listing_flags` entries.
     * - `high_price`: Amazon's own buy-box "High price" warning — today's listing is a bad deal.
     * - `unavailable`: the buy-box states "Currently unavailable" — nothing to buy today.
     * Both are offer-level, point-in-time listing state (the PRODUCT stays visible) and
     * clear on a clean rescan.
     */
    public const RECOGNIZED_FLAGS = ['high_price', 'unavailable'];

    /**
     * Flags that make a product's BEST offer ineligible as a landing-page pick
     * (SelectLandingPagePicks exclusion + AuditLandingPageFreshness `pick_ineligible`).
     * Kept separate from RECOGNIZED_FLAGS on purpose: a future flag may be worth
     * storing without disqualifying picks.
     */
    public const PICK_EXCLUDING_FLAGS = ['high_price', 'unavailable'];

    /**
     * Columns any narrow `offers:col1,col2,...` eager-load allowlist MUST include
     * whenever the caller reads ANY `best_offer`/`best_price`/`affiliate_url`/
     * `estimated_price` derivative (2026-08-16 audit S2/M2/M1) — an unselected
     * column silently resolves as `null` and looks clean to {@see isPurchasable()},
     * not an error. `grep -rn "offers:id,product_id" app/` finds every narrow
     * offers-select in this codebase; check each one against this list whenever
     * a new health column is added.
     */
    public const OFFER_HEALTH_COLUMNS = ['condition', 'listing_flags'];

    private function __construct() {}

    /**
     * The single shared "is this offer purchasable" predicate (2026-08-16 audit
     * S2) — replaces four independent copies that had drifted to three different
     * definitions (`Product::bestOffer`, `ListingHealthService::hasCleanOffer`,
     * `SelectLandingPagePicks::hasEligibleOffer`, `AuditLandingPageFreshness::
     * hasEligibleOffer`). An offer is purchasable when it is priced, free of a
     * negative condition, free of a pick-excluding flag, and — when the `store`
     * relation happens to be loaded — belongs to an active store.
     *
     * `$offer->store` is deliberately READ, never eager-loaded here: every
     * caller controls its own eager-load shape (Perf H2), so when `store_id`
     * isn't in the caller's column allowlist, Eloquent returns `null` WITHOUT a
     * query (verified in the 2026-08-16 perf audit) and the is_active check is
     * simply skipped — "unknown store state" is treated as active, never as a
     * reason to exclude. Callers that need the is_active check to actually bite
     * must eager-load `offers.store` themselves (mirrors how the condition/flags
     * columns work: the predicate is only as strict as what was loaded).
     *
     * @param bool $requirePositivePrice S3 fix: every caller of this predicate
     *   requires `scraped_price > 0`, not just non-null — a `$0.00` offer (valid
     *   per every ingestion endpoint's `min:0` rule) must never win "best offer".
     *   Parameter kept (rather than hardcoded) in case a future caller genuinely
     *   needs the looser null-only check; none does today.
     */
    public static function isPurchasable(ProductOffer $offer, bool $requirePositivePrice = true): bool
    {
        if ($offer->scraped_price === null) {
            return false;
        }

        if ($requirePositivePrice && (float) $offer->scraped_price <= 0) {
            return false;
        }

        if (in_array($offer->condition, self::NEGATIVE_CONDITIONS, true)) {
            return false;
        }

        if (array_intersect(self::PICK_EXCLUDING_FLAGS, $offer->listing_flags ?? []) !== []) {
            return false;
        }

        if ($offer->store !== null && !$offer->store->is_active) {
            return false;
        }

        return true;
    }

    /**
     * SQL-level twin of {@see isPurchasable()} — mirrors its three offer-level
     * conditions (priced, condition not negative, no pick-excluding flag) as
     * query constraints so a "does this product have ANY purchasable offer"
     * check can run as a `whereHas('offers', ...)` instead of loading every
     * offer into PHP first. Deliberately does NOT reproduce the `store.is_active`
     * check (a join the callers of this predicate haven't needed) — kept as a
     * pure three-condition mirror per the 2026-08-16 "hide unbuyable products"
     * task; a caller needing the store check can eager-load `offers.store`
     * and filter in PHP as `Product::bestOffer` already does.
     *
     * `listing_flags` NULL-safety: `whereJsonDoesntContain()` compiles to
     * `NOT (JSON_CONTAINS(...))` on MySQL, which evaluates to NULL (excluded
     * by WHERE) when the column itself is NULL — so a clean, never-flagged
     * offer (`listing_flags IS NULL`) would be wrongly excluded without the
     * explicit `whereNull('listing_flags')->orWhere(...)` wrapper below.
     * Verified against both the MySQL and SQLite (json_each) grammars.
     *
     * @param  Builder<ProductOffer> $query  A query already scoped to the
     *   `product_offers` table (e.g. the closure passed to `whereHas('offers', ...)`).
     * @return Builder<ProductOffer> The same builder, for chaining.
     */
    public static function applyPurchasableOfferQuery(Builder $query): Builder
    {
        return $query
            ->where('scraped_price', '>', 0)
            ->where(fn (Builder $cq) => $cq
                ->whereNull('condition')
                ->orWhereNotIn('condition', self::NEGATIVE_CONDITIONS))
            ->where(fn (Builder $fq) => $fq
                ->whereNull('listing_flags')
                ->orWhere(function (Builder $sub) {
                    foreach (self::PICK_EXCLUDING_FLAGS as $flag) {
                        $sub->whereJsonDoesntContain('listing_flags', $flag);
                    }
                }));
    }
}
