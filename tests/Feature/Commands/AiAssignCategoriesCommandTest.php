<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Jobs\AuditLandingPageFreshnessJob;
use App\Models\Category;
use App\Models\Feature;
use App\Models\LandingPage;
use App\Models\Product;
use App\Models\ProductFeatureValue;
use App\Models\Tenant;
use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Spec 035 — pw2d:ai-assign-categories, full-command integration.
 *
 * Before Spec 035, `Product::where(...)->update(['category_id' => ...])` was a mass
 * Eloquent update — no `ProductObserver` event, so a product carrying stale feature
 * values from a PREVIOUS category kept them, on top of whatever the (still-manually
 * dispatched) `RescanProductFeatures` added for the new one. That's the exact 12-
 * values-for-a-6-feature-category shape found on product #3352 (2026-08-21 incident).
 */
class AiAssignCategoriesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'assign-cmd-tenant', 'name' => 'Assign Command Tenant']);
        $this->tenant = Tenant::find('assign-cmd-tenant');
        tenancy()->initialize($this->tenant);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    /**
     * AiService spy covering both AI calls this command's pipeline makes:
     * `assignCategories` (the command itself) and `rescanFeatures` (the
     * `RescanProductFeatures` job the observer queues — QUEUE_CONNECTION=sync
     * in tests, so it runs inline within the same call).
     */
    private function makeAssignAndRescanSpy(int $productId, int $categoryId, array $featureScores): object
    {
        return new class ($productId, $categoryId, $featureScores) extends AiService {
            public function __construct(
                private int $productId,
                private int $categoryId,
                private array $featureScores,
            ) {}

            public function assignCategories(Collection $products, array $categoryOptions): array
            {
                return [[
                    'id'          => $this->productId,
                    'category_id' => $this->categoryId,
                    'reason'      => 'Test assignment',
                ]];
            }

            public function rescanFeatures(
                string $productName,
                string $priceNote,
                string $ratingNote,
                array $featureMap,
            ): array {
                return ['parsed' => ['features' => $this->featureScores]];
            }
        };
    }

    private function jobTargetsPage(AuditLandingPageFreshnessJob $job, int $landingPageId): bool
    {
        $ref  = new \ReflectionClass($job);
        $prop = $ref->getProperty('landingPageId');
        $prop->setAccessible(true);

        return $prop->getValue($job) === $landingPageId;
    }

    /** AiService spy for the --ignore-unmatched branch: every product is unmatched. */
    private function makeUnmatchedSpy(int $productId): object
    {
        return new class ($productId) extends AiService {
            public function __construct(private int $productId) {}

            public function assignCategories(Collection $products, array $categoryOptions): array
            {
                return [[
                    'id'          => $this->productId,
                    'category_id' => null,
                    'reason'      => 'No matching category',
                ]];
            }
        };
    }

    // =========================================================================
    // The #3352 regression (Spec 035): a product must never hold two
    // categories' worth of feature values after being re-homed.
    // =========================================================================

    /** @test */
    public function assigning_a_category_clears_the_old_categorys_feature_values_and_the_final_count_matches_the_new_categorys_feature_count(): void
    {
        $oldCategory = Category::factory()->create();
        $newCategory = Category::factory()->create();

        $oldFeature1 = Feature::factory()->create(['category_id' => $oldCategory->id, 'name' => 'Weight']);
        $oldFeature2 = Feature::factory()->create(['category_id' => $oldCategory->id, 'name' => 'Size']);

        $newFeature1 = Feature::factory()->create(['category_id' => $newCategory->id, 'name' => 'Speed']);
        $newFeature2 = Feature::factory()->create(['category_id' => $newCategory->id, 'name' => 'Durability']);
        $newFeature3 = Feature::factory()->create(['category_id' => $newCategory->id, 'name' => 'Noise']);

        // Uncategorized (the only state pw2d:ai-assign-categories will touch), but
        // already carrying stale values from a category it left earlier — exactly
        // the shape a prior sweep-to-null would leave behind pre-Spec-035.
        $product = Product::factory()->create(['category_id' => null, 'is_ignored' => false]);
        ProductFeatureValue::factory()->create(['product_id' => $product->id, 'feature_id' => $oldFeature1->id]);
        ProductFeatureValue::factory()->create(['product_id' => $product->id, 'feature_id' => $oldFeature2->id]);

        app()->instance(AiService::class, $this->makeAssignAndRescanSpy(
            $product->id,
            $newCategory->id,
            ['Speed' => 80, 'Durability' => 70, 'Noise' => 60],
        ));

        tenancy()->end();

        Artisan::call('pw2d:ai-assign-categories', [
            'tenant' => 'assign-cmd-tenant',
        ]);

        tenancy()->initialize($this->tenant);

        $fresh = $product->fresh();
        $this->assertSame($newCategory->id, $fresh->category_id);

        $values = ProductFeatureValue::where('product_id', $product->id)->get();

        $this->assertSame(
            $newCategory->features()->count(),
            $values->count(),
            'total feature values must equal the NEW category\'s feature count — no leftover from the old one'
        );
        $this->assertEqualsCanonicalizing(
            [$newFeature1->id, $newFeature2->id, $newFeature3->id],
            $values->pluck('feature_id')->all(),
        );
    }

    // =========================================================================
    // Assigning a category is a re-home like any other — the instant freshness
    // path (Spec 030) must fire for it too, now that it uses a model-level save.
    // =========================================================================

    /** @test */
    public function assigning_a_category_dispatches_the_freshness_audit_for_pages_referencing_the_product(): void
    {
        $oldCategory = Category::factory()->create();
        $newCategory = Category::factory()->create();
        Feature::factory()->create(['category_id' => $newCategory->id, 'name' => 'Speed']);

        $product = Product::factory()->create(['category_id' => null, 'is_ignored' => false]);

        $page = LandingPage::factory()->create([
            'category_id' => $oldCategory->id,
            'picks'       => [
                ['product_id' => $product->id, 'role' => 'overall', 'headline' => 'H', 'body' => 'B', 'est_price_snapshot' => 100],
            ],
        ]);

        app()->instance(AiService::class, $this->makeAssignAndRescanSpy(
            $product->id,
            $newCategory->id,
            ['Speed' => 75],
        ));

        Queue::fake([AuditLandingPageFreshnessJob::class]);

        tenancy()->end();

        Artisan::call('pw2d:ai-assign-categories', [
            'tenant' => 'assign-cmd-tenant',
        ]);

        tenancy()->initialize($this->tenant);

        Queue::assertPushed(
            AuditLandingPageFreshnessJob::class,
            fn (AuditLandingPageFreshnessJob $job) => $this->jobTargetsPage($job, $page->id)
        );
    }

    /**
     * Spec 036 §2 / H-B (2026-08-21 audit) — the --ignore-unmatched branch used a
     * mass `Product::where('id', ...)->update(...)`, 11 lines below the category-
     * assignment branch's own model-level-save fix (Spec 035). No Eloquent event
     * fired, so a page already showing an unmatched product as a pick never got
     * re-audited when it was flipped to is_ignored=true.
     */
    /** @test */
    public function ignore_unmatched_dispatches_the_freshness_audit_for_pages_referencing_the_product(): void
    {
        $pageCategory = Category::factory()->create();

        // Uncategorized (the only state the command touches), already referenced by
        // a landing page pick — the shape a category re-home or manual pick edit
        // could leave behind.
        $product = Product::factory()->create(['category_id' => null, 'is_ignored' => false]);

        $page = LandingPage::factory()->create([
            'category_id' => $pageCategory->id,
            'picks'       => [
                ['product_id' => $product->id, 'role' => 'overall', 'headline' => 'H', 'body' => 'B', 'est_price_snapshot' => 100],
            ],
        ]);

        app()->instance(AiService::class, $this->makeUnmatchedSpy($product->id));

        Queue::fake([AuditLandingPageFreshnessJob::class]);

        tenancy()->end();

        Artisan::call('pw2d:ai-assign-categories', [
            'tenant'               => 'assign-cmd-tenant',
            '--ignore-unmatched'   => true,
        ]);

        tenancy()->initialize($this->tenant);

        $this->assertTrue($product->fresh()->is_ignored);

        Queue::assertPushed(
            AuditLandingPageFreshnessJob::class,
            fn (AuditLandingPageFreshnessJob $job) => $this->jobTargetsPage($job, $page->id)
        );
    }
}
