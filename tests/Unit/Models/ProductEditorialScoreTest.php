<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Category;
use App\Models\Feature;
use App\Models\Product;
use App\Models\ProductFeatureValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins Product::editorialScore() (Spec 042): mean of raw_value over ALL category
 * features, /10, one decimal; null unless every feature is scored.
 */
class ProductEditorialScoreTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<int, float> $rawValues index-aligned with the created features */
    private function makeProduct(int $featureCount, array $rawValues): Product
    {
        $category = Category::factory()->create();
        $features = Feature::factory()->count($featureCount)->create(['category_id' => $category->id]);
        $product  = Product::factory()->create(['category_id' => $category->id]);

        foreach ($features as $i => $feature) {
            if (array_key_exists($i, $rawValues)) {
                ProductFeatureValue::factory()->create([
                    'product_id' => $product->id,
                    'feature_id' => $feature->id,
                    'raw_value'  => $rawValues[$i],
                ]);
            }
        }

        return $product->load('category.features', 'featureValues');
    }

    public function test_returns_mean_divided_by_ten_rounded_to_one_decimal(): void
    {
        // mean 68.333.. -> 6.8333.. -> 6.8
        $this->assertSame(6.8, $this->makeProduct(3, [60.0, 70.0, 75.0])->editorialScore());
    }

    public function test_rounds_half_up_at_one_decimal(): void
    {
        // mean 68.5 -> 6.85 -> 6.9
        $this->assertSame(6.9, $this->makeProduct(2, [68.0, 69.0])->editorialScore());
        // exact tenth stays put
        $this->assertSame(7.0, $this->makeProduct(2, [70.0, 70.0])->editorialScore());
    }

    public function test_null_when_any_category_feature_is_unscored(): void
    {
        $this->assertNull($this->makeProduct(3, [60.0, 70.0])->editorialScore());
    }

    public function test_null_when_no_feature_is_scored_or_category_has_no_features(): void
    {
        $this->assertNull($this->makeProduct(3, [])->editorialScore());
        $this->assertNull($this->makeProduct(0, [])->editorialScore());
    }

    public function test_ignores_values_for_features_outside_the_category(): void
    {
        $product = $this->makeProduct(2, [80.0, 60.0]);
        $other   = Feature::factory()->create();
        ProductFeatureValue::factory()->create([
            'product_id' => $product->id,
            'feature_id' => $other->id,
            'raw_value'  => 1.0,
        ]);
        $product->load('featureValues');

        $this->assertSame(7.0, $product->editorialScore());
    }

    public function test_accepts_explicit_category_feature_ids_without_loading_category(): void
    {
        $product = $this->makeProduct(2, [80.0, 60.0]);
        $ids     = $product->category->features->pluck('id')->all();
        $fresh   = Product::find($product->id)->load('featureValues');

        $this->assertSame(7.0, $fresh->editorialScore($ids));
    }
}
