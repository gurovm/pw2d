<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Tenant;
use App\Support\BouncerRules;
use Illuminate\Support\Carbon;

/**
 * Spec 039 T3 — assembles the operator-session export payload for
 * `pw2d:products:export-pending`. Read-only; the caller (the command) is
 * responsible for initializing tenancy and writing the returned array to
 * disk.
 *
 * Two shapes, both documented in the spec:
 *   - A single leaf category (a `category-slug` was given): top-level
 *     `category` / `rules` / `anchors` / `products` keys.
 *   - No slug: every leaf category of the tenant that has at least one
 *     matching product, each wrapped with its OWN `category` / `rules` /
 *     `anchors` / `products` block under a top-level `categories` array
 *     (each category's `rules` text is category-name-dependent — see
 *     {@see \App\Support\BouncerRules::text()} — so it cannot be hoisted to
 *     a single shared top-level field the way `brands` can).
 */
class ExportPendingProducts
{
    /**
     * @param array<int, string> $statuses Raw --status values, e.g.
     *   ['pending_ai', 'failed'] or ['processed'] (the blind-calibration mode).
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException When a given category slug does not
     *   resolve to a leaf category of this tenant.
     */
    public function execute(Tenant $tenant, ?string $categorySlug, array $statuses, ?int $limit, int $anchorsCount): array
    {
        $isProcessed = in_array('processed', $statuses, true);
        $brands      = $this->brandNames();

        if ($categorySlug !== null) {
            $category = Category::where('slug', $categorySlug)->doesntHave('children')->first();

            if (!$category) {
                throw new \InvalidArgumentException("No leaf category found with slug \"{$categorySlug}\" (parent/hub categories are not exportable).");
            }

            $block = $this->buildCategoryBlock($category, $isProcessed, $statuses, $limit, $anchorsCount);

            return [
                'meta'     => $this->meta($tenant, $statuses, $block['count']),
                'category' => $block['category'],
                'rules'    => $block['rules'],
                'brands'   => $brands,
                'anchors'  => $block['anchors'],
                'products' => $block['products'],
            ];
        }

        $leafCategories = Category::doesntHave('children')->orderBy('name')->get();

        $categories = [];
        $totalCount = 0;

        foreach ($leafCategories as $category) {
            $block = $this->buildCategoryBlock($category, $isProcessed, $statuses, $limit, $anchorsCount);

            if ($block['count'] === 0) {
                continue;
            }

            $totalCount += $block['count'];

            $categories[] = [
                'category' => $block['category'],
                'rules'    => $block['rules'],
                'anchors'  => $block['anchors'],
                'products' => $block['products'],
            ];
        }

        return [
            'meta'       => $this->meta($tenant, $statuses, $totalCount),
            'brands'     => $brands,
            'categories' => $categories,
        ];
    }

    /**
     * @return array{category: array, rules: string, anchors: array, products: array, count: int}
     */
    private function buildCategoryBlock(Category $category, bool $isProcessed, array $statuses, ?int $limit, int $anchorsCount): array
    {
        $category->loadMissing('features');

        $productsQuery = $isProcessed
            ? Product::where('category_id', $category->id)->whereNull('status')->where('is_ignored', false)
            : Product::where('category_id', $category->id)->whereIn('status', $statuses)->where('is_ignored', false);

        $productsQuery->with('offers.store')->orderBy('id');

        if ($limit !== null) {
            $productsQuery->limit($limit);
        }

        $products = $productsQuery->get();

        // Blind calibration mode (`--status=processed`, review M3): the
        // anchor set must never include the very products under test, or
        // an evaluator sees the same product's stored scores (as an anchor)
        // next to its own raw_title/price (as a product to score) and the
        // T5 gate is contaminated. Non-blind exports (pending/failed) never
        // overlap with the (always-processed) anchor pool, so exclude
        // nothing there.
        $excludeAnchorIds = $isProcessed ? $products->pluck('id')->all() : [];

        return [
            'category' => [
                'id'           => $category->id,
                'name'         => $category->name,
                'slug'         => $category->slug,
                'budget_max'   => $category->budget_max,
                'midrange_max' => $category->midrange_max,
                'features'     => $category->features->map(fn ($f) => [
                    'name'             => $f->name,
                    'unit'             => $f->unit,
                    'is_higher_better' => $f->is_higher_better,
                ])->values()->all(),
            ],
            'rules'    => BouncerRules::text($category->name) . "\n\n" . BouncerRules::sessionAddendum(),
            'anchors'  => $this->buildAnchors($category, $anchorsCount, $excludeAnchorIds),
            'products' => $products->map(fn (Product $p) => $this->buildProductEntry($p, $category, $isProcessed))->values()->all(),
            'count'    => $products->count(),
        ];
    }

