<?php

namespace App\View\Components;

use App\Models\Product;
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
        $this->similar = Cache::remember(
            tenant_cache_key('similar_products_' . $product->id),
            now()->addDays(7),
            function () use ($product) {
                // Priority 1: same category + same price tier
                $sameTier = Product::where('category_id', $product->category_id)
                    ->where('id', '!=', $product->id)
                    ->where('price_tier', $product->price_tier)
                    ->whereNull('status')
                    ->where('is_ignored', false)
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
