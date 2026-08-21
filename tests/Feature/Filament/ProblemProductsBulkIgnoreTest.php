<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\ProblemProducts;
use App\Jobs\AuditLandingPageFreshnessJob;
use App\Models\Category;
use App\Models\LandingPage;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Spec 036 §2 / H-B (2026-08-21 audit) — ProblemProducts.php had BOTH patterns
 * on the same page: `:325` (the single-record Ignore row action) fired
 * ProductObserver via a model-level save, `:347` (the Mark as Ignored bulk
 * action) did not — a mass `Product::whereIn(...)->update(...)` fires no
 * Eloquent events, so a page already showing one of the bulk-ignored products
 * as a pick never got re-audited until the nightly sweep. This is THE
 * regression Spec 035 left open; no test asserted the bulk path before this.
 *
 * These tests drive the real `BulkAction::make('markIgnored')` / `Action::
 * make('ignore')` closures defined in ProblemProducts::table() via
 * `Livewire::test()->callTableBulkAction()` / `callTableAction()` — not a
 * reimplementation of their bodies. That requires ProblemProducts::table()'s
 * own query (`problemQuery()`) to actually run, which hits a pre-existing,
 * unrelated sqlite/REGEXP bug (tracked as F12 — see ProductResourceTest,
 * CategoryResourceHealthTest, LandingPageResourceTest for the same note).
 * Those three tests route around it by testing a DIFFERENT, unaffected
 * Livewire component; that option doesn't exist here because the page under
 * test IS ProblemProducts. Registering a REGEXP callback on the sqlite PDO
 * connection (MySQL — production's driver — already supports REGEXP natively)
 * is a test-environment-only fix, not a change to app behavior, and lets the
 * table mount so the real bulk action can be driven end-to-end.
 */
class ProblemProductsBulkIgnoreTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        DB::connection()->getPdo()->sqliteCreateFunction(
            'REGEXP',
            fn (string $pattern, ?string $subject) => preg_match('/' . $pattern . '/i', (string) $subject) === 1 ? 1 : 0,
            2
        );

        $this->admin = User::factory()->create(['email' => 'admin-problem-products@pw2d.com']);

        Tenant::create(['id' => 'problem-products-bulk-tenant', 'name' => 'Problem Products Bulk Tenant']);
        $this->tenant = Tenant::find('problem-products-bulk-tenant');
        tenancy()->initialize($this->tenant);

        $this->actingAs($this->admin);
        Filament::setTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    /** A product that matches ProblemProducts::problemQuery() (missing ai_summary is the simplest trigger). */
    private function makeProblemProduct(Category $category): Product
    {
        return Product::factory()->create([
            'category_id' => $category->id,
            'is_ignored'  => false,
            'status'      => null,
            'ai_summary'  => null,
        ]);
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

    private function jobTargetsPage(AuditLandingPageFreshnessJob $job, int $landingPageId): bool
    {
        $ref  = new \ReflectionClass($job);
        $prop = $ref->getProperty('landingPageId');
        $prop->setAccessible(true);

        return $prop->getValue($job) === $landingPageId;
    }

    /** @test */
    public function bulk_mark_as_ignored_dispatches_the_freshness_audit_for_every_referencing_page(): void
    {
        $category = Category::factory()->create();
        // A separate category per page — `landing_pages` is unique on (tenant_id, category_id).
        $pageCategoryA = Category::factory()->create();
        $pageCategoryB = Category::factory()->create();

        $productA = $this->makeProblemProduct($category);
        $productB = $this->makeProblemProduct($category);

        $pageA = $this->makePageWithPick($pageCategoryA, $productA);
        $pageB = $this->makePageWithPick($pageCategoryB, $productB);

        Queue::fake([AuditLandingPageFreshnessJob::class]);

        $component = Livewire::test(ProblemProducts::class);
        $component->assertSuccessful();

        $component->callTableBulkAction('markIgnored', [$productA->id, $productB->id]);
        $component->assertSuccessful();

        $this->assertTrue($productA->fresh()->is_ignored);
        $this->assertTrue($productB->fresh()->is_ignored);

        $component->assertNotified('2 products marked as ignored');

        Queue::assertPushed(
            AuditLandingPageFreshnessJob::class,
            fn (AuditLandingPageFreshnessJob $job) => $this->jobTargetsPage($job, $pageA->id)
        );
        Queue::assertPushed(
            AuditLandingPageFreshnessJob::class,
            fn (AuditLandingPageFreshnessJob $job) => $this->jobTargetsPage($job, $pageB->id)
        );
    }

    /** @test */
    public function bulk_mark_as_ignored_does_not_dispatch_for_a_page_that_does_not_reference_any_selected_product(): void
    {
        $category = Category::factory()->create();
        $pageCategorySelected   = Category::factory()->create();
        $pageCategoryUnselected = Category::factory()->create();

        $selected   = $this->makeProblemProduct($category);
        $unselected = $this->makeProblemProduct($category);

        $this->makePageWithPick($pageCategorySelected, $selected);
        $unrelatedPage = $this->makePageWithPick($pageCategoryUnselected, $unselected);

        Queue::fake([AuditLandingPageFreshnessJob::class]);

        Livewire::test(ProblemProducts::class)
            ->callTableBulkAction('markIgnored', [$selected->id]);

        $this->assertFalse($unselected->fresh()->is_ignored);

        Queue::assertNotPushed(
            AuditLandingPageFreshnessJob::class,
            fn (AuditLandingPageFreshnessJob $job) => $this->jobTargetsPage($job, $unrelatedPage->id)
        );
    }

    /**
     * Guards `ProblemProducts.php:325` (the single-record Ignore row action)
     * against being "optimised" into a bulk update later — see docs/tasks/
     * todo.md Q11 (WONTFIX, Spec 036 §3): per-record saves are load-bearing
     * here specifically because that advice is what produced the :347 bug.
     */
    /** @test */
    public function single_record_ignore_action_still_dispatches_the_freshness_audit(): void
    {
        $category = Category::factory()->create();
        $product  = $this->makeProblemProduct($category);
        $page     = $this->makePageWithPick($category, $product);

        Queue::fake([AuditLandingPageFreshnessJob::class]);

        $component = Livewire::test(ProblemProducts::class);
        $component->assertSuccessful();

        $component->callTableAction('ignore', $product);
        $component->assertSuccessful();

        $this->assertTrue($product->fresh()->is_ignored);
        $component->assertNotified('Product ignored: ' . $product->name);

        Queue::assertPushed(
            AuditLandingPageFreshnessJob::class,
            fn (AuditLandingPageFreshnessJob $job) => $this->jobTargetsPage($job, $page->id)
        );
    }
}
