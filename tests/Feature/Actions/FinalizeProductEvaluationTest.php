<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\FinalizeProductEvaluation;
use App\Enums\FinalizeOutcome;
use App\Models\AiCategoryRejection;
use App\Models\Category;
use App\Models\Feature;
use App\Models\Product;
use App\Models\ProductFeatureValue;
use App\Services\AiService;
use App\Support\ProductEvaluation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Spec 039 T2 — FinalizeProductEvaluation: the two behaviours that are NEW in
 * the extraction. Every branch moved verbatim from ProcessPendingProduct
 * (ignored, scored, name/slug cap, brand fuzzy-match) is covered end to end
 * through ProcessPendingProduct's own suite (ProductNameSlugCapTest,
 * AiUsageJobAttributionTest) — those pass unchanged, proving the move didn't
 * alter behaviour. BrandNormalizationTest and AiMatchProductTest exercise the
 * underlying `AiService` logic directly, NOT this job — they do not run
 * `ProcessPendingProduct::handle()`.
 *
 * Correction (review LOW-6, 2026-08-28): this docblock previously claimed the
 * merge branch (offer transfer, same-store-cheaper rule, `forceDelete`) and
 * the pre-existing-`AiCategoryRejection` branch were "already covered end to
 * end" by the suites above. They were not — no test in this codebase ran the
 * job through either branch. Both are now covered, end to end, by
 * `ProcessPendingProductEvaluationTest::a_gemini_scored_response_merges_into_an_existing_product_transferring_offers_and_deleting_the_duplicate()`
 * and `::a_pre_existing_category_rejection_detaches_the_product_before_any_scored_write()`.
 *
 * This file covers only: the new `wrong_category` outcome, and
 * `applyFeatureScores()` now being a single shared implementation.
 */
class FinalizeProductEvaluationTest extends TestCase
{
    use RefreshDatabase;

    private function action(): FinalizeProductEvaluation
    {
        return new FinalizeProductEvaluation(app(AiService::class));
    }

    // -------------------------------------------------------------------
    // wrong_category
    // -------------------------------------------------------------------

    /** @test */
    public function wrong_category_creates_a_rejection_row_detaches_the_product_and_leaves_is_ignored_untouched(): void
    {
        $category = Category::factory()->create();
        $feature  = Feature::factory()->create(['category_id' => $category->id]);
        $product  = Product::factory()->create([
            'category_id' => $category->id,
            'status'      => 'pending_ai',
            'is_ignored'  => false,
        ]);
        ProductFeatureValue::factory()->create(['product_id' => $product->id, 'feature_id' => $feature->id]);

        $eval = ProductEvaluation::fromArray(['status' => 'ignored', 'reason' => 'wrong_category']);

        $outcome = $this->action()->execute($product, $category, $eval, source: 'gemini');

        $this->assertSame(FinalizeOutcome::RejectedFromCategory, $outcome);

        $this->assertDatabaseHas('ai_category_rejections', [
            'product_id'  => $product->id,
            'category_id' => $category->id,
        ]);

        $product->refresh();
        $this->assertNull($product->category_id, 'product must be detached from the category');
        $this->assertNull($product->status);
        $this->assertFalse($product->is_ignored, 'wrong_category must NOT flip is_ignored — spec §2 T1');

        // Spec 035: the write above must be a model-level save (not a mass
        // Builder::update()) so ProductObserver::saved() fires — it clears
        // feature values that became unreachable once the product left the
        // category. A leftover value here would mean the extraction quietly
        // reintroduced the 2026-08-21 bug.
        $this->assertSame(
            0,
            ProductFeatureValue::where('product_id', $product->id)->count(),
            'a detached product has no reachable category feature — the old value must be cleared'
        );
    }

    /** @test */
    public function wrong_category_does_not_duplicate_the_rejection_row_when_applied_twice(): void
    {
        $category = Category::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);
        $product = Product::factory()->create(['category_id' => $category->id, 'status' => 'pending_ai']);

