<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\AiUsage;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Feature;
use App\Models\Product;
use App\Models\ProductFeatureValue;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Spec 039 T4 — pw2d:products:apply-evaluations. Same tenancy pattern as
 * ExportPendingProductsCommandTest / GenerateLandingPageCommandTest: seed
 * under an initialized tenant, `tenancy()->end()` before Artisan::call so the
 * command initializes tenancy itself.
 */
class ApplyProductEvaluationsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Category $category;
    protected Feature $feature;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'apply-tenant', 'name' => 'Apply Tenant']);
        $this->tenant = Tenant::find('apply-tenant');
        tenancy()->initialize($this->tenant);

        $this->category = Category::factory()->create([
            'slug'         => 'apply-cat',
            'budget_max'   => 100,
            'midrange_max' => 300,
        ]);
        $this->feature = Feature::factory()->create([
            'category_id' => $this->category->id,
            'name'        => 'Build Quality',
        ]);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    private function makePendingProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'category_id' => $this->category->id,
            'name'        => 'Raw Stub Title',
            'slug'        => 'stub-' . Str::random(8),
            'status'      => 'pending_ai',
            'is_ignored'  => false,
        ], $overrides));
    }

    private function writeEvaluationsFile(array $evaluations): string
    {
        $path = storage_path('app/bouncer/test-evaluations-' . Str::random(8) . '.json');
        \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, json_encode(['evaluations' => $evaluations]));

        return $path;
    }

    private function fakeMatchProductAiCall(array $body): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content'      => ['parts' => [['text' => json_encode($body)]]],
                    'finishReason' => 'STOP',
                ]],
            ]),
        ]);
    }

    // =========================================================================
    // Happy path — scored
    // =========================================================================

    /** @test */
    public function happy_path_writes_product_brand_feature_values_and_a_zero_cost_ai_usage_row(): void
    {
        $product = $this->makePendingProduct();
        tenancy()->end();

        Http::fake(); // matchProduct has no candidates for this brand → no AI call

        $file = $this->writeEvaluationsFile([[
            'product_id' => $product->id,
            'name'       => 'Sony WH-1000XM5',
            'brand'      => 'Sony',
            'ai_summary' => 'A great pair of headphones.',
            'price_tier' => 2,
            'features'   => ['Build Quality' => ['score' => 88, 'reason' => 'Solid.']],
        ]]);

        $exitCode = Artisan::call('pw2d:products:apply-evaluations', [
            'tenant' => 'apply-tenant',
            'file'   => $file,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);

        tenancy()->initialize($this->tenant);

        $product->refresh();
        $this->assertSame('Sony WH-1000XM5', $product->name);
        $this->assertNull($product->status);
        $this->assertSame('Sony', $product->brand->name);
        $this->assertSame('A great pair of headphones.', $product->ai_summary);

        $this->assertDatabaseHas('product_feature_values', [
            'product_id' => $product->id,
            'feature_id' => $this->feature->id,
            'raw_value'  => 88,
        ]);

        $usage = AiUsage::where('purpose', 'evaluate_product')->where('model', 'claude-code-session')->sole();
        $this->assertSame($this->tenant->id, $usage->tenant_id);
        $this->assertNull($usage->input_tokens);
        $this->assertNotNull($usage->estimated_cost_usd, 'cost must be 0.0, not NULL — sunk-cost subscription');
        $this->assertEquals(0.0, (float) $usage->estimated_cost_usd);

        unlink($file);
    }

    // =========================================================================
    // Ignored path
    // =========================================================================

    /** @test */
    public function ignored_row_sets_is_ignored_and_nulls_status(): void
    {
        $product = $this->makePendingProduct();
        tenancy()->end();

        $file = $this->writeEvaluationsFile([[
            'product_id' => $product->id,
            'status'     => 'ignored',
            'reason'     => 'generic_white_label',
        ]]);

        Artisan::call('pw2d:products:apply-evaluations', ['tenant' => 'apply-tenant', 'file' => $file]);

        $product->refresh();
        $this->assertTrue($product->is_ignored);
        $this->assertNull($product->status);

        unlink($file);
    }

    /**
     * Spec 039 review M2/C — ProductEvaluation itself accepts any non-empty
     * ignore reason (the Gemini path may drift off the four-value
     * vocabulary), so the four-value enum is enforced HERE instead, at apply
     * time, where an off-list reason on a hand/AI-authored FILE row is an
     * authoring mistake, not model drift, and an `error` row is cheap and
     * visible.
     */
    /** @test */
    public function an_ignored_row_with_an_off_list_reason_is_an_error_and_writes_nothing(): void
    {
        $product = $this->makePendingProduct();
        tenancy()->end();

        $file = $this->writeEvaluationsFile([[
            'product_id' => $product->id,
            'status'     => 'ignored',
            'reason'     => 'bundle',
        ]]);

        $exitCode = Artisan::call('pw2d:products:apply-evaluations', ['tenant' => 'apply-tenant', 'file' => $file]);

        $this->assertSame(Command::FAILURE, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('unknown reason', $output);

        tenancy()->initialize($this->tenant);
        $product->refresh();
        $this->assertFalse($product->is_ignored, 'an off-list reason must write nothing');
        $this->assertSame('pending_ai', $product->status);

        unlink($file);
    }

    /** @test */
    public function wrong_category_is_still_a_valid_ignored_reason_at_apply_time(): void
    {
        $product = $this->makePendingProduct();
        tenancy()->end();

        $file = $this->writeEvaluationsFile([[
            'product_id' => $product->id,
            'status'     => 'ignored',
            'reason'     => 'wrong_category',
        ]]);

        $exitCode = Artisan::call('pw2d:products:apply-evaluations', ['tenant' => 'apply-tenant', 'file' => $file]);

        $this->assertSame(Command::SUCCESS, $exitCode);

        tenancy()->initialize($this->tenant);
        $product->refresh();
        $this->assertNull($product->category_id);
        $this->assertNull($product->status);

        unlink($file);
    }

    // =========================================================================
    // Merged path
    // =========================================================================

    /** @test */
    public function merged_row_transfers_offers_and_force_deletes_the_duplicate(): void
    {
        $brand = Brand::factory()->create(['name' => 'Breville']);
        $existing = Product::create([
            'category_id' => $this->category->id,
            'brand_id'    => $brand->id,
            'name'        => 'Breville Barista Express',
            'slug'        => 'breville-barista-express',
            'status'      => null,
            'is_ignored'  => false,
        ]);

        $duplicate = $this->makePendingProduct(['name' => 'Breville Barista Express BES870XL Duplicate Listing']);

        tenancy()->end();

        $this->fakeMatchProductAiCall([
            'is_match'             => true,
            'matched_product_name' => 'Breville Barista Express',
        ]);

        $file = $this->writeEvaluationsFile([[
            'product_id' => $duplicate->id,
            'name'       => 'Breville Barista Express',
            'brand'      => 'Breville',
            'ai_summary' => 'Duplicate of an existing product.',
            'features'   => ['Build Quality' => ['score' => 70, 'reason' => 'Fine.']],
        ]]);

        $exitCode = Artisan::call('pw2d:products:apply-evaluations', ['tenant' => 'apply-tenant', 'file' => $file]);
        $this->assertSame(Command::SUCCESS, $exitCode);

        tenancy()->initialize($this->tenant);

        $this->assertDatabaseMissing('products', ['id' => $duplicate->id]);
        $this->assertDatabaseHas('products', ['id' => $existing->id]);

        unlink($file);
    }

    // =========================================================================
    // Rejected path (wrong_category)
    // =========================================================================

    /** @test */
    public function wrong_category_row_rejects_the_product_from_its_category(): void
    {
        $product = $this->makePendingProduct();
        tenancy()->end();

        $file = $this->writeEvaluationsFile([[
            'product_id' => $product->id,
            'status'     => 'ignored',
            'reason'     => 'wrong_category',
        ]]);

        Artisan::call('pw2d:products:apply-evaluations', ['tenant' => 'apply-tenant', 'file' => $file]);

        tenancy()->initialize($this->tenant);

        $product->refresh();
        $this->assertNull($product->category_id);
        $this->assertNull($product->status);
        $this->assertFalse($product->is_ignored);

        $this->assertDatabaseHas('ai_category_rejections', [
            'product_id'  => $product->id,
            'category_id' => $this->category->id,
        ]);

        unlink($file);
    }

    // =========================================================================
    // Skip: processed product
    // =========================================================================

    /** @test */
    public function a_row_for_an_already_processed_product_is_skipped(): void
    {
        $product = $this->makePendingProduct(['status' => null]);
        tenancy()->end();

        $file = $this->writeEvaluationsFile([[
            'product_id' => $product->id,
            'name'       => 'Something',
            'brand'      => 'Something',
            'ai_summary' => 'Summary.',
            'features'   => [],
        ]]);

        $exitCode = Artisan::call('pw2d:products:apply-evaluations', ['tenant' => 'apply-tenant', 'file' => $file]);

        $this->assertSame(Command::SUCCESS, $exitCode, 'a skip alone must not fail the command');

        $output = Artisan::output();
        $this->assertStringContainsString('skipped', $output);

        unlink($file);
    }

    // =========================================================================
    // Skip: another tenant's product
    // =========================================================================

    /** @test */
    public function a_row_for_another_tenants_product_is_skipped(): void
    {
        Tenant::create(['id' => 'apply-tenant-b', 'name' => 'Apply Tenant B']);
        tenancy()->end();
        tenancy()->initialize(Tenant::find('apply-tenant-b'));

        $otherCategory = Category::factory()->create(['slug' => 'apply-cat-b']);
        $otherProduct  = Product::create([
            'category_id' => $otherCategory->id,
            'name'        => 'Other Tenant Product',
            'slug'        => 'other-tenant-product',
            'status'      => 'pending_ai',
            'is_ignored'  => false,
        ]);
        tenancy()->end();

        $file = $this->writeEvaluationsFile([[
            'product_id' => $otherProduct->id,
            'name'       => 'Should Not Apply',
            'brand'      => 'X',
            'ai_summary' => 'Summary.',
            'features'   => [],
        ]]);

        $exitCode = Artisan::call('pw2d:products:apply-evaluations', ['tenant' => 'apply-tenant', 'file' => $file]);
        $this->assertSame(Command::SUCCESS, $exitCode);

        tenancy()->initialize(Tenant::find('apply-tenant-b'));
        $otherProduct->refresh();
        $this->assertSame('Other Tenant Product', $otherProduct->name, 'a cross-tenant row must never be applied');
        $this->assertSame('pending_ai', $otherProduct->status);

        unlink($file);
    }

    // =========================================================================
    // Invalid row → error, nothing written, exit 1
    // =========================================================================

    /** @test */
    public function an_invalid_row_errors_writes_nothing_for_it_and_exits_with_failure(): void
    {
        $product = $this->makePendingProduct();
        tenancy()->end();

        Http::fake();

        // Missing required `ai_summary` for a scored row.
        $file = $this->writeEvaluationsFile([[
            'product_id' => $product->id,
            'name'       => 'Some Product',
            'brand'      => 'Some Brand',
            'features'   => [],
        ]]);

        $exitCode = Artisan::call('pw2d:products:apply-evaluations', ['tenant' => 'apply-tenant', 'file' => $file]);

        $this->assertSame(Command::FAILURE, $exitCode);

        tenancy()->initialize($this->tenant);
        $product->refresh();
        $this->assertSame('pending_ai', $product->status, 'an invalid row must write nothing');
        $this->assertSame(0, AiUsage::count());

        unlink($file);
    }

    /** @test */
    public function an_unknown_feature_name_is_an_error_and_writes_nothing(): void
    {
        $product = $this->makePendingProduct();
        tenancy()->end();

        Http::fake();

        $file = $this->writeEvaluationsFile([[
            'product_id' => $product->id,
            'name'       => 'Some Product',
            'brand'      => 'Some Brand',
            'ai_summary' => 'Summary.',
            'features'   => ['Not A Real Feature' => ['score' => 50, 'reason' => 'x']],
        ]]);

        $exitCode = Artisan::call('pw2d:products:apply-evaluations', ['tenant' => 'apply-tenant', 'file' => $file]);

        $this->assertSame(Command::FAILURE, $exitCode);

        tenancy()->initialize($this->tenant);
        $product->refresh();
        $this->assertSame('pending_ai', $product->status);
        $this->assertDatabaseMissing('product_feature_values', ['product_id' => $product->id]);

        unlink($file);
    }

    // =========================================================================
    // Per-row transaction failure isolation (review M1)
    // =========================================================================

    /**
     * A throw inside one row's DB::transaction() (here: matchProduct()'s
     * Gemini call returning 503) must become an `error` row for THAT product
     * only — the batch continues, and the other rows are still applied.
     */
    /** @test */
    public function a_row_that_throws_inside_its_transaction_becomes_an_error_row_and_the_batch_continues(): void
    {
        $productA = $this->makePendingProduct(['name' => 'Product A Raw Title']);
        $productB = $this->makePendingProduct(['name' => 'Product B Raw Title']);
        $productC = $this->makePendingProduct(['name' => 'Product C Raw Title']);

        // An existing product under the same brand as B — required so
        // matchProduct() reaches STEP 3 (the Gemini call) instead of
        // short-circuiting at the "no existing products for this brand"
        // heuristic, which A and C hit (no HTTP call, no failure).
        $failBrand = Brand::factory()->create(['name' => 'FailBrand']);
        Product::create([
            'category_id' => $this->category->id,
            'brand_id'    => $failBrand->id,
            'name'        => 'Existing FailBrand Product',
            'slug'        => 'existing-failbrand-product',
            'status'      => null,
            'is_ignored'  => false,
        ]);

        tenancy()->end();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 503),
        ]);

        $file = $this->writeEvaluationsFile([
            [
                'product_id' => $productA->id,
                'name'       => 'Product A',
                'brand'      => 'BrandA',
                'ai_summary' => 'Summary A.',
                'features'   => ['Build Quality' => ['score' => 60, 'reason' => 'x']],
            ],
            [
                'product_id' => $productB->id,
                'name'       => 'Product B',
                'brand'      => 'FailBrand',
                'ai_summary' => 'Summary B.',
                'features'   => ['Build Quality' => ['score' => 60, 'reason' => 'x']],
            ],
            [
                'product_id' => $productC->id,
                'name'       => 'Product C',
                'brand'      => 'BrandC',
                'ai_summary' => 'Summary C.',
                'features'   => ['Build Quality' => ['score' => 60, 'reason' => 'x']],
            ],
        ]);

        $exitCode = Artisan::call('pw2d:products:apply-evaluations', ['tenant' => 'apply-tenant', 'file' => $file]);

        $this->assertSame(Command::FAILURE, $exitCode, 'a batch with one failing row must still exit 1');

        $output = Artisan::output();
        $this->assertStringContainsString('error', $output);
        $this->assertStringContainsString('scored 2', $output, 'the two unaffected rows must still be applied');
        $this->assertStringContainsString('errors 1', $output);

        tenancy()->initialize($this->tenant);

        $productA->refresh();
        $productB->refresh();
        $productC->refresh();

        $this->assertNull($productA->status, 'product A must be applied despite product B failing');
        $this->assertSame('pending_ai', $productB->status, 'product B must be rolled back, not half-written');
        $this->assertNull($productC->status, 'product C must be applied despite product B failing');

        $this->assertSame(2, AiUsage::count(), 'only the two successful rows record ai_usage');

        unlink($file);
    }

    // =========================================================================
    // Missing-feature completeness (review M2)
    // =========================================================================

    /**
     * The spec's "every category feature present" rule (§2 T1/T4): a scored
     * row that omits one of the category's feature keys entirely (not even
     * an explicit `null`) is an authoring mistake, not model drift — it must
     * error and write nothing, the same as an unknown feature name.
     */
    /** @test */
    public function a_scored_row_omitting_a_category_feature_key_is_an_error_and_writes_nothing(): void
    {
        Feature::factory()->create(['category_id' => $this->category->id, 'name' => 'Feature B']);

        $product = $this->makePendingProduct();
        tenancy()->end();

        Http::fake();

        $file = $this->writeEvaluationsFile([[
            'product_id' => $product->id,
            'name'       => 'Some Product',
            'brand'      => 'Some Brand',
            'ai_summary' => 'Summary.',
            // 'Feature B' entirely omitted — not even an explicit null.
            'features'   => ['Build Quality' => ['score' => 60, 'reason' => 'x']],
        ]]);

        $exitCode = Artisan::call('pw2d:products:apply-evaluations', ['tenant' => 'apply-tenant', 'file' => $file]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('missing feature', Artisan::output());

        tenancy()->initialize($this->tenant);
        $product->refresh();
        $this->assertSame('pending_ai', $product->status, 'a row missing a category feature must write nothing');
        $this->assertDatabaseMissing('product_feature_values', ['product_id' => $product->id]);

        unlink($file);
    }

    /**
     * An explicit `"Feature B": null` counts as present ("not applicable")
     * and must apply cleanly — only a fully absent key is an error.
     */
    /** @test */
    public function a_scored_row_with_an_explicit_null_feature_is_applied(): void
    {
        $featureB = Feature::factory()->create(['category_id' => $this->category->id, 'name' => 'Feature B']);

        $product = $this->makePendingProduct();
        tenancy()->end();

        Http::fake();

        $file = $this->writeEvaluationsFile([[
            'product_id' => $product->id,
            'name'       => 'Some Product',
            'brand'      => 'Some Brand',
            'ai_summary' => 'Summary.',
            'features'   => [
                'Build Quality' => ['score' => 60, 'reason' => 'x'],
                'Feature B'     => null,
            ],
        ]]);

        $exitCode = Artisan::call('pw2d:products:apply-evaluations', ['tenant' => 'apply-tenant', 'file' => $file]);

        $this->assertSame(Command::SUCCESS, $exitCode);

        tenancy()->initialize($this->tenant);
        $product->refresh();
        $this->assertNull($product->status);
        $this->assertDatabaseMissing('product_feature_values', ['product_id' => $product->id, 'feature_id' => $featureB->id]);

        unlink($file);
    }

    // =========================================================================
    // --dry-run
    // =========================================================================

    /** @test */
    public function dry_run_writes_nothing_and_makes_zero_ai_calls(): void
    {
        $scoredProduct = $this->makePendingProduct();
        $ignoredProduct = $this->makePendingProduct();
        tenancy()->end();

        Http::fake();

        $file = $this->writeEvaluationsFile([
            [
                'product_id' => $scoredProduct->id,
                'name'       => 'Some Product',
                'brand'      => 'Some Brand',
                'ai_summary' => 'Summary.',
                'features'   => ['Build Quality' => ['score' => 60, 'reason' => 'x']],
            ],
            [
                'product_id' => $ignoredProduct->id,
                'status'     => 'ignored',
                'reason'     => 'accessory_or_bundle',
            ],
        ]);

        $exitCode = Artisan::call('pw2d:products:apply-evaluations', [
            'tenant'    => 'apply-tenant',
            'file'      => $file,
            '--dry-run' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);

        Http::assertNothingSent();

        tenancy()->initialize($this->tenant);
        $scoredProduct->refresh();
        $ignoredProduct->refresh();
        $this->assertSame('pending_ai', $scoredProduct->status, '--dry-run must write nothing');
        $this->assertSame('pending_ai', $ignoredProduct->status, '--dry-run must write nothing');
        $this->assertFalse($ignoredProduct->is_ignored);
        $this->assertSame(0, AiUsage::count());
        $this->assertSame(0, ProductFeatureValue::count());

        $output = Artisan::output();
        $this->assertStringContainsString('DRY RUN', $output);

        unlink($file);
    }

    // =========================================================================
    // Idempotency — second run of the same file is all skipped
    // =========================================================================

    /** @test */
    public function reapplying_the_same_file_skips_every_row_the_second_time(): void
    {
        $product = $this->makePendingProduct();
        tenancy()->end();

        Http::fake();

        $file = $this->writeEvaluationsFile([[
            'product_id' => $product->id,
            'name'       => 'Some Product',
            'brand'      => 'Some Brand',
            'ai_summary' => 'Summary.',
            'features'   => ['Build Quality' => ['score' => 60, 'reason' => 'x']],
        ]]);

        $first = Artisan::call('pw2d:products:apply-evaluations', ['tenant' => 'apply-tenant', 'file' => $file]);
        $this->assertSame(Command::SUCCESS, $first);

        $second = Artisan::call('pw2d:products:apply-evaluations', ['tenant' => 'apply-tenant', 'file' => $file]);
        $this->assertSame(Command::SUCCESS, $second);

        $secondOutput = Artisan::output();
        $this->assertStringContainsString('skipped', $secondOutput);
        $this->assertStringContainsString('scored 0', $secondOutput);

        tenancy()->initialize($this->tenant);
        $this->assertSame(1, AiUsage::count(), 'the second run must not record a second ai_usage row');

        unlink($file);
    }

    // =========================================================================
    // Queue guard (review M4) — a still-queued job can overwrite a product
    // this apply just finalized, so refuse unless --force is given.
    // =========================================================================

    /** @test */
    public function apply_refuses_while_jobs_are_queued_and_writes_nothing(): void
    {
        $product = $this->makePendingProduct();
        tenancy()->end();

        \Illuminate\Support\Facades\DB::table('jobs')->insert([
            'queue'        => 'default',
            'payload'      => 'serialized-payload',
            'attempts'     => 0,
            'available_at' => now()->getTimestamp(),
            'created_at'   => now()->getTimestamp(),
        ]);

        Http::fake();

        $file = $this->writeEvaluationsFile([[
            'product_id' => $product->id,
            'name'       => 'Some Product',
            'brand'      => 'Some Brand',
            'ai_summary' => 'Summary.',
            'features'   => ['Build Quality' => ['score' => 60, 'reason' => 'x']],
        ]]);

        $exitCode = Artisan::call('pw2d:products:apply-evaluations', ['tenant' => 'apply-tenant', 'file' => $file]);

        $this->assertSame(2, $exitCode, 'must refuse with a distinct exit code while jobs are queued');

        $output = Artisan::output();
        $this->assertStringContainsString('still queued', $output);
        $this->assertStringNotContainsString('Product ID', $output, 'the outcome table must never render on refusal');

        tenancy()->initialize($this->tenant);
        $product->refresh();
        $this->assertSame('pending_ai', $product->status, 'a refused apply must write nothing');
        $this->assertSame(0, AiUsage::count());

        unlink($file);
    }

    /** @test */
    public function apply_refuses_while_jobs_are_queued_even_with_dry_run(): void
    {
        $product = $this->makePendingProduct();
        tenancy()->end();

        \Illuminate\Support\Facades\DB::table('jobs')->insert([
            'queue'        => 'default',
            'payload'      => 'serialized-payload',
            'attempts'     => 0,
            'available_at' => now()->getTimestamp(),
            'created_at'   => now()->getTimestamp(),
        ]);

        $file = $this->writeEvaluationsFile([[
            'product_id' => $product->id,
            'name'       => 'Some Product',
            'brand'      => 'Some Brand',
            'ai_summary' => 'Summary.',
            'features'   => ['Build Quality' => ['score' => 60, 'reason' => 'x']],
        ]]);

        $exitCode = Artisan::call('pw2d:products:apply-evaluations', [
            'tenant'    => 'apply-tenant',
            'file'      => $file,
            '--dry-run' => true,
        ]);

        $this->assertSame(2, $exitCode, '--dry-run must not bypass the queue guard');

        unlink($file);
    }

    /** @test */
    public function force_bypasses_the_queue_guard_and_applies_normally(): void
    {
        $product = $this->makePendingProduct();
        tenancy()->end();

        \Illuminate\Support\Facades\DB::table('jobs')->insert([
            'queue'        => 'default',
            'payload'      => 'serialized-payload',
            'attempts'     => 0,
            'available_at' => now()->getTimestamp(),
            'created_at'   => now()->getTimestamp(),
        ]);

        Http::fake();

        $file = $this->writeEvaluationsFile([[
            'product_id' => $product->id,
            'name'       => 'Some Product',
            'brand'      => 'Some Brand',
            'ai_summary' => 'Summary.',
            'features'   => ['Build Quality' => ['score' => 60, 'reason' => 'x']],
        ]]);

        $exitCode = Artisan::call('pw2d:products:apply-evaluations', [
            'tenant'  => 'apply-tenant',
            'file'    => $file,
            '--force' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);

        tenancy()->initialize($this->tenant);
        $product->refresh();
        $this->assertNull($product->status, '--force must let the apply proceed despite queued jobs');

        unlink($file);
    }

    // =========================================================================
    // Malformed input file
    // =========================================================================

    /** @test */
    public function a_missing_evaluations_key_fails_cleanly(): void
    {
        $path = storage_path('app/bouncer/test-malformed-' . Str::random(8) . '.json');
        \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, json_encode(['not_evaluations' => []]));

        tenancy()->end();

        $exitCode = Artisan::call('pw2d:products:apply-evaluations', ['tenant' => 'apply-tenant', 'file' => $path]);

        $this->assertSame(Command::FAILURE, $exitCode);

        unlink($path);
    }

    /** @test */
    public function an_unknown_tenant_fails_cleanly(): void
    {
        tenancy()->end();

        $file = $this->writeEvaluationsFile([]);

        $exitCode = Artisan::call('pw2d:products:apply-evaluations', ['tenant' => 'no-such-tenant', 'file' => $file]);

        $this->assertSame(Command::FAILURE, $exitCode);

        unlink($file);
    }
}
