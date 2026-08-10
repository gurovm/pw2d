<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\Product;

/**
 * Recalculates `products.price_tier` from each category's `budget_max`/`midrange_max`
 * thresholds. Extracted from `products:recalculate-tiers` (Spec 029 §A4 / Q13) so the
 * same chunked, memory-safe logic is reusable from both the console command and
 * {@see \App\Jobs\RecalculateCategoryPriceTiers}, instead of loading every product+offer
 * for a category into memory via an unbounded `->get()`.
 */
class PriceTierRecalculator
{
    private const CHUNK_SIZE = 200;

    /**
     * @param callable(Product, int|null, int): void|null $onUpdate Called with
     *   (product, old_tier, new_tier) every time a product's tier actually changes —
     *   lets the console command print progress without this class knowing about I/O.
     * @return array{fixed: int, skipped: int}
     */
    public function recalculateForCategory(Category $category, ?callable $onUpdate = null): array
    {
        $fixed   = 0;
        $skipped = 0;

        if ($category->budget_max === null || $category->midrange_max === null) {
            return ['fixed' => 0, 'skipped' => 0];
        }

        Product::where('category_id', $category->id)
            ->with(['offers:id,product_id,scraped_price'])
            ->chunkById(self::CHUNK_SIZE, function ($products) use ($category, $onUpdate, &$fixed, &$skipped) {
                foreach ($products as $product) {
                    $bestPrice = $product->best_price;

                    if ($bestPrice === null) {
                        continue;
                    }

                    $correct = $category->priceTierFor((float) $bestPrice);

                    if ($correct === null) {
                        $skipped++;
                        continue;
                    }

                    if ($correct === $product->price_tier) {
                        continue;
                    }

                    $old = $product->price_tier;
                    $product->update(['price_tier' => $correct]);
                    $fixed++;

                    if ($onUpdate) {
                        $onUpdate($product, $old, $correct);
                    }
                }
            });

        return ['fixed' => $fixed, 'skipped' => $skipped];
    }
}
