<?php

namespace App\Services;

use App\Models\Feature;
use App\Models\Product;
use Illuminate\Support\Collection;

class ProductScoringService
{
    /**
     * Calculate Match Score for a product based on feature weights.
     *
     * @param Product $product
     * @param Collection $features
     * @param Collection $products All products being compared (for dynamic normalization)
     * @param array $weights Feature weights (feature_id => weight 0-100)
     * @param float|null $amazonRatingWeight Weight for Amazon rating (0-100)
     * @return float Match Score (0-100)
     */
    public function calculateMatchScore(
        Product $product,
        Collection $features,
        Collection $products,
        array $weights,
        ?float $amazonRatingWeight = 50,
        ?float $priceWeight = 50
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

            // Normalize the raw value to 0-100 scale (dynamically)
            $normalizedScore = $this->normalizeFeatureValue(
                $featureValue->raw_value,
                $feature,
                $products
            );
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
     *
     * @param float $rawValue
     * @param Feature $feature
     * @param Collection $products All products being compared
     * @return float Normalized score (0-100)
     */
    protected function normalizeFeatureValue(float $rawValue, Feature $feature, Collection $products): float
    {
        // Dynamically calculate min and max from actual product data
        $range = $this->calculateFeatureRange($feature, $products);
        $min = $range['min'];
        $max = $range['max'];

        // Edge case: if all products have the same value (or only one product)
        // Return 50 to avoid division by zero
        if ($max === $min) {
            return 50;
        }

        // Clamp value to min-max range
        $clampedValue = max($min, min($max, $rawValue));

        if ($feature->is_higher_better) {
            // Higher is better: direct normalization
            // Formula: (value - min) / (max - min) * 100
            return (($clampedValue - $min) / ($max - $min)) * 100;
        } else {
            // Lower is better: inverse normalization
            // Formula: (max - value) / (max - min) * 100
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
