<?php

namespace App\Services;

use App\Models\Feature;
use Illuminate\Support\Collection;

class ProductScoringService
{
    /**
     * Score all products at once using O(1) hash lookups instead of O(N) collection scans.
     *
     * Key optimizations:
     * 1. Build a [product_id][feature_id] => featureValue map once upfront
     * 2. Pre-compute feature ranges using that map (not collection->where() per product)
     * 3. Score each product via direct array access, not Collection::where()
     */
    public function scoreAllProducts(
        Collection $products,
        Collection $features,
        array $weights,
        ?float $amazonRatingWeight = 50,
        ?float $priceWeight = 50
    ): Collection {
        // Build lookup map once: O(N×F) time, then O(1) access per lookup
        $fvMap = [];
        foreach ($products as $product) {
            foreach ($product->featureValues as $fv) {
                $fvMap[$product->id][$fv->feature_id] = $fv;
            }
        }

        // Pre-compute feature ranges using the map (avoids Collection::where() per product)
        $ranges = [];
        foreach ($features as $feature) {
            if (empty($feature->unit)) {
                $ranges[$feature->id] = ['min' => 0, 'max' => 100];
                continue;
            }
            $values = [];
            foreach ($products as $product) {
                $fv = $fvMap[$product->id][$feature->id] ?? null;
                if ($fv !== null && $fv->raw_value !== null) {
                    $values[] = (float) $fv->raw_value;
                }
            }
            $ranges[$feature->id] = empty($values)
                ? ['min' => 0, 'max' => 100]
                : ['min' => min($values), 'max' => max($values)];
        }

        return $products->map(function ($product) use ($features, $weights, $amazonRatingWeight, $priceWeight, $ranges, $fvMap) {
            $totalWeightedScore = 0;
            $totalWeight = 0;
            $featureScores = [];

            foreach ($features as $feature) {
                $fv = $fvMap[$product->id][$feature->id] ?? null;
                if ($fv === null) continue;

                $weight = $weights[$feature->id] ?? 50;
                $range = $ranges[$feature->id];
                $score = $this->normalizeWithRange((float) $fv->raw_value, $feature, $range['min'], $range['max']);
                $featureScores[$feature->id] = round($score, 1);
                $totalWeightedScore += $score * $weight;
                $totalWeight += $weight;
            }

            if ($product->amazon_rating && $amazonRatingWeight > 0) {
                $totalWeightedScore += ($product->amazon_rating / 5 * 100) * $amazonRatingWeight;
                $totalWeight += $amazonRatingWeight;
            }

            if ($product->price_tier && $priceWeight > 0) {
                $priceScore = match((int)$product->price_tier) { 1 => 100, 2 => 50, 3 => 0, default => 50 };
                $totalWeightedScore += $priceScore * $priceWeight;
                $totalWeight += $priceWeight;
            }

            $product->match_score = $totalWeight === 0 ? 0 : round($totalWeightedScore / $totalWeight, 1);
            $product->feature_scores = $featureScores;
            return $product;
        });
    }

    /**
     * Normalize a value given pre-computed min/max bounds.
     */
    protected function normalizeWithRange(float $rawValue, Feature $feature, float $min, float $max): float
    {
        if ($max === $min) {
            return 50;
        }

        $clampedValue = max($min, min($max, $rawValue));

        if ($feature->is_higher_better) {
            return (($clampedValue - $min) / ($max - $min)) * 100;
        } else {
            return (($max - $clampedValue) / ($max - $min)) * 100;
        }
    }
}