        $eval = ProductEvaluation::fromArray(['status' => 'ignored', 'reason' => 'wrong_category']);

        $this->action()->execute($product, $category, $eval, source: 'gemini');
        $this->action()->execute($product, $category, $eval, source: 'gemini');

        $this->assertSame(
            1,
            AiCategoryRejection::where('product_id', $product->id)->where('category_id', $category->id)->count()
        );
    }

    // -------------------------------------------------------------------
    // Spec 041 — names, model, generic-brand guard
    // -------------------------------------------------------------------

    /** @param array<string, mixed> $payload */
    private function finalizeScored(Product $product, Category $category, array $payload): void
    {
        Http::fake();

        $eval = ProductEvaluation::fromArray(array_merge([
            'name' => 'x', 'brand' => 'Shure', 'ai_summary' => 'Fine.', 'features' => [],
        ], $payload));

        $this->action()->execute($product, $category, $eval, source: 'gemini');
    }

    private function pendingProduct(Category $category, string $rawTitle): Product
    {
        return Product::factory()->create([
            'category_id' => $category->id,
            'name'        => $rawTitle,
            'status'      => 'pending_ai',
            'is_ignored'  => false,
        ]);
    }

    /** @test */
    public function a_short_ai_name_is_kept_and_never_replaced_by_the_raw_title(): void
    {
        $category = Category::factory()->create();
        $product  = $this->pendingProduct($category, 'Shure MV7+ Podcast Dynamic Microphone with USB-C and XLR Outputs, Black');

        $this->finalizeScored($product, $category, ['name' => 'Shure MV7+', 'model' => 'MV7+']);

        $product->refresh();
        $this->assertSame('Shure MV7+', $product->name);
        $this->assertSame('MV7+', $product->model);
        $this->assertNull($product->status);
    }

    /** @test */
    public function a_brand_only_name_is_rebuilt_from_brand_and_model(): void
    {
        $category = Category::factory()->create();
        $product  = $this->pendingProduct($category, 'Breville Oracle Jet Espresso Machine, Black Truffle');

        $this->finalizeScored($product, $category, ['name' => 'Breville', 'brand' => 'Breville', 'model' => 'Oracle Jet']);

        $this->assertSame('Breville Oracle Jet', $product->refresh()->name);
    }

    /** @test */
    public function a_brand_only_name_without_a_model_is_not_replaced_by_the_raw_title(): void
    {
        $category = Category::factory()->create();
        $product  = $this->pendingProduct($category, 'Breville Oracle Jet Espresso Machine, Black Truffle');

        $this->finalizeScored($product, $category, ['name' => 'Breville', 'brand' => 'Breville']);

        $this->assertSame('Breville', $product->refresh()->name);
    }

    /** @test */
    public function the_name_is_shaped_to_start_with_the_brand(): void
    {
        $category = Category::factory()->create();
        $product  = $this->pendingProduct($category, 'UWP-D Wireless Lavalier Bundle');

        $this->finalizeScored($product, $category, ['name' => 'UWP-D', 'brand' => 'Sony']);

        $this->assertSame('Sony UWP-D', $product->refresh()->name);
    }

    /** @test */
    public function a_scored_payload_without_a_model_keeps_the_stored_model(): void
    {
        $category = Category::factory()->create();
        $product  = $this->pendingProduct($category, 'Rode NT-USB Mini');
        $product->update(['model' => 'NT-USB Mini']);

        $this->finalizeScored($product, $category, ['name' => 'Rode NT-USB Mini', 'brand' => 'Rode']);

        $this->assertSame('NT-USB Mini', $product->refresh()->model);
    }

    /**
     * @test
     * @dataProvider genericBrandProvider
     */
    public function a_generic_brand_is_ignored_whatever_the_ai_returned(string $brand): void
    {
        $category = Category::factory()->create();
        $product  = $this->pendingProduct($category, 'Wireless Lavalier Microphone Kit');

        $this->finalizeScored($product, $category, ['name' => "{$brand} Lavalier", 'brand' => $brand]);

        $product->refresh();
        $this->assertTrue($product->is_ignored);
        $this->assertNull($product->status);
        $this->assertSame(0, \App\Models\Brand::where('name', $brand)->count(), 'no brand row may be created for a placeholder brand');
    }

    /** @return array<string, array{string}> */
    public static function genericBrandProvider(): array
    {
        return ['Generic' => ['Generic'], 'Unbranded' => ['Unbranded'], 'padded + cased' => ['  NO BRAND '], 'Unknown' => ['unknown']];
    }

    // -------------------------------------------------------------------
    // applyFeatureScores — shared by ProcessPendingProduct & RescanProductFeatures
    // -------------------------------------------------------------------

    /** @test */
    public function apply_feature_scores_skips_a_null_entry_and_a_zero_score_identically_and_writes_the_rest(): void
    {
        $category = Category::factory()->create();
        $scored   = Feature::factory()->create(['category_id' => $category->id, 'name' => 'Scored']);
        $zeroed   = Feature::factory()->create(['category_id' => $category->id, 'name' => 'Zeroed']);
        $unscored = Feature::factory()->create(['category_id' => $category->id, 'name' => 'Unscored']);
        $product  = Product::factory()->create(['category_id' => $category->id]);

        $category->load('features');

        $written = FinalizeProductEvaluation::applyFeatureScores($product, $category, [
            'Scored'   => ['score' => 75, 'reason' => 'Good.'],
            'Zeroed'   => ['score' => 0, 'reason' => 'Bad.'],
            'Unscored' => null,
        ]);

        $this->assertSame(1, $written);
        $this->assertDatabaseHas('product_feature_values', [
            'product_id' => $product->id,
            'feature_id' => $scored->id,
            'raw_value'  => 75,
        ]);
        $this->assertDatabaseMissing('product_feature_values', ['product_id' => $product->id, 'feature_id' => $zeroed->id]);
        $this->assertDatabaseMissing('product_feature_values', ['product_id' => $product->id, 'feature_id' => $unscored->id]);
    }

    // -------------------------------------------------------------------
    // Review M3 — truncated free text is WRITTEN, not rejected/failed
    // -------------------------------------------------------------------

    /**
     * An over-length ai_summary (700 chars) or feature reason (400 chars)
     * must never fail a product after three paid Gemini attempts —
     * ProductEvaluation truncates both at their cap; this proves the
     * truncated value survives all the way to the written row.
     */
    /** @test */
    public function an_over_length_ai_summary_and_feature_reason_are_written_truncated_not_failed(): void
    {
        $category = Category::factory()->create();
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'name' => 'Comfort']);
        $product  = Product::factory()->create(['category_id' => $category->id, 'status' => 'pending_ai']);

        $eval = ProductEvaluation::fromArray([
            'name'       => 'Sony WH-1000XM5',
            'brand'      => 'Sony',
            'ai_summary' => str_repeat('a', 700),
            'price_tier' => 2,
            'features'   => ['Comfort' => ['score' => 80, 'reason' => str_repeat('b', 400)]],
        ]);

        $outcome = $this->action()->execute($product, $category, $eval, source: 'gemini');

        $this->assertSame(FinalizeOutcome::Scored, $outcome);

        $product->refresh();
        $this->assertNull($product->status, 'a truncated field must not fail the product');
        $this->assertSame(600, mb_strlen($product->ai_summary));
        $this->assertSame(str_repeat('a', 600), $product->ai_summary);

        $value = ProductFeatureValue::where('product_id', $product->id)->where('feature_id', $feature->id)->sole();
        $this->assertSame(300, mb_strlen($value->explanation));
        $this->assertSame(str_repeat('b', 300), $value->explanation);
    }
}
