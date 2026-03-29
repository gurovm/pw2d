<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductEstimatedPriceTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(float|null $price): Product
    {
        $category = Category::factory()->create();

        $product = Product::factory()->create([
            'category_id' => $category->id,
        ]);

        if ($price !== null) {
            $store = Store::firstOrCreate(
                ['slug' => 'amazon', 'tenant_id' => $product->tenant_id],
                ['name' => 'Amazon']
            );

            ProductOffer::create([
                'product_id'    => $product->id,
                'tenant_id'     => $product->tenant_id,
                'store_id'      => $store->id,
                'url'           => 'https://www.amazon.com/dp/TEST',
                'scraped_price' => $price,
                'raw_title'     => $product->name,
            ]);
        }

        // Reload offers relationship
        $product->load('offers');

        return $product;
    }

    #[Test]
    public function it_returns_null_when_no_offers_have_price(): void
    {
        $product = $this->makeProduct(null);

        $this->assertNull($product->estimated_price);
    }

    #[Test]
    public function it_rounds_prices_under_100_to_nearest_5(): void
    {
        $this->assertEquals(50, $this->makeProduct(52.10)->estimated_price);
        $this->assertEquals(55, $this->makeProduct(54.99)->estimated_price);
        $this->assertEquals(30, $this->makeProduct(30.00)->estimated_price);
        $this->assertEquals(10, $this->makeProduct(9.99)->estimated_price);
        $this->assertEquals(100, $this->makeProduct(99.99)->estimated_price);
    }

    #[Test]
    public function it_rounds_prices_at_or_above_100_to_nearest_10(): void
    {
        $this->assertEquals(140, $this->makeProduct(144.99)->estimated_price);
        $this->assertEquals(150, $this->makeProduct(146.00)->estimated_price);
        $this->assertEquals(200, $this->makeProduct(200.00)->estimated_price);
        $this->assertEquals(500, $this->makeProduct(499.95)->estimated_price);
    }

    #[Test]
    public function it_returns_a_raw_integer_with_no_formatting(): void
    {
        $product = $this->makeProduct(54.99);

        $this->assertIsInt($product->estimated_price);
        $this->assertEquals(55, $product->estimated_price);
    }
}
