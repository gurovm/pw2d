<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Product;
use App\Support\ListingHealth;
use App\Support\ModelIdentity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Spec 043 Phase 1 — the closest rivals of a product for the "Compared with" block
 * and the natural pairs for VS pages.
 *
 * Same category, live, at least one pick-eligible offer, not the same model, and an
 * estimated price within +/-40% of the product's. Nearest price first, ties broken by
 * editorial score (higher first). Estimated price is a computed attribute (best offer),
 * so the price window and ordering run in PHP over the category pool; the resulting
 * ID list is cached 24h per tenant + product.
 */
final class SelectRivals
{
    private const PRICE_WINDOW = 0.40;
    private const CACHE_TTL    = 86400;

    /**
     * @return Collection<int, Product> 0-3 rivals, nearest price first, relations loaded
     *                                  (brand, offers.store, featureValues, category.features)
     */
    public function handle(Product $product, int $limit = 3): Collection
    {
        if ($product->category_id === null) {
            return collect();
        }

        $ids = Cache::remember(
            tenant_cache_key("rivals:{$product->id}:{$limit}"),
            self::CACHE_TTL,
            fn () => $this->selectIds($product, $limit),
        );

        if ($ids === []) {
            return collect();
        }

        // Re-check liveness on the way out: the id list may be up to 24h old.
        $rivals = $this->liveQuery($product)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        return collect($ids)->map(fn (int $id) => $rivals->get($id))->filter()->values();
    }

    /**
     * @return list<int>
     */
    private function selectIds(Product $product, int $limit): array
    {
        $price = $product->estimated_price;

        if ($price === null || $price <= 0) {
            return [];
        }

        $product->loadMissing('brand:id,name');

        $min = $price * (1 - self::PRICE_WINDOW);
        $max = $price * (1 + self::PRICE_WINDOW);

        $candidates = $this->liveQuery($product)
            ->where('id', '!=', $product->id)
            ->get()
            ->filter(fn (Product $p) => $p->offers->contains(fn ($o) => ListingHealth::isPickEligible($o)))
            ->filter(function (Product $p) use ($min, $max) {
                $est = $p->estimated_price;

                return $est !== null && $est >= $min && $est <= $max;
            })
            ->reject(fn (Product $p) => ModelIdentity::sameModel($product, $p));

        $featureIds = $product->category?->features()->pluck('id')->all() ?? [];

        return $candidates
            ->sortBy([
                fn (Product $a, Product $b) => abs($a->estimated_price - $price) <=> abs($b->estimated_price - $price),
                fn (Product $a, Product $b) => ($b->editorialScore($featureIds) ?? -1.0) <=> ($a->editorialScore($featureIds) ?? -1.0),
            ])
            ->take($limit)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function liveQuery(Product $product)
    {
        return Product::query()
            ->where('category_id', $product->category_id)
            ->where('is_ignored', false)
            ->whereNull('status')
            ->with([
                'brand:id,name',
                'offers.store',
                'featureValues:id,product_id,feature_id,raw_value',
                'category.features',
            ]);
    }
}
