<?php

namespace App\View\Components;

use App\Models\Product;
use App\Support\ListingHealth;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\Component;

class SimilarProducts extends Component
{
    public Collection $similar;

    public function __construct(Product $product)
    {
        // Cache per product for 7 days — "static random" effect:
        // links are randomised once (distributing PageRank evenly) then frozen
        // so Googlebot always sees stable, persistent internal links.
        //
        // Owner decision (2026-08-16, "hide unbuyable products"): cache key bumped
        // to v2 — the query below now excludes products with no purchasable offer
        // (same CTA-grid problem as the compare page's product cards), so a pre-fix
        // v1 entry (possibly holding an unbuyable pick) must never be served
        // post-deploy.
        $this->similar = Cache::remember(
            tenant_cache_key('similar_products_v2_' . $product->id),
            now()->addDays(7),
            function () use ($product) {
                // Priority 1: same category + same price tier
                $sameTier = Product::where('category_id', $product->category_id)
                    ->where('id', '!=', $product->id)
                    ->where('price_tier', $product->price_tier)
                    ->whereNull('status')
                    ->where('is_ignored', false)
                    // Same reasoning as ProductCompare::scoredProducts(): this card
                    // grid renders a "Check Price" CTA per item — a product with no
                    // purchasable offer would show a card with the button silently
                    // missing.
                    ->whereHas('offers', fn ($q) => ListingHealth::applyPurchasableOfferQuery($q))
                    ->with(['brand', 'offers.store'])
                    ->inRandomOrder()
                    ->limit(4)
                    ->get();

                $needed = 4 - $sameTier->count();

                if ($needed > 0) {
                    // Priority 2: fill remaining slots from other tiers
                    $fill = Product::where('category_id', $product->category_id)
                        ->where('id', '!=', $product->id)
                        ->where('price_tier', '!=', $product->price_tier)
                        ->whereNull('status')
                        ->where('is_ignored', false)
                        ->whereNotIn('id', $sameTier->pluck('id'))
                        ->whereHas('offers', fn ($q) => ListingHealth::applyPurchasableOfferQuery($q))
                        ->with(['brand', 'offers.store'])
                        ->inRandomOrder()
                        ->limit($needed)
                        ->get();

                    return $sameTier->concat($fill);
                }

                return $sameTier;
            }
        );
    }

    public function render()
    {
        return view('components.similar-products');
    }
}
