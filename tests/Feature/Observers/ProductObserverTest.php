<?php

declare(strict_types=1);

namespace Tests\Feature\Observers;

use App\Jobs\AuditLandingPageFreshnessJob;
use App\Models\Category;
use App\Models\LandingPage;
use App\Models\Product;
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
}
