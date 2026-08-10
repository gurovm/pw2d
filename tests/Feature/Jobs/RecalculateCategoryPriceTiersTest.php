<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\RecalculateCategoryPriceTiers;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 029 §A4 / Q13 — chunked (not unbounded ->get()) category-scoped price-tier
 * recalculation, dispatched after ingestion updates a price on an existing offer.
 */
class RecalculateCategoryPriceTiersTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'price-tier-job-tenant', 'name' => 'Price Tier Job Tenant']);
        $this->tenant = Tenant::find('price-tier-job-tenant');
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
    public function it_corrects_a_stale_price_tier_using_the_categorys_thresholds(): void
    {
        $category = Category::factory()->create(['budget_max' => 50, 'midrange_max' => 150]);
        $product  = Product::factory()->create(['category_id' => $category->id, 'price_tier' => 1]);
        ProductOffer::create([
            'product_id'    => $product->id,
            'store_id'      => null,
            'url'           => 'https://example.com/p',
            'raw_title'     => $product->name,
            'scraped_price' => 199.99, // premium tier (3), stale tier says budget (1)
        ]);

        (new RecalculateCategoryPriceTiers($this->tenant->id, $category->id))->handle(
            app(\App\Services\PriceTierRecalculator::class)
        );

        $this->assertSame(3, $product->fresh()->price_tier);
    }

    /** @test */
    public function it_is_a_no_op_for_an_unknown_category(): void
    {
        // Must not throw — the category may have been deleted between dispatch and execution.
        (new RecalculateCategoryPriceTiers($this->tenant->id, 999999))->handle(
            app(\App\Services\PriceTierRecalculator::class)
        );

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function it_is_a_no_op_for_an_unknown_tenant(): void
    {
        tenancy()->end();

        (new RecalculateCategoryPriceTiers('does-not-exist', 1))->handle(
            app(\App\Services\PriceTierRecalculator::class)
        );

        $this->addToAssertionCount(1);
    }
}
