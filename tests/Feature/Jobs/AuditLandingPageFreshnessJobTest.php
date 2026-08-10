<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\AuditLandingPageFreshnessJob;
use App\Models\Category;
use App\Models\LandingPage;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Spec 030 §B3/B7 — AuditLandingPageFreshnessJob: tenancy handling + auditing.
 */
class AuditLandingPageFreshnessJobTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'audit-job-tenant', 'name' => 'Audit Job Tenant']);
        $this->tenant = Tenant::find('audit-job-tenant');
        tenancy()->initialize($this->tenant);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    /** @test */
    public function it_initializes_tenancy_and_audits_the_page_when_run_standalone(): void
    {
        $category = Category::factory()->create();
        $product  = Product::factory()->create(['category_id' => $category->id, 'is_ignored' => true]); // ineligible

        $page = LandingPage::factory()->create([
            'category_id' => $category->id,
            'status'      => 'published',
            'picks'       => [
                ['product_id' => $product->id, 'role' => 'overall', 'headline' => 'H', 'body' => 'B', 'est_price_snapshot' => 100],
            ],
        ]);

        tenancy()->end(); // simulate a queue worker with no ambient tenant context

        (new AuditLandingPageFreshnessJob($this->tenant->id, $page->id))->handle();

        tenancy()->initialize($this->tenant);
        $fresh = $page->fresh();

        $this->assertNotEmpty($fresh->stale_reasons, 'is_ignored pick must mark the page stale');
        $this->assertNotNull($fresh->freshness_checked_at);
    }

    /** @test */
    public function it_reuses_already_initialized_tenancy_instead_of_reinitializing(): void
    {
        $category = Category::factory()->create();
        $product  = Product::factory()->create(['category_id' => $category->id, 'is_ignored' => false]);

        $page = LandingPage::factory()->create([
            'category_id' => $category->id,
            'picks'       => [],
        ]);

        // Tenancy is already active (this test's setUp) — the job must not blow up
        // trying to nest another tenancy()->initialize() call, and must leave the
        // caller's tenancy active afterwards.
        (new AuditLandingPageFreshnessJob($this->tenant->id, $page->id))->handle();

        $this->assertTrue(tenancy()->initialized, 'the job must not end tenancy it did not itself initialize');
        $this->assertNotNull($page->fresh()->freshness_checked_at);
    }

    /** @test */
    public function it_is_a_no_op_for_an_unknown_landing_page(): void
    {
        (new AuditLandingPageFreshnessJob($this->tenant->id, 999999))->handle();

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function it_is_a_no_op_for_an_unknown_tenant(): void
    {
        tenancy()->end();

        (new AuditLandingPageFreshnessJob('does-not-exist', 1))->handle();

        $this->addToAssertionCount(1);
    }

    // =========================================================================
    // Reviewer B1 — dispatchForProduct()'s PHP-side picks filter (replaces the
    // MySQL-dead `picks LIKE` containment). Asserted directly against a picks
    // array — never via raw SQL — so this proves the SAME thing on every
    // connection, sqlite included (see docs/lessons.md).
    // =========================================================================

    /** @test */
    public function dispatch_for_product_finds_only_pages_whose_picks_reference_the_product(): void
    {
        Queue::fake();

        $category = Category::factory()->create();
        $otherCategory = Category::factory()->create();
        $product  = Product::factory()->create(['category_id' => $category->id]);
        $unrelatedProduct = Product::factory()->create(['category_id' => $otherCategory->id]);

        $matchingPage = LandingPage::factory()->create([
            'category_id' => $category->id,
            'picks'       => [['product_id' => $product->id, 'role' => 'overall']],
        ]);

        // Unique per (tenant_id, category_id) — a second landing page must belong to
        // a DIFFERENT category (unrelated to $product either way).
        $unrelatedPage = LandingPage::factory()->create([
            'category_id' => $otherCategory->id,
            'picks'       => [['product_id' => $unrelatedProduct->id, 'role' => 'overall']],
        ]);

        AuditLandingPageFreshnessJob::dispatchForProduct($product);

        Queue::assertPushed(AuditLandingPageFreshnessJob::class, fn (AuditLandingPageFreshnessJob $job) => $this->jobTargetsPage($job, $matchingPage->id));
        Queue::assertNotPushed(AuditLandingPageFreshnessJob::class, fn (AuditLandingPageFreshnessJob $job) => $this->jobTargetsPage($job, $unrelatedPage->id));
    }

    /** @test */
    public function dispatch_for_product_finds_a_stale_page_even_when_its_category_no_longer_matches_the_products_current_category(): void
    {
        Queue::fake();

        $originalCategory = Category::factory()->create();
        $newCategory      = Category::factory()->create();

        // The product has ALREADY moved to $newCategory by the time dispatchForProduct
        // runs — the STALE page (still filed under $originalCategory) must still be
        // found; there is deliberately no category_id filter in dispatchForProduct().
        $product = Product::factory()->create(['category_id' => $newCategory->id]);

        $stalePage = LandingPage::factory()->create([
            'category_id' => $originalCategory->id,
            'picks'       => [['product_id' => $product->id, 'role' => 'overall']],
        ]);

        AuditLandingPageFreshnessJob::dispatchForProduct($product);

        Queue::assertPushed(AuditLandingPageFreshnessJob::class, fn (AuditLandingPageFreshnessJob $job) => $this->jobTargetsPage($job, $stalePage->id));
    }

    private function jobTargetsPage(AuditLandingPageFreshnessJob $job, int $landingPageId): bool
    {
        $ref  = new \ReflectionClass($job);
        $prop = $ref->getProperty('landingPageId');
        $prop->setAccessible(true);

        return $prop->getValue($job) === $landingPageId;
    }
}
