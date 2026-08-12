<?php

declare(strict_types=1);

namespace App\Support;

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

    private function __construct() {}
}
