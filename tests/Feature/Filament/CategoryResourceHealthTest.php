<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\CategoryResource\Pages\ListCategories;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Spec 032 — CategoryResource health columns. Same F12 workaround as
 * ProductResourceTest/LandingPageResourceTest: full HTTP requests through the
 * admin panel layout hit a pre-existing, unrelated sqlite/REGEXP bug in
 * ProblemProducts::getNavigationBadge(); Livewire::test() on the ListRecords
 * page directly sidesteps it.
 */
class CategoryResourceHealthTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['email' => 'admin-cat-health@pw2d.com']);

        Tenant::create(['id' => 'category-resource-health-tenant', 'name' => 'Category Resource Health Tenant']);
        $this->tenant = Tenant::find('category-resource-health-tenant');
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

    /** @test */
    public function the_decorated_columns_are_present_and_correct_on_every_record(): void
    {
        $category = Category::factory()->create(['name' => 'Health Column Category']);

        $buyable = Product::factory()->create(['category_id' => $category->id]);
        ProductOffer::create([
            'product_id'        => $buyable->id,
            'url'               => 'https://example.com/health-col-buyable',
            'raw_title'         => 'Buyable',
            'scraped_price'     => 50,
            'condition'         => 'new',
            'health_checked_at' => now(),
        ]);

        $neverChecked = Product::factory()->create(['category_id' => $category->id]);
        ProductOffer::create([
            'product_id'        => $neverChecked->id,
            'url'               => 'https://example.com/health-col-unchecked',
            'raw_title'         => 'Unchecked',
            'scraped_price'     => 50,
            'health_checked_at' => null,
        ]);

        $component = Livewire::test(ListCategories::class);

        $records = $component->instance()->getTable()->getRecords();
        $record  = $records->firstWhere('id', $category->id);

        $this->assertNotNull($record);
        $this->assertSame(2, (int) $record->products_count);
        $this->assertSame(2, (int) $record->pool_count);
        // Both offers are purchasable (priced, clean condition, no flags) — a null
        // health_checked_at means "never DOM-verified", not "unbuyable"; the two
        // are deliberately independent signals (import_debt vs. buyable).
        $this->assertSame(2, (int) $record->buyable_count);
        $this->assertSame(1, (int) $record->never_checked_count);
        $this->assertNotNull($record->oldest_check);
    }

    /** @test */
    public function the_table_query_can_still_be_sorted_by_buyable_count(): void
    {
        $lowBuyable = Category::factory()->create(['name' => 'Low Buyable']);
        $product1   = Product::factory()->create(['category_id' => $lowBuyable->id]);
        ProductOffer::create([
            'product_id'    => $product1->id,
            'url'           => 'https://example.com/health-sort-low',
            'raw_title'     => 'Low',
            'scraped_price' => 50,
            'condition'     => 'new',
        ]);

        $highBuyable = Category::factory()->create(['name' => 'High Buyable']);
        foreach (range(1, 3) as $i) {
            $product = Product::factory()->create(['category_id' => $highBuyable->id]);
            ProductOffer::create([
                'product_id'    => $product->id,
                'url'           => "https://example.com/health-sort-high-{$i}",
                'raw_title'     => 'High',
                'scraped_price' => 50,
                'condition'     => 'new',
            ]);
        }

        $component = Livewire::test(ListCategories::class)
            ->sortTable('buyable_count', 'desc');

        $records = $component->instance()->getTable()->getRecords()->values();

        $this->assertSame($highBuyable->id, $records->first()->id);
    }

    /** @test */
    public function default_sort_is_oldest_check_ascending(): void
    {
        $older = Category::factory()->create(['name' => 'Older']);
        $productOld = Product::factory()->create(['category_id' => $older->id]);
        ProductOffer::create([
            'product_id'        => $productOld->id,
            'url'               => 'https://example.com/health-default-sort-old',
            'raw_title'         => 'Old',
            'scraped_price'     => 50,
            'condition'         => 'new',
            'health_checked_at' => now()->subDays(20),
        ]);

        $newer = Category::factory()->create(['name' => 'Newer']);
        $productNew = Product::factory()->create(['category_id' => $newer->id]);
        ProductOffer::create([
            'product_id'        => $productNew->id,
            'url'               => 'https://example.com/health-default-sort-new',
            'raw_title'         => 'New',
            'scraped_price'     => 50,
            'condition'         => 'new',
            'health_checked_at' => now()->subDays(1),
        ]);

        $component = Livewire::test(ListCategories::class);

        $records = $component->instance()->getTable()->getRecords()->values();
        $ids     = $records->pluck('id')->all();

        $this->assertLessThan(
            array_search($newer->id, $ids, true),
            array_search($older->id, $ids, true),
            'the older (oldest_check ASC) category must sort first by default'
        );
    }

    /**
     * Spec 032 "Performance" section: the owner's central question was whether
     * this is too heavy for the admin list, answered by "`decorate()` is one
     * query — row count does not change the query count." Nothing before this
     * test actually asserted that claim. `AssessCategoryHealthTest` proves the
     * SQL is correct; this proves the *shape* holds when Filament's column
     * closures (health/buyable/never_checked/oldest_check, all reading
     * already-decorated attributes) run over every rendered row.
     *
     * A future change that adds a relation access inside one of those closures
     * (e.g. `$record->products` instead of `$record->products_count`) would
     * reintroduce a per-row N+1 without breaking a single existing assertion —
     * this is the test that would catch it, by proving query count is
     * INVARIANT to row count rather than asserting a brittle magic number.
     *
     * @test
     */
    public function decorated_table_query_count_does_not_grow_with_category_count(): void
    {
        $this->seedCategoriesWithHealthData(3);

        // First Livewire::test() call mounts + renders at the default page
        // size (10); only the query cost of materialising *every* row (via
        // tableRecordsPerPage=all) is what should stay flat as row count
        // grows, so measurement starts after that initial mount.
        $small = Livewire::test(ListCategories::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $small->set('tableRecordsPerPage', 'all');
        $smallQueryCount = count(DB::getQueryLog());
        $smallRowCount   = Category::query()->count();
        DB::flushQueryLog();

        $this->assertGreaterThan(0, $smallQueryCount);

        // Substantially more categories, each with the same buyable /
        // unbuyable / never-checked mix, so every column closure still has
        // real, varied data to read on every row. Row counts are read back
        // (not hardcoded) because seedCategoriesWithHealthData() also creates
        // a parent category per call, so "3" and "30" leaves become 4 and 35
        // total rows in the list, not 3 and 33.
        $this->seedCategoriesWithHealthData(30);

        $large = Livewire::test(ListCategories::class);
        DB::flushQueryLog();
        $large->set('tableRecordsPerPage', 'all');
        $largeQueryCount = count(DB::getQueryLog());
        $largeRowCount   = Category::query()->count();
        DB::disableQueryLog();

        $this->assertGreaterThan($smallRowCount, $largeRowCount, 'the second seed must actually grow the row count, or this test proves nothing');

        $this->assertSame(
            $smallQueryCount,
            $largeQueryCount,
            "Query count must be invariant to row count: {$smallQueryCount} queries for {$smallRowCount} categories vs. "
            . "{$largeQueryCount} for {$largeRowCount}. A growth here means a column closure (including the "
            . "pre-existing parent.name column) started lazy-loading a relation per row (the N+1 Spec 032 exists to avoid)."
        );

        // Loose ceiling, not a magic number — this only guards against the
        // count silently becoming "one query per row" while still passing
        // the invariance assertion above (e.g. if both runs happened to seed
        // the same effective row count). Generous headroom so an unrelated
        // Filament/Livewire framework bump doesn't make this flaky.
        $this->assertLessThan(20, $largeQueryCount);
    }

    /**
     * One category with a buyable, an unbuyable (refurbished), and a
     * never-checked (health_checked_at = null) product, so `pool_count`,
     * `buyable_count`, `never_checked_count`, and `oldest_check` are all
     * non-trivial on every seeded row — a column closure that starts reading
     * a relation instead of a decorated attribute would touch real data.
     *
     * Also gives most rows a non-null `parent_id`, matching production (all
     * 11 leaf categories sit under a parent there): `CategoryResource`'s
     * pre-existing `TextColumn::make('parent.name')` column resolves a null
     * parent_id without a query at all, so an all-top-level seed can never
     * exercise Filament's automatic eager-loading (or lack of it) for that
     * column. One row per batch is kept top-level so the null-parent path
     * stays covered too.
     */
    private function seedCategoriesWithHealthData(int $categoryCount): void
    {
        static $n = 0;

        $parent = Category::factory()->create(['name' => 'Parent ' . ++$n]);

        for ($i = 0; $i < $categoryCount; $i++) {
            $category = Category::factory()->create([
                'parent_id' => $i === 0 ? null : $parent->id,
            ]);

            $buyable = Product::factory()->create(['category_id' => $category->id]);
            ProductOffer::create([
                'product_id'        => $buyable->id,
                'url'               => 'https://example.com/qc-buyable-' . ++$n,
                'raw_title'         => 'Buyable',
                'scraped_price'     => 50,
                'condition'         => 'new',
                'health_checked_at' => now(),
            ]);

            $unbuyable = Product::factory()->create(['category_id' => $category->id]);
            ProductOffer::create([
                'product_id'        => $unbuyable->id,
                'url'               => 'https://example.com/qc-unbuyable-' . ++$n,
                'raw_title'         => 'Unbuyable',
                'scraped_price'     => 50,
                'condition'         => 'refurbished',
                'health_checked_at' => now(),
            ]);

            $neverChecked = Product::factory()->create(['category_id' => $category->id]);
            ProductOffer::create([
                'product_id'        => $neverChecked->id,
                'url'               => 'https://example.com/qc-unchecked-' . ++$n,
                'raw_title'         => 'Unchecked',
                'scraped_price'     => 50,
                'health_checked_at' => null,
            ]);
        }
    }
}
