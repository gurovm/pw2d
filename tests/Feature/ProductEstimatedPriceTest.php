<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductEstimatedPriceTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(float|null $price): Product
    {
        $category = Category::factory()->create();

        return Product::factory()->create([
            'category_id'   => $category->id,
            'scraped_price' => $price,
        ]);
    }

    #[Test]
    public function it_returns_null_when_scraped_price_is_null(): void
    {
        $product = $this->makeProduct(null);

        $this->assertNull($product->estimated_price);
    }

    #[Test]
    public function it_rounds_prices_under_100_to_nearest_5(): void
    {
        // Rounds down
        $this->assertEquals('~$50', $this->makeProduct(52.10)->estimated_price);
        // Rounds up
        $this->assertEquals('~$55', $this->makeProduct(54.99)->estimated_price);
        // Exact multiple — stays
        $this->assertEquals('~$30', $this->makeProduct(30.00)->estimated_price);
        // Low price
        $this->assertEquals('~$10', $this->makeProduct(9.99)->estimated_price);
        // Boundary: exactly 99.99 rounds to nearest 5
        $this->assertEquals('~$100', $this->makeProduct(99.99)->estimated_price);
    }

    #[Test]
    public function it_rounds_prices_at_or_above_100_to_nearest_10(): void
    {
        // Rounds down
        $this->assertEquals('~$140', $this->makeProduct(144.99)->estimated_price);
        // Rounds up
        $this->assertEquals('~$150', $this->makeProduct(146.00)->estimated_price);
        // Exact multiple — stays
        $this->assertEquals('~$200', $this->makeProduct(200.00)->estimated_price);
        // High price
        $this->assertEquals('~$500', $this->makeProduct(499.95)->estimated_price);
    }

    #[Test]
    public function it_prefixes_the_estimated_price_with_tilde_dollar(): void
    {
        $product = $this->makeProduct(29.99);

        $this->assertStringStartsWith('~$', $product->estimated_price);
    }

    #[Test]
    public function it_returns_integer_amount_with_no_decimals(): void
    {
        $product = $this->makeProduct(54.99);

        // Should be '~$55', not '~$55.00'
        $this->assertEquals('~$55', $product->estimated_price);
    }
}
