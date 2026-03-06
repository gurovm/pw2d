<?php

namespace App\Services;

use App\Models\Feature;
use App\Models\Product;
use Illuminate\Support\Collection;

class ProductScoringService
{
    /**
     * Score all products at once, pre-computing feature ranges a single time.
     * This is O(N×F) instead of the naive O(N²×F) approach.
     */
    public function scoreAllProducts(
        Collection $products,
        Collection $features,
        array $weights,
        ?float $amazonRatingWeight = 50,
        ?float $priceWeight = 50
    ): Collection {
        // Pre-compute ranges for all features once (not once per product)
        $ranges = [];
        foreach ($features as $feature) {
            $ranges[$feature->id] = $this->calculateFeatureRange($feature, $products);
        }

        return $products->map(function ($product) use ($features, $weights, $amazonRatingWeight, $priceWeight, $ranges) {
            $result = $this->calculateMatchScore($product, $features, [], $weights, $amazonRatingWeight, $priceWeight, $ranges);
            $product->match_score = $result['score'];
            $product->feature_scores = $result['feature_scores'];
            return $product;
        });
    }

    /**
     * Calculate Match Score for a product based on feature weights.
     *
     * @param Product $product
     * @param Collection $features
     * @param Collection $products All products being compared (for dynamic normalization) — pass [] when using pre-computed $ranges
     * @param array $weights Feature weights (feature_id => weight 0-100)
     * @param float|null $amazonRatingWeight Weight for Amazon rating (0-100)
     * @param array $precomputedRanges Optional pre-computed ranges keyed by feature_id
     * @return float Match Score (0-100)
     */
    public function calculateMatchScore(
        Product $product,
        Collection $features,
        Collection|array $products,
        array $weights,
        ?float $amazonRatingWeight = 50,
        ?float $priceWeight = 50,
        array $precomputedRanges = []
    ): array {
        $totalWeightedScore = 0;
        $totalWeight = 0;
        $featureScores = [];

        // Calculate scores for each feature
        foreach ($features as $feature) {
            $weight = $weights[$feature->id] ?? 50; // Default weight: 50

            // Get the product's raw value for this feature
            $featureValue = $product->featureValues
                ->where('feature_id', $feature->id)
                ->first();

            if (!$featureValue) {
                continue; // Skip if product doesn't have this feature
            }

            // Use pre-computed range if available, otherwise compute dynamically
            if (isset($precomputedRanges[$feature->id])) {
                $range = $precomputedRanges[$feature->id];
                $normalizedScore = $this->normalizeWithRange($featureValue->raw_value, $feature, $range['min'], $range['max']);
            } else {
                $normalizedScore = $this->normalizeFeatureValue($featureValue->raw_value, $feature, collect($products));
            }
            $featureScores[$feature->id] = round($normalizedScore, 1);

            // Apply weight
            $totalWeightedScore += $normalizedScore * $weight;
            $totalWeight += $weight;
        }

        // Add Amazon Rating (virtual feature)
        if ($product->amazon_rating && $amazonRatingWeight > 0) {
            // Amazon rating is already 0-5, normalize to 0-100
            $normalizedAmazonScore = ($product->amazon_rating / 5) * 100;
            $totalWeightedScore += $normalizedAmazonScore * $amazonRatingWeight;
            $totalWeight += $amazonRatingWeight;
        }

        // Add Price Score (virtual feature)
        // Logic: Lower price tier is better (1=Budget, 2=Mid, 3=Premium)
        if ($product->price_tier && $priceWeight > 0) {
            // Map tiers to scores: 1->100, 2->50, 3->0
            $priceScore = match((int)$product->price_tier) {
                1 => 100,
                2 => 50,
                3 => 0,
                default => 50,
            };
            
            $totalWeightedScore += $priceScore * $priceWeight;
            $totalWeight += $priceWeight;
        }

        // Calculate final Match Score
        return [
            'score' => $totalWeight === 0 ? 0 : round(($totalWeightedScore / $totalWeight), 1),
            'feature_scores' => $featureScores
        ];
    }

    /**
     * Normalize a feature value to 0-100 scale using dynamic min/max from products.
     */
    protected function normalizeFeatureValue(float $rawValue, Feature $feature, Collection $products): float
    {
        $range = $this->calculateFeatureRange($feature, $products);
        return $this->normalizeWithRange($rawValue, $feature, $range['min'], $range['max']);
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

    /**
     * Calculate min and max values for a feature across all products in a category.
     *
     * @param Feature $feature
     * @param Collection $products
     * @return array ['min' => float, 'max' => float]
     */
    public function calculateFeatureRange(Feature $feature, Collection $products): array
    {
        // If the feature has no unit, it's an AI-generated subjective score (0-100)
        // Hardcode the bounds so the visual bars match the actual 0-100 value exactly.
        if (empty($feature->unit)) {
            return ['min' => 0, 'max' => 100];
        }

        $values = $products->flatMap(function ($product) use ($feature) {
            return $product->featureValues
                ->where('feature_id', $feature->id)
                ->pluck('raw_value');
        })->filter(function($val) {
            return $val !== null && $val !== '';
        });

        if ($values->isEmpty()) {
            return ['min' => 0, 'max' => 100];
        }

        return [
            'min' => (float) $values->min(),
            'max' => (float) $values->max(),
        ];
    }

    /**
     * Auto-update feature min/max values based on actual product data.
     * NOTE: This method is deprecated as normalization is now fully dynamic.
     *
     * @param Feature $feature
     * @param Collection $products
     * @return void
     */
    public function updateFeatureRange(Feature $feature, Collection $products): void
    {
        $range = $this->calculateFeatureRange($feature, $products);
        
        $feature->update([
            'min_value' => $range['min'],
            'max_value' => $range['max'],
        ]);
    }
}
