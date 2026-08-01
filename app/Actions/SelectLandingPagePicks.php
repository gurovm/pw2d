<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Category;
use App\Models\Feature;
use App\Models\Product;
use App\Services\ProductScoringService;
use App\Support\ProductConditionGuard;
use Illuminate\Support\Str;

/**
 * Deterministic pick selection for a "Best X" landing page (Spec 027 §4).
 *
 * Picks are chosen entirely by data — the AI (AiService::generateLandingPageContent)
 * only writes prose about the picks selected here. Keeps the "data-driven" claim
 * honest and the page regenerable.
 */
class SelectLandingPagePicks
{
    private const MAX_PICKS = 7;
    private const MIN_PICKS = 5;

    /**
     * @return list<array{product_id: int, role: string}>
     *   role ∈ overall/budget/premium/preset:{slug}. Fill-in picks (beyond the four
     *   named roles) reuse role "overall" — they are simply the next-highest entries
     *   in the same default-weighted ranking used for the #1 "Best Overall" pick.
     *
     * @throws \RuntimeException When fewer than MIN_PICKS eligible products exist.
     */
    public function execute(Category $category): array
    {
        $features = Feature::where('category_id', $category->id)->get();

        // Eligibility gate (Spec 027 §4): processed, not ignored, category attached
        // (implicit via the category_id scope below), image + ai_summary present.
        $products = Product::where('category_id', $category->id)
            ->where('is_ignored', false)
            ->whereNull('status')
            ->whereNotNull('ai_summary')
            ->with([
                'featureValues:id,product_id,feature_id,raw_value',
                'offers:id,product_id,image_url,raw_title',
            ])
            ->get()
            // image_url is a computed accessor (local path → best offer → any offer);
            // reuses the same resolution chain the rest of the site uses to decide
            // whether a product actually has a displayable image.
            ->filter(fn (Product $p) => filled($p->image_url))
            // Addendum A §2a: exclude condition-marked products (renewed/refurbished/
            // open box/pre-owned/used) — checked against every offer's raw_title AND
            // the product's own ai_summary (an earlier AI pass can itself fabricate a
            // condition claim in prose, e.g. "even if renewed" — see builder memory).
            ->reject(fn (Product $p) => self::hasConditionMarker($p))
            ->values();

        if ($products->isEmpty()) {
            throw new \RuntimeException(
                "Category \"{$category->name}\" has no eligible products for a landing page "
                . '(need: fully processed, not ignored, image + ai_summary present).'
            );
        }

        // "Best Overall" scoring path: identical to ProductCompare's default-weight
        // scoring (ProductScoringService::scoreAllProducts, all weights = 50/neutral).
        $defaultWeights = $features->mapWithKeys(fn (Feature $f) => [$f->id => 50])->toArray();

        $scored = (new ProductScoringService())
            ->scoreAllProducts($products, $features, $defaultWeights, amazonRatingWeight: 50, priceWeight: 50)
            ->sortByDesc('match_score')
            ->values();

        $picks     = [];
        $pickedIds = [];

        // Addendum A §2b: reject a candidate whose normalized name is a near-duplicate
        // of an already-picked product (e.g. "Keychron Q6 Max Black" vs "Keychron Q6
        // Max - Black" as two separate, un-merged Product rows). Checked at every
        // pick site below so the NEXT-best candidate is tried instead of leaving the
        // role/slot empty.
        $isDuplicateOfPicked = function (Product $candidate) use (&$pickedIds, $products): bool {
            $candidateNorm = self::normalizeName($candidate->name);

            if ($candidateNorm === '') {
                return false;
            }

            foreach ($pickedIds as $pickedId) {
                $picked = $products->firstWhere('id', $pickedId);

                if ($picked === null) {
                    continue;
                }

                $pickedNorm = self::normalizeName($picked->name);

                if ($pickedNorm === '') {
                    continue;
                }

                if (str_contains($candidateNorm, $pickedNorm) || str_contains($pickedNorm, $candidateNorm)) {
                    return true;
                }

                similar_text($candidateNorm, $pickedNorm, $percent);

                if ($percent >= 85.0) {
                    return true;
                }
            }

            return false;
        };

        $addPick = function (?Product $product, string $role) use (&$picks, &$pickedIds, $isDuplicateOfPicked): bool {
            if ($product === null || in_array($product->id, $pickedIds, true) || $isDuplicateOfPicked($product)) {
                return false;
            }

            $picks[]     = ['product_id' => $product->id, 'role' => $role];
            $pickedIds[] = $product->id;

            return true;
        };

        // Best Overall — the single highest default-weighted score.
        $addPick($scored->first(fn (Product $p) => !in_array($p->id, $pickedIds, true) && !$isDuplicateOfPicked($p)), 'overall');

        // Best Budget / Best Premium — top-scored within price_tier 1 / 3.
        $addPick(
            $scored->first(fn (Product $p) => (int) $p->price_tier === 1
                && !in_array($p->id, $pickedIds, true)
                && !$isDuplicateOfPicked($p)),
            'budget',
        );
        $addPick(
            $scored->first(fn (Product $p) => (int) $p->price_tier === 3
                && !in_array($p->id, $pickedIds, true)
                && !$isDuplicateOfPicked($p)),
            'premium',
        );

        // Best for {preset} — top-scored under that preset's weights, for the
        // category's top presets (by sort_order, max 3), skipping already-picked products.
        // Scoring mirrors AiService::generatePresetContent's established preset-ranking
        // approach: sum of (feature raw_value * pivot weight) over the preset's weighted
        // features only (unlisted features contribute nothing — no neutral default).
        $presets = $category->presets()->with('presetFeatures')->orderBy('sort_order')->take(3)->get();

        foreach ($presets as $preset) {
            $weightMap = $preset->presetFeatures->pluck('weight', 'feature_id')->toArray();

            if (empty($weightMap)) {
                continue;
            }

            $best = $products
                ->reject(fn (Product $p) => in_array($p->id, $pickedIds, true))
                ->map(fn (Product $p) => [
                    'product' => $p,
                    'score'   => $p->featureValues
                        ->filter(fn ($fv) => isset($weightMap[$fv->feature_id]))
                        ->sum(fn ($fv) => (float) $fv->raw_value * (float) $weightMap[$fv->feature_id]),
                ])
                ->sortByDesc('score')
                ->first(fn (array $entry) => !$isDuplicateOfPicked($entry['product']));

            if ($best !== null) {
                $addPick($best['product'], 'preset:' . Str::slug($preset->name));
            }
        }

        // Fill remaining slots (to MAX_PICKS) with the next-highest overall scores.
        foreach ($scored as $product) {
            if (count($picks) >= self::MAX_PICKS) {
                break;
            }
            $addPick($product, 'overall');
        }

        if (count($picks) < self::MIN_PICKS) {
            throw new \RuntimeException(sprintf(
                'Category "%s" is not ready for a landing page: only %d eligible pick(s) found (minimum %d required).',
                $category->name,
                count($picks),
                self::MIN_PICKS,
            ));
        }

        return $picks;
    }

    /**
     * Addendum A §2a: true if any offer's raw_title or the product's own ai_summary
     * matches a condition marker (renewed/refurbished/open box/pre-owned/used).
     */
    private static function hasConditionMarker(Product $product): bool
    {
        if (ProductConditionGuard::matchesSummary($product->ai_summary)) {
            return true;
        }

        foreach ($product->offers as $offer) {
            if (ProductConditionGuard::matchesTitle($offer->raw_title)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lowercase, strip everything but alphanumerics — the normalization Addendum A §2b's
     * duplicate guard compares ("Keychron Q6 Max Black" vs "Keychron Q6 Max - Black" both
     * normalize to "keychronq6maxblack").
     */
    private static function normalizeName(?string $name): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower((string) $name)) ?? '';
    }
}
