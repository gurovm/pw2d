<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Jobs\AuditLandingPageFreshnessJob;
use App\Jobs\RescanProductFeatures;
use App\Models\AiCategoryRejection;
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
 * Spec 035 — pw2d:ai-sweep-category, full-command integration.
 *
 * `AiSweepCategoryTest` (tests/Unit) already covers `AiService::sweepCategoryPollution`
 * in isolation. These tests exercise the command end to end, because the bug this spec
 * fixes ONLY reproduces at that level: `Product::where(...)->update(...)` is a mass
 * Eloquent update that fires no model events, so `ProductObserver` never ran and the
 * whole point of these tests is proving it now does.
 */
class AiSweepCategoryCommandTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'sweep-cmd-tenant', 'name' => 'Sweep Command Tenant']);
        $this->tenant = Tenant::find('sweep-cmd-tenant');
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
     * AiService spy: flags every product it's given (the command only cares about
     * IDs it's told to remove — which products get flagged is AiService's job, and
     * that's already covered by tests/Unit/AiSweepCategoryTest).
     */
    private function makeFlagAllSpy(): object
    {
        return new class extends AiService {
            public function __construct() {}

            public function sweepCategoryPollution(Category $category, Collection $products): array
            {
                return $products->map(fn ($p) => ['id' => $p->id, 'reason' => 'Test flag'])->values()->toArray();
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

    // =========================================================================
    // Critical case (Spec 035): before this fix, NOTHING asserted this — the
    // mass update silently skipped ProductObserver, so the instant freshness
    // path (Spec 030) never ran for a sweep-to-null.
    // =========================================================================

    /** @test */
    public function sweeping_a_product_to_null_fires_the_observer_and_dispatches_the_freshness_audit(): void
    {
        app()->instance(AiService::class, $this->makeFlagAllSpy());
        Queue::fake();

        $category = Category::factory()->create(['slug' => 'sweep-to-null-category']);
        $product  = Product::factory()->create([
            'category_id' => $category->id,
            'is_ignored'  => false,
            'status'      => null,
        ]);

        $feature = Feature::factory()->create(['category_id' => $category->id]);
        ProductFeatureValue::factory()->create(['product_id' => $product->id, 'feature_id' => $feature->id]);

        $page = LandingPage::factory()->create([
            'category_id' => $category->id,
            'picks'       => [
                ['product_id' => $product->id, 'role' => 'overall', 'headline' => 'H', 'body' => 'B', 'est_price_snapshot' => 100],
            ],
        ]);

        tenancy()->end();

        Artisan::call('pw2d:ai-sweep-category', [
            'tenant'        => 'sweep-cmd-tenant',
            'category_slug' => 'sweep-to-null-category',
        ]);

        tenancy()->initialize($this->tenant);

        $this->assertNull($product->fresh()->category_id, 'the product must be detached from the swept category');

        $this->assertDatabaseHas('ai_category_rejections', [
            'product_id'  => $product->id,
            'category_id' => $category->id,
        ]);

        Queue::assertPushed(
            AuditLandingPageFreshnessJob::class,
            fn (AuditLandingPageFreshnessJob $job) => $this->jobTargetsPage($job, $page->id)
        );

        $this->assertSame(
            0,
            ProductFeatureValue::where('product_id', $product->id)->count(),
            'a detached product has no reachable category feature — the old value must be cleared'
        );

        // No new category was assigned, so there is nothing to rescan.
        Queue::assertNotPushed(RescanProductFeatures::class);
    }

    // =========================================================================
    // Queue-flood guard (Spec 035 §3 / S9, docs/tasks/todo.md).
    // =========================================================================

    /** @test */
    public function sweeping_products_whose_pages_overlap_does_not_flood_the_queue_with_duplicate_audits(): void
    {
        // S9 (docs/tasks/todo.md): before Spec 035, this fan-out was purely
        // theoretical because the sweep's mass Builder::update() never fired
        // ProductObserver at all. Now that it does, AuditLandingPageFreshnessJob's
        // existing ShouldBeUnique (uniqueFor: 600s, keyed on landing page id) MUST
        // collapse repeat dispatches for the SAME page, or a 100-product sweep
        // would produce ~100 duplicate audits of a handful of pages.
        app()->instance(AiService::class, $this->makeFlagAllSpy());
        Queue::fake();

        $sweptCategory = Category::factory()->create(['slug' => 'sweep-collapse-category']);
        $pageCategoryA = Category::factory()->create();
        $pageCategoryB = Category::factory()->create();

        $productsOnA = Product::factory()->count(3)->create(['category_id' => $sweptCategory->id, 'status' => null]);
        $productsOnB = Product::factory()->count(3)->create(['category_id' => $sweptCategory->id, 'status' => null]);

        $pageA = LandingPage::factory()->create([
            'category_id' => $pageCategoryA->id,
            'picks'       => $productsOnA->map(fn (Product $p) => ['product_id' => $p->id, 'role' => 'overall'])->all(),
        ]);
        $pageB = LandingPage::factory()->create([
            'category_id' => $pageCategoryB->id,
            'picks'       => $productsOnB->map(fn (Product $p) => ['product_id' => $p->id, 'role' => 'overall'])->all(),
        ]);

        tenancy()->end();

        Artisan::call('pw2d:ai-sweep-category', [
            'tenant'        => 'sweep-cmd-tenant',
            'category_slug' => 'sweep-collapse-category',
        ]);

        tenancy()->initialize($this->tenant);

        // 6 products swept, referencing only 2 distinct pages between them — far
        // fewer than 6 audit jobs must have been pushed.
        Queue::assertPushed(AuditLandingPageFreshnessJob::class, 2);

        Queue::assertPushed(
            AuditLandingPageFreshnessJob::class,
            fn (AuditLandingPageFreshnessJob $job) => $this->jobTargetsPage($job, $pageA->id)
        );
        Queue::assertPushed(
            AuditLandingPageFreshnessJob::class,
            fn (AuditLandingPageFreshnessJob $job) => $this->jobTargetsPage($job, $pageB->id)
        );
    }
}
