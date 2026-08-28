<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\CompareProductEvaluations;
use App\Models\AiCategoryRejection;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Feature;
use App\Models\Product;
use App\Models\ProductFeatureValue;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Spec 039 §2 T5 — CompareProductEvaluations: the read-only diff core behind
 * `pw2d:ai:eval-model --from-file`. Every number asserted below is hand-computed
 * in the test's own docblock — see docs/specs/039-bouncer-in-session.md §2 T5.
 */
class CompareProductEvaluationsTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Category $category;
    protected Feature $batteryLife;
    protected Feature $buildQuality;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'cmp-tenant', 'name' => 'Compare Tenant']);
        $this->tenant = Tenant::find('cmp-tenant');
        tenancy()->initialize($this->tenant);

        $this->category = Category::factory()->create(['slug' => 'cmp-cat']);
        $this->batteryLife  = Feature::factory()->create(['category_id' => $this->category->id, 'name' => 'Battery Life']);
        $this->buildQuality = Feature::factory()->create(['category_id' => $this->category->id, 'name' => 'Build Quality']);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    private function action(): CompareProductEvaluations
    {
        return new CompareProductEvaluations();
    }

    private function processedProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'category_id' => $this->category->id,
            'name'        => 'Stored Product',
            'slug'        => 'cmp-' . Str::random(8),
            'status'      => null,
            'is_ignored'  => false,
        ], $overrides));
    }

    // =========================================================================
    // Hand-computable 3-product fixture (spec §2 T5 worked example)
    // =========================================================================

    /**
     * Fixture:
     *  - A: stored is_ignored=false, brand "Sony", stored "Battery Life"=70.
     *       Candidate: scored, brand "Sony", "Battery Life"=78 → delta 8.
     *       Ignore: agree (both not ignored).
     *  - B: stored is_ignored=false, brand "Bose", stored "Build Quality"=50.
     *       Candidate: scored, brand "Bose", "Build Quality"=52 → delta 2.
     *       Ignore: agree.
     *  - C: stored is_ignored=TRUE, brand "JBL", stored "Battery Life"=60.
     *       Candidate: scored (disagrees with the stored ignore flag), brand "JBL",
     *       "Battery Life"=62 → delta 2.
     *       Ignore: DISAGREE (candidate says not-ignored, stored says ignored).
     *
     * Ignore agreement: 2/3 (A, B agree; C does not) = 0.6666...
     * Brand: all 3 candidates are scored (not ignored) → 3 comparisons, all
     *   raw-exact and normalized-exact → 3/3 = 1.0 on both.
     * Feature deltas: 8, 2, 2 → MAD = 12/3 = 4.0 exactly; max delta = 8 on
     *   "Battery Life" for product A.
     * Gate: FAIL — ignore agreement (66.7%) is below the 95% threshold, even
     *   though brand (100%) and MAD (4.0) both pass their own thresholds.
     */
    /** @test */
    public function hand_computable_metrics_on_a_three_product_fixture(): void
    {
        $sony = Brand::factory()->create(['name' => 'Sony']);
        $bose = Brand::factory()->create(['name' => 'Bose']);
        $jbl  = Brand::factory()->create(['name' => 'JBL']);

        $productA = $this->processedProduct(['brand_id' => $sony->id, 'is_ignored' => false]);
        $productB = $this->processedProduct(['brand_id' => $bose->id, 'is_ignored' => false]);
        $productC = $this->processedProduct(['brand_id' => $jbl->id, 'is_ignored' => true]);

        ProductFeatureValue::create(['product_id' => $productA->id, 'feature_id' => $this->batteryLife->id, 'raw_value' => 70]);
        ProductFeatureValue::create(['product_id' => $productB->id, 'feature_id' => $this->buildQuality->id, 'raw_value' => 50]);
        ProductFeatureValue::create(['product_id' => $productC->id, 'feature_id' => $this->batteryLife->id, 'raw_value' => 60]);

        $evaluations = [
            ['product_id' => $productA->id, 'name' => 'A', 'brand' => 'Sony', 'ai_summary' => 'Great battery.', 'features' => ['Battery Life' => ['score' => 78, 'reason' => null]]],
            ['product_id' => $productB->id, 'name' => 'B', 'brand' => 'Bose', 'ai_summary' => 'Solid build.',   'features' => ['Build Quality' => ['score' => 52, 'reason' => null]]],
            ['product_id' => $productC->id, 'name' => 'C', 'brand' => 'JBL',  'ai_summary' => 'Decent option.', 'features' => ['Battery Life' => ['score' => 62, 'reason' => null]]],
        ];

        $result = $this->action()->execute($this->tenant, $evaluations);

        $this->assertSame(3, $result->totalRows);
        $this->assertSame(3, $result->comparedRows);
        $this->assertSame(0, $result->unmatchedRows);

        $this->assertSame(2, $result->ignoreAgreementMatches);
        $this->assertEqualsWithDelta(2 / 3, $result->ignoreAgreementRate, 0.0001);

        $this->assertSame(3, $result->brandComparisons);
        $this->assertSame(3, $result->brandRawExactMatches);
        $this->assertSame(3, $result->brandNormalizedExactMatches);
        $this->assertSame(1.0, $result->brandRawExactRate);
        $this->assertSame(1.0, $result->brandNormalizedExactRate);

        $this->assertSame(3, $result->featurePairsCompared);
        $this->assertSame(0, $result->featurePairsSkipped);
        $this->assertEqualsWithDelta(4.0, $result->featureMad, 0.0001);
        $this->assertSame($productA->id, $result->featureMaxDelta['product_id']);
        $this->assertSame('Battery Life', $result->featureMaxDelta['feature']);
        $this->assertEqualsWithDelta(8.0, $result->featureMaxDelta['delta'], 0.0001);

        $this->assertSame('fail', $result->gate);
        $this->assertNotEmpty($result->gateReasons);
        $this->assertStringContainsString('is_ignored agreement', $result->gateReasons[0]);
    }

    // =========================================================================
    // Gate: PASS
    // =========================================================================

    /** @test */
    public function gate_passes_when_every_threshold_is_met(): void
    {
        $brand = Brand::factory()->create(['name' => 'Anker']);
        $product = $this->processedProduct(['brand_id' => $brand->id, 'is_ignored' => false]);
        ProductFeatureValue::create(['product_id' => $product->id, 'feature_id' => $this->batteryLife->id, 'raw_value' => 80]);

        $evaluations = [[
            'product_id' => $product->id,
            'name'       => 'Anker Product',
            'brand'      => 'Anker',
            'ai_summary' => 'Clean, no issues.',
            'features'   => ['Battery Life' => ['score' => 82, 'reason' => null]],
        ]];

        $result = $this->action()->execute($this->tenant, $evaluations);

        $this->assertSame('pass', $result->gate);
        $this->assertSame([], $result->gateReasons);
    }

    // =========================================================================
    // Gate: FAIL (brand mismatch, ignore agreement + MAD both fine)
    // =========================================================================

    /** @test */
    public function gate_fails_on_a_below_threshold_brand_normalized_exact_rate(): void
    {
        $brand = Brand::factory()->create(['name' => 'Anker']);
        $product = $this->processedProduct(['brand_id' => $brand->id, 'is_ignored' => false]);
        ProductFeatureValue::create(['product_id' => $product->id, 'feature_id' => $this->batteryLife->id, 'raw_value' => 80]);

        $evaluations = [[
            'product_id' => $product->id,
            'name'       => 'Mislabeled Product',
            'brand'      => 'RavPower', // does not match stored "Anker" at all
            'ai_summary' => 'Clean, no issues.',
            'features'   => ['Battery Life' => ['score' => 81, 'reason' => null]],
        ]];

        $result = $this->action()->execute($this->tenant, $evaluations);

        $this->assertSame('fail', $result->gate);
        $this->assertNotEmpty(array_filter($result->gateReasons, fn ($r) => str_contains($r, 'brand normalized')));
    }

    // =========================================================================
    // Gate: INSUFFICIENT
    // =========================================================================

    /** @test */
    public function gate_is_insufficient_on_an_empty_evaluations_array(): void
    {
        $result = $this->action()->execute($this->tenant, []);

        $this->assertSame('insufficient', $result->gate);
        $this->assertSame(0, $result->comparedRows);
        $this->assertSame(0, $result->totalRows);
    }

    // =========================================================================
    // Unmatched: another tenant's product
    // =========================================================================

    /** @test */
    public function a_row_for_another_tenants_product_is_unmatched(): void
    {
        Tenant::create(['id' => 'cmp-tenant-b', 'name' => 'Compare Tenant B']);
        tenancy()->end();
        tenancy()->initialize(Tenant::find('cmp-tenant-b'));

        $otherCategory = Category::factory()->create(['slug' => 'cmp-cat-b']);
        $otherProduct = Product::create([
            'category_id' => $otherCategory->id,
            'name'        => 'Other Tenant Product',
            'slug'        => 'cmp-other-tenant-product',
            'status'      => null,
            'is_ignored'  => false,
        ]);

        tenancy()->end();
        tenancy()->initialize($this->tenant);

        $evaluations = [[
            'product_id' => $otherProduct->id,
            'name'       => 'Should Not Match',
            'brand'      => 'X',
            'ai_summary' => 'Y',
            'features'   => [],
        ]];

        $result = $this->action()->execute($this->tenant, $evaluations);

        $this->assertSame(1, $result->totalRows);
        $this->assertSame(0, $result->comparedRows);
        $this->assertSame(1, $result->unmatchedRows);
        $this->assertSame('unmatched', $result->diffs[0]['status']);
        $this->assertStringContainsString('not found', $result->diffs[0]['reason']);
    }

    // =========================================================================
    // Unmatched: pending (unprocessed) product
    // =========================================================================

    /** @test */
    public function a_row_for_a_pending_product_is_unmatched(): void
    {
        $product = $this->processedProduct(['status' => 'pending_ai']);

        $evaluations = [[
            'product_id' => $product->id,
            'name'       => 'Still Pending',
            'brand'      => 'X',
            'ai_summary' => 'Y',
            'features'   => [],
        ]];

        $result = $this->action()->execute($this->tenant, $evaluations);

        $this->assertSame(0, $result->comparedRows);
        $this->assertSame(1, $result->unmatchedRows);
        $this->assertSame('unmatched', $result->diffs[0]['status']);
        $this->assertStringContainsString('not processed', $result->diffs[0]['reason']);
    }

    // =========================================================================
    // Unmatched: invalid evaluation row
    // =========================================================================

    /** @test */
    public function an_invalid_evaluation_row_is_unmatched_with_a_reason(): void
    {
        $product = $this->processedProduct();

        // Missing required `ai_summary` for a scored row → InvalidProductEvaluation.
        $evaluations = [[
            'product_id' => $product->id,
            'name'       => 'Some Product',
            'brand'      => 'Some Brand',
            'features'   => [],
        ]];

        $result = $this->action()->execute($this->tenant, $evaluations);

        $this->assertSame(0, $result->comparedRows);
        $this->assertSame(1, $result->unmatchedRows);
        $this->assertStringContainsString('invalid evaluation', $result->diffs[0]['reason']);
    }

    // =========================================================================
    // Sweep-detach counts as "ignored" for agreement purposes
    // =========================================================================

    /**
     * Spec 039 §2 T5 — a product detached by a category sweep (category_id
     * null, an AiCategoryRejection row present) counts as "ignored" for
     * is_ignored-agreement purposes, even though its own is_ignored column is
     * still false. A candidate `wrong_category` verdict against it is
     * agreement, not disagreement.
     */
    /** @test */
    public function a_swept_detached_product_counts_as_ignored_for_agreement(): void
    {
        $product = $this->processedProduct(['category_id' => null, 'is_ignored' => false]);

        AiCategoryRejection::create([
            'product_id'       => $product->id,
            'category_id'      => $this->category->id,
            'rejection_reason' => 'wrong_category',
        ]);

        $evaluations = [[
            'product_id' => $product->id,
            'status'     => 'ignored',
            'reason'     => 'wrong_category',
        ]];

        $result = $this->action()->execute($this->tenant, $evaluations);

        $this->assertSame(1, $result->comparedRows);
        $this->assertSame(1, $result->ignoreAgreementMatches);
        $this->assertTrue($result->diffs[0]['ignore']['stored']);
        $this->assertTrue($result->diffs[0]['ignore']['agree']);
    }

    // =========================================================================
    // Feature pair skipped when the candidate scores a feature with no
    // stored ProductFeatureValue
    // =========================================================================

    /** @test */
    public function a_feature_the_candidate_scored_but_that_has_no_stored_value_is_skipped_not_deltad(): void
    {
        $brand = Brand::factory()->create(['name' => 'Anker']);
        $product = $this->processedProduct(['brand_id' => $brand->id, 'is_ignored' => false]);
        // No stored ProductFeatureValue at all.

        $evaluations = [[
            'product_id' => $product->id,
            'name'       => 'No Stored Features',
            'brand'      => 'Anker',
            'ai_summary' => 'Clean.',
            'features'   => ['Battery Life' => ['score' => 90, 'reason' => null]],
        ]];

        $result = $this->action()->execute($this->tenant, $evaluations);

        $this->assertSame(0, $result->featurePairsCompared);
        $this->assertSame(1, $result->featurePairsSkipped);
        $this->assertSame(0.0, $result->featureMad);
        $this->assertNull($result->featureMaxDelta);
        $this->assertSame(['Battery Life'], $result->diffs[0]['features_skipped']);
    }

    // =========================================================================
    // ai_summary condition-word hit counting
    // =========================================================================

    /** @test */
    public function ai_summary_condition_word_hits_are_counted_via_product_condition_guard(): void
    {
        $brand = Brand::factory()->create(['name' => 'Anker']);
        $product = $this->processedProduct(['brand_id' => $brand->id, 'is_ignored' => false]);

        $evaluations = [[
            'product_id' => $product->id,
            'name'       => 'Refurbished Widget',
            'brand'      => 'Anker',
            'ai_summary' => 'A solid pick, though refurbished units are common in the market.',
            'features'   => [],
        ]];

        $result = $this->action()->execute($this->tenant, $evaluations);

        $this->assertSame(1, $result->conditionWordHits);
        $this->assertTrue($result->diffs[0]['condition_word_hit']);
    }
}
