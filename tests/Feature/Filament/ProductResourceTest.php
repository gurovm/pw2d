<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Perf M2 (2026-08-16 audit) — ProductResource's `best_price` table column.
 *
 * NOTE on scope: same F12 workaround as LandingPageResourceTest — full HTTP
 * requests through the admin panel layout are blocked by a pre-existing,
 * unrelated sqlite/REGEXP bug in ProblemProducts::getNavigationBadge().
 * `Livewire::test()` on the ListRecords page directly sidesteps it.
 */
class ProductResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['email' => 'admin@pw2d.com']);

        Tenant::create(['id' => 'product-resource-tenant', 'name' => 'Product Resource Tenant']);
        $this->tenant = Tenant::find('product-resource-tenant');
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
    public function best_price_column_reflects_the_filtered_price_matching_the_public_site(): void
    {
        $category = Category::factory()->create(['slug' => 'product-resource-cat']);
        $product  = Product::factory()->create([
            'category_id' => $category->id,
            'slug'        => 'product-resource-item',
        ]);

        $storeA = Store::create([
            'tenant_id'       => $this->tenant->id,
            'name'            => 'Amazon',
            'slug'            => 'amazon-pr-' . uniqid(),
            'commission_rate' => 0,
            'priority'        => 0,
            'is_active'       => true,
        ]);
        $storeB = Store::create([
            'tenant_id'       => $this->tenant->id,
            'name'            => 'Whole Latte Love',
            'slug'            => 'wll-pr-' . uniqid(),
            'commission_rate' => 0,
            'priority'        => 0,
            'is_active'       => true,
        ]);

        // The CHEAPEST offer is refurbished — must be excluded from the displayed
        // price, exactly like the public site's best_price/estimated_price.
        ProductOffer::create([
            'product_id'    => $product->id,
            'store_id'      => $storeA->id,
            'tenant_id'     => $this->tenant->id,
            'url'           => 'https://example.com/product-resource-item-cheap',
            'raw_title'     => 'Cheap refurbished listing',
            'scraped_price' => 50.00,
            'condition'     => 'refurbished',
        ]);
        ProductOffer::create([
            'product_id'    => $product->id,
            'store_id'      => $storeB->id,
            'tenant_id'     => $this->tenant->id,
            'url'           => 'https://example.com/product-resource-item-clean',
            'raw_title'     => 'Clean listing',
            'scraped_price' => 120.00,
            'condition'     => 'new',
        ]);

        $component = Livewire::test(ListProducts::class);

        $records = $component->instance()->getTable()->getRecords();
        $record  = $records->firstWhere('id', $product->id);

        $this->assertNotNull($record);
        $this->assertEquals('120.00', (string) $record->best_price, 'the admin list must show the clean $120 offer, not the refurbished $50 one');
    }
}
