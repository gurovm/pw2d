<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\AssessCategoryHealth;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Tenant;
use App\Support\CategoryHealthRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 032 — AssessCategoryHealth + CategoryHealthRow: the reasons contract,
 * and the "one definition of purchasable" regression this spec exists to
 * protect (see the last test).
 */
class AssessCategoryHealthTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'cat-health-tenant', 'name' => 'Cat Health Tenant']);
        $this->tenant = Tenant::find('cat-health-tenant');
        tenancy()->initialize($this->tenant);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    private function offer(Product $product, array $overrides = []): ProductOffer
    {
        static $n = 0;
        $n++;

        return ProductOffer::create(array_merge([
            'product_id'    => $product->id,
            'url'           => "https://example.com/offer-{$n}",
            'raw_title'     => $product->name,
            'scraped_price' => 50,
        ], $overrides));
    }

    private function row(Category $category): CategoryHealthRow
    {
        return (new AssessCategoryHealth())->execute($this->tenant)
            ->firstWhere('categoryId', $category->id);
    }

    /** @test */
    public function pool_excludes_ignored_and_pending_status_products(): void
    {
        $category = Category::factory()->create();

        Product::factory()->create(['category_id' => $category->id, 'is_ignored' => true]);
        Product::factory()->create(['category_id' => $category->id, 'status' => 'pending_ai']);
        $poolProduct = Product::factory()->create(['category_id' => $category->id]);
        $this->offer($poolProduct);

        $row = $this->row($category);

        $this->assertSame(3, $row->totalRows);
        $this->assertSame(1, $row->pool);
    }

    /** @test */
    public function buyable_requires_a_purchasable_offer(): void
    {
        $category = Category::factory()->create();

        $buyableProduct = Product::factory()->create(['category_id' => $category->id]);
        $this->offer($buyableProduct, ['scraped_price' => 50, 'condition' => 'new']);

        $unbuyableProduct = Product::factory()->create(['category_id' => $category->id]);
        $this->offer($unbuyableProduct, ['scraped_price' => 50, 'condition' => 'refurbished']);

        $row = $this->row($category);

        $this->assertSame(2, $row->pool);
        $this->assertSame(1, $row->buyable, 'the refurbished-only product must count in pool but not buyable');
    }

    /** @test */
    public function never_checked_counts_pool_products_with_zero_health_stamped_offers(): void
    {
        $category = Category::factory()->create();

        $checked = Product::factory()->create(['category_id' => $category->id]);
        $this->offer($checked, ['health_checked_at' => now()->subDays(5)]);

        $unchecked = Product::factory()->create(['category_id' => $category->id]);
        $this->offer($unchecked, ['health_checked_at' => null]);

        $row = $this->row($category);

        $this->assertSame(1, $row->neverChecked);
        $this->assertContains('import_debt', $row->reasons());
        $this->assertSame('import_debt', $row->reasons()[0], 'import_debt must sort first');
    }

    /** @test */
    public function empty_pool_reports_no_data_only(): void
    {
        $category = Category::factory()->create();

        $row = $this->row($category);

        $this->assertSame(0, $row->pool);
        $this->assertSame(['no_data'], $row->reasons());
        $this->assertFalse($row->isHealthy());
    }

    /**
     * >=45 buyable products in both isolation tests below so `thin` never
     * co-fires — matches AuditLandingPageFreshnessTest's convention of
     * asserting the EXACT reasons array only where a trigger truly isolates.
     */
    private function seedBuyablePool(Category $category, int $count, \Carbon\CarbonInterface $healthCheckedAt): void
    {
        for ($i = 0; $i < $count; $i++) {
            $product = Product::factory()->create(['category_id' => $category->id]);
            $this->offer($product, ['health_checked_at' => $healthCheckedAt, 'condition' => 'new']);
        }
    }

    /** @test */
    public function stale_fires_when_oldest_check_exceeds_30_days(): void
    {
        $category = Category::factory()->create();
        $this->seedBuyablePool($category, 45, now()->subDays(31));

        $row = $this->row($category);

        $this->assertSame(['stale'], $row->reasons());
    }

    /** @test */
    public function aging_fires_when_oldest_check_exceeds_21_but_not_30_days(): void
    {
        $category = Category::factory()->create();
        $this->seedBuyablePool($category, 45, now()->subDays(25));

        $row = $this->row($category);

        $this->assertSame(['aging'], $row->reasons());
    }

    /** @test */
    public function thin_fires_when_buyable_is_below_45(): void
    {
        $category = Category::factory()->create();

        for ($i = 0; $i < 10; $i++) {
            $product = Product::factory()->create(['category_id' => $category->id]);
            $this->offer($product, ['health_checked_at' => now()]);
        }

        $row = $this->row($category);

        $this->assertSame(10, $row->buyable);
        $this->assertContains('thin', $row->reasons());
    }

    /** @test */
    public function churn_fires_when_unbuyable_share_reaches_20_percent(): void
    {
        $category = Category::factory()->create();

        // 4 buyable, 1 unbuyable -> pool 5, buyable 4, unbuyable 20%.
        for ($i = 0; $i < 4; $i++) {
            $product = Product::factory()->create(['category_id' => $category->id]);
            $this->offer($product, ['health_checked_at' => now(), 'condition' => 'new']);
        }

        $unbuyable = Product::factory()->create(['category_id' => $category->id]);
        $this->offer($unbuyable, ['health_checked_at' => now(), 'condition' => 'refurbished']);

        $row = $this->row($category);

        $this->assertSame(5, $row->pool);
        $this->assertSame(4, $row->buyable);
        $this->assertEqualsWithDelta(0.20, $row->unbuyablePct(), 0.0001);
        $this->assertContains('churn', $row->reasons());
    }

    /** @test */
    public function several_reasons_can_apply_at_once_with_import_debt_first(): void
    {
        $category = Category::factory()->create();

        // One never-checked (import_debt) + total pool stays under 45 (thin).
        $product = Product::factory()->create(['category_id' => $category->id]);
        $this->offer($product, ['health_checked_at' => null]);

        $row     = $this->row($category);
        $reasons = $row->reasons();

        $this->assertContains('import_debt', $reasons);
        $this->assertContains('thin', $reasons);
        $this->assertSame('import_debt', $reasons[0]);
    }

    /** @test */
    public function healthy_category_reports_an_empty_reasons_list(): void
    {
        $category = Category::factory()->create();

        for ($i = 0; $i < 45; $i++) {
            $product = Product::factory()->create(['category_id' => $category->id]);
            $this->offer($product, ['health_checked_at' => now(), 'condition' => 'new']);
        }

        $row = $this->row($category);

        $this->assertSame([], $row->reasons());
        $this->assertTrue($row->isHealthy());
    }

    /** @test */
    public function tenant_isolation_a_second_tenants_category_never_leaks_in(): void
    {
        $categoryA = Category::factory()->create();
        Product::factory()->create(['category_id' => $categoryA->id]);

        tenancy()->end();
        Tenant::create(['id' => 'cat-health-tenant-b', 'name' => 'Tenant B']);
        $tenantB = Tenant::find('cat-health-tenant-b');
        tenancy()->initialize($tenantB);

        $categoryB = Category::factory()->create();
        Product::factory()->create(['category_id' => $categoryB->id]);

        $rowsB = (new AssessCategoryHealth())->execute($tenantB);

        $this->assertCount(1, $rowsB);
        $this->assertSame($categoryB->id, $rowsB->first()->categoryId);

        tenancy()->end();
        tenancy()->initialize($this->tenant);
    }

    /** @test */
    public function decorate_stays_sortable_and_paginatable(): void
    {
        $category = Category::factory()->create();
        $product  = Product::factory()->create(['category_id' => $category->id]);
        $this->offer($product, ['health_checked_at' => now()]);

        $query = AssessCategoryHealth::decorate(Category::query());

        $paginated = $query->orderBy('buyable_count', 'desc')->paginate(5);

        $this->assertGreaterThanOrEqual(1, $paginated->total());
        $record = $paginated->firstWhere('id', $category->id);
        $this->assertNotNull($record);
        $this->assertSame(1, (int) $record->buyable_count);
        $this->assertNotNull($record->oldest_check);
    }

    /**
     * The regression this whole spec exists to protect: buyable_count must
     * never drift from ProductCompare::scoredProducts()->count() for the same
     * category — both claim to answer "what can a reader buy here".
     */
    /** @test */
    public function buyable_count_agrees_with_product_compare_scored_products_count(): void
    {
        $category = Category::factory()->create(['slug' => 'compare-parity-category']);

        // ProductFactory does not stamp a slug (that's a real-pipeline concern);
        // ProductCompare's full render (SeoSchema) needs one on every product.
        $buyable = Product::factory()->create(['category_id' => $category->id, 'slug' => 'compare-parity-buyable']);
        $this->offer($buyable, ['scraped_price' => 50, 'condition' => 'new']);

        $unbuyableCondition = Product::factory()->create(['category_id' => $category->id, 'slug' => 'compare-parity-condition']);
        $this->offer($unbuyableCondition, ['scraped_price' => 50, 'condition' => 'refurbished']);

        $unbuyableFlag = Product::factory()->create(['category_id' => $category->id, 'slug' => 'compare-parity-flag']);
        $this->offer($unbuyableFlag, ['scraped_price' => 50, 'condition' => 'new', 'listing_flags' => ['high_price']]);

        $ignored = Product::factory()->create(['category_id' => $category->id, 'is_ignored' => true, 'slug' => 'compare-parity-ignored']);
        $this->offer($ignored, ['scraped_price' => 50, 'condition' => 'new']);

        $row = $this->row($category);

        $compareComponent = \Livewire\Livewire::test(\App\Livewire\ProductCompare::class, ['slug' => $category->slug]);
        $scoredCount       = $compareComponent->instance()->scoredProducts->count();

        $this->assertSame($scoredCount, $row->buyable);
    }
}
