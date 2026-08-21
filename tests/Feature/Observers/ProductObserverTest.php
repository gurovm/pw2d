<?php

declare(strict_types=1);

namespace Tests\Feature\Observers;

use App\Jobs\AuditLandingPageFreshnessJob;
use App\Jobs\RescanProductFeatures;
use App\Models\Category;
use App\Models\Feature;
use App\Models\LandingPage;
use App\Models\Product;
use App\Models\ProductFeatureValue;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Spec 030 §B3/B7 — ProductObserver's instant freshness-audit trigger.
 *
 * The audit itself is never run inline (Queue::fake() would catch a synchronous
 * call as easily as a dispatch, but the important assertion is WHICH events
 * dispatch {@see AuditLandingPageFreshnessJob} and which don't).
 */
class ProductObserverTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'product-observer-tenant', 'name' => 'Product Observer Tenant']);
        $this->tenant = Tenant::find('product-observer-tenant');
        tenancy()->initialize($this->tenant);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    private function makePageWithPick(Category $category, Product $product): LandingPage
    {
        return LandingPage::factory()->create([
            'category_id' => $category->id,
            'picks'       => [
                ['product_id' => $product->id, 'role' => 'overall', 'headline' => 'H', 'body' => 'B', 'est_price_snapshot' => 100],
            ],
        ]);
    }

    /** @test */
    public function flipping_is_ignored_to_true_dispatches_the_audit_job_for_pages_referencing_the_product(): void
    {
        Queue::fake();

        $category = Category::factory()->create();
        $product  = Product::factory()->create(['category_id' => $category->id, 'is_ignored' => false]);
        $page     = $this->makePageWithPick($category, $product);

        $product->update(['is_ignored' => true]);

        Queue::assertPushed(AuditLandingPageFreshnessJob::class, function (AuditLandingPageFreshnessJob $job) use ($page) {
            return $this->jobTargets($job, $page->id);
        });
    }

    /** @test */
    public function flipping_is_ignored_back_to_false_does_not_dispatch(): void
    {
        Queue::fake();

        $category = Category::factory()->create();
        $product  = Product::factory()->create(['category_id' => $category->id, 'is_ignored' => true]);
        $this->makePageWithPick($category, $product);

        $product->update(['is_ignored' => false]);

        Queue::assertNotPushed(AuditLandingPageFreshnessJob::class);
    }

    /** @test */
    public function changing_category_id_dispatches_the_audit_job(): void
    {
        Queue::fake();

        $category      = Category::factory()->create();
        $otherCategory = Category::factory()->create();
        $product       = Product::factory()->create(['category_id' => $category->id]);
        $page          = $this->makePageWithPick($category, $product);

        $product->update(['category_id' => $otherCategory->id]);

        Queue::assertPushed(AuditLandingPageFreshnessJob::class, function (AuditLandingPageFreshnessJob $job) use ($page) {
            return $this->jobTargets($job, $page->id);
        });
    }

    /** @test */
    public function deleting_a_pick_product_dispatches_the_audit_job(): void
    {
        Queue::fake();

        $category = Category::factory()->create();
        $product  = Product::factory()->create(['category_id' => $category->id]);
        $page     = $this->makePageWithPick($category, $product);

        $product->delete();

        Queue::assertPushed(AuditLandingPageFreshnessJob::class, function (AuditLandingPageFreshnessJob $job) use ($page) {
            return $this->jobTargets($job, $page->id);
        });
    }

    /** @test */
    public function an_unrelated_save_does_not_dispatch(): void
    {
        Queue::fake();

        $category = Category::factory()->create();
        $product  = Product::factory()->create(['category_id' => $category->id, 'name' => 'Old Name']);
        $this->makePageWithPick($category, $product);

        $product->update(['name' => 'New Name']);

        Queue::assertNotPushed(AuditLandingPageFreshnessJob::class);
    }

    /** @test */
    public function a_change_on_a_product_not_referenced_by_any_page_does_not_dispatch(): void
    {
        Queue::fake();

        $category = Category::factory()->create();
        $product  = Product::factory()->create(['category_id' => $category->id, 'is_ignored' => false]);
        // No landing page references this product at all.

        $product->update(['is_ignored' => true]);

        Queue::assertNotPushed(AuditLandingPageFreshnessJob::class);
    }

    // =========================================================================
    // Spec 035 — re-home integrity: clearing foreign-category feature values.
    //
    // A plain `$product->update(...)`/`->save()` IS the Filament re-home path
    // (Filament's category select just sets the attribute and saves). The two
    // AI commands (see tests/Feature/Commands/AiSweepCategoryCommandTest and
    // AiAssignCategoriesCommandTest) were fixed to use the same model-level
    // save instead of a mass Builder::update(), so they run through this exact
    // same observer logic — proving the "Filament path behaves identically to
    // a command re-home" requirement without duplicating these assertions.
    // =========================================================================

    /** @test */
    public function re_homing_a_product_deletes_the_old_categorys_feature_values_and_queues_a_rescan_for_the_new_one(): void
    {
        Queue::fake();

        $categoryA = Category::factory()->create();
        $categoryB = Category::factory()->create();

        $featureA = Feature::factory()->create(['category_id' => $categoryA->id]);
        $featureB = Feature::factory()->create(['category_id' => $categoryB->id]);

        $product = Product::factory()->create(['category_id' => $categoryA->id]);
        ProductFeatureValue::factory()->create(['product_id' => $product->id, 'feature_id' => $featureA->id]);

        $product->update(['category_id' => $categoryB->id]);

        $this->assertSame(
            0,
            ProductFeatureValue::where('product_id', $product->id)->where('feature_id', $featureA->id)->count(),
            "category A's feature value must be deleted once the product leaves category A"
        );

        Queue::assertPushed(
            RescanProductFeatures::class,
            fn (RescanProductFeatures $job) => $this->rescanJobTargets($job, $product->id, $categoryB->id)
        );
    }

    /** @test */
    public function detaching_a_product_to_null_deletes_all_feature_values_and_queues_no_rescan(): void
    {
        Queue::fake();

        $category = Category::factory()->create();
        $feature1 = Feature::factory()->create(['category_id' => $category->id]);
        $feature2 = Feature::factory()->create(['category_id' => $category->id]);

        $product = Product::factory()->create(['category_id' => $category->id]);
        ProductFeatureValue::factory()->create(['product_id' => $product->id, 'feature_id' => $feature1->id]);
        ProductFeatureValue::factory()->create(['product_id' => $product->id, 'feature_id' => $feature2->id]);

        $product->update(['category_id' => null]);

        $this->assertSame(
            0,
            ProductFeatureValue::where('product_id', $product->id)->count(),
            'a detached product (no category) has no reachable feature — every value must be deleted'
        );

        Queue::assertNotPushed(RescanProductFeatures::class);
    }

    /** @test */
    public function a_feature_value_already_belonging_to_the_new_category_survives_the_re_home(): void
    {
        Queue::fake();

        $categoryA = Category::factory()->create();
        $categoryB = Category::factory()->create();

        $featureA = Feature::factory()->create(['category_id' => $categoryA->id]);
        $featureB = Feature::factory()->create(['category_id' => $categoryB->id]);

        $product = Product::factory()->create(['category_id' => $categoryA->id]);
        ProductFeatureValue::factory()->create(['product_id' => $product->id, 'feature_id' => $featureA->id]);
        // A value for categoryB's feature that (unusually) already exists before the re-home.
        ProductFeatureValue::factory()->create(['product_id' => $product->id, 'feature_id' => $featureB->id]);

        $product->update(['category_id' => $categoryB->id]);

        $this->assertDatabaseHas('product_feature_values', [
            'product_id' => $product->id,
            'feature_id' => $featureB->id,
        ]);
        $this->assertSame(
            1,
            ProductFeatureValue::where('product_id', $product->id)->count(),
            'only the foreign-category value should have been deleted'
        );
    }

    /**
     * Reflection helper: AuditLandingPageFreshnessJob's constructor args are
     * private readonly — no public accessor exists (nor should one, for a job
     * whose only public surface is `handle()`), so tests reach in via Reflection.
     */
    private function jobTargets(AuditLandingPageFreshnessJob $job, int $landingPageId): bool
    {
        $ref  = new \ReflectionClass($job);
        $prop = $ref->getProperty('landingPageId');
        $prop->setAccessible(true);

        return $prop->getValue($job) === $landingPageId;
    }

    /**
     * Reflection helper: RescanProductFeatures' constructor args are private
     * readonly — see {@see jobTargets()} above for why reflection is used.
     */
    private function rescanJobTargets(RescanProductFeatures $job, int $productId, int $categoryId): bool
    {
        $ref = new \ReflectionClass($job);

        $productIdProp = $ref->getProperty('productId');
        $productIdProp->setAccessible(true);

        $categoryIdProp = $ref->getProperty('categoryId');
        $categoryIdProp->setAccessible(true);

        return $productIdProp->getValue($job) === $productId
            && $categoryIdProp->getValue($job) === $categoryId;
    }
}