    /**
     * @param array<int, int> $excludeIds Product ids to never surface as an
     *   anchor — the exported (`--status=processed`) product ids themselves,
     *   in blind calibration mode (review M3).
     * @return array<int, array{name: string, brand: ?string, price_tier: ?int, features: array<string, float>}>
     */
    private function buildAnchors(Category $category, int $count, array $excludeIds = []): array
    {
        if ($count <= 0) {
            return [];
        }

        return Product::where('category_id', $category->id)
            ->whereNull('status')
            ->where('is_ignored', false)
            ->whereHas('featureValues')
            ->when($excludeIds !== [], fn ($q) => $q->whereNotIn('id', $excludeIds))
            ->with(['brand', 'featureValues.feature'])
            // Deterministic: highest review count first, id as a stable tiebreak.
            ->orderByDesc('amazon_reviews_count')
            ->orderBy('id')
            ->limit($count)
            ->get()
            ->map(fn (Product $p) => [
                'name'       => $p->name,
                'brand'      => $p->brand?->name,
                'price_tier' => $p->price_tier,
                'features'   => $p->featureValues
                    ->filter(fn ($fv) => $fv->feature !== null)
                    ->mapWithKeys(fn ($fv) => [$fv->feature->name => (float) $fv->raw_value])
                    ->all(),
            ])
            ->values()
            ->all();
    }

    private function buildProductEntry(Product $product, Category $category, bool $isProcessed): array
    {
        $offer = $this->representativeOffer($product);
        $price = $offer?->scraped_price !== null ? (float) $offer->scraped_price : null;

        $entry = [
            'product_id'  => $product->id,
            'raw_title'   => $offer?->raw_title ?? $product->name,
            'price'       => $price,
            'price_note'  => $this->priceNote($category, $price),
            'rating_note' => $this->ratingNote($product),
            'store'       => $offer?->store?->name,
            'url'         => $offer?->url,
        ];

        // Blind calibration export (Spec 039 T3): never reveal the product's
        // current pipeline status — a fresh evaluator has no such signal.
        if (!$isProcessed) {
            $entry['status'] = $product->status;
        }

        return $entry;
    }

    private function representativeOffer(Product $product): ?ProductOffer
    {
        return $product->best_offer ?? $product->offers->sortBy('scraped_price')->first();
    }

    /**
     * Deterministic from price + category thresholds only — never the
     * product's stored `price_tier` column, which (once the AI has scored
     * it) is itself part of what a `--status=processed` export must stay
     * blind to. Mirrors ProcessPendingProduct's price-tier note wording.
     */
    private function priceNote(Category $category, ?float $price): string
    {
        $budgetMax   = $category->budget_max ?? 50;
        $midrangeMax = $category->midrange_max ?? 150;

        return match ($category->priceTierFor($price)) {
            1       => "Budget (under \${$budgetMax})",
            2       => "Mid-range (\${$budgetMax}–\${$midrangeMax})",
            3       => "Premium (over \${$midrangeMax})",
            default => 'unknown price',
        };
    }

    /**
     * amazon_rating / amazon_reviews_count are raw scraped data (populated at
     * import time, not by the AI evaluation — see ProductEvaluation's
     * docblock: "Gemini leaves null; session leaves null"), so this is safe
     * to expose even in the blind `--status=processed` export.
     */
    private function ratingNote(Product $product): string
    {
        return $product->amazon_rating
            ? "{$product->amazon_rating}/5 stars ({$product->amazon_reviews_count} reviews)"
            : 'no rating data available';
    }

    /**
     * @return array<int, string>
     */
    private function brandNames(): array
    {
        // Spec 039 T3: "distinct brand names for the tenant (tenant-scoped
        // query)" — relies on Brand::BelongsToTenant's automatic global
        // scope, which requires the caller (the command) to have already
        // initialized tenancy for the target tenant.
        return Brand::orderBy('name')->pluck('name')->unique()->values()->all();
    }

    private function meta(Tenant $tenant, array $statuses, int $count): array
    {
        return [
            'tenant'        => $tenant->id,
            'exported_at'   => Carbon::now()->toIso8601String(),
            'status_filter' => array_values($statuses),
            'count'         => $count,
        ];
    }
}
