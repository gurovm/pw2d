<?php

namespace Tests\Feature\Compare;

use App\Livewire\ProductCompare;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Feature;
use App\Models\Product;
use App\Models\ProductFeatureValue;
use App\Models\ProductOffer;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Product pages must not expose a crawlable ?limit= "Show More" link
 * (Googlebot chained limit=24..120 copies); comparison pages keep it.
 */
class ShowMoreCrawlLinkTest extends TestCase
{
    use RefreshDatabase;

    private function scaffold(int $count): array
    {
        $category = Category::factory()->create(['slug' => 'crawl-cat']);
        $feature  = Feature::factory()->create(['category_id' => $category->id]);
        $brand    = Brand::factory()->create();
        $store    = Store::create([
            'tenant_id' => null,
            'name'      => 'Crawl Store',
            'slug'      => 'crawl-store-' . uniqid(),
            'is_active' => true,
        ]);

        $products = collect();
        for ($i = 1; $i <= $count; $i++) {
            $product = Product::factory()->create([
                'category_id' => $category->id,
                'brand_id'    => $brand->id,
                'slug'        => "crawl-product-{$i}",
                'status'      => null,
                'is_ignored'  => false,
            ]);
            ProductFeatureValue::factory()->create([
                'product_id' => $product->id,
                'feature_id' => $feature->id,
                'raw_value'  => $i * 5.0,
            ]);
            ProductOffer::create([
                'product_id'    => $product->id,
                'store_id'      => $store->id,
                'tenant_id'     => null,
                'url'           => "https://example.com/crawl/{$i}",
                'scraped_price' => 50 + $i,
                'raw_title'     => "crawl product {$i}",
                'condition'     => 'new',
            ]);
            $products->push($product);
        }

        return [$category, $products];
    }

    /** @test */
    public function product_page_renders_button_without_limit_url(): void
    {
        [, $products] = $this->scaffold(14);

        $html = Livewire::test(ProductCompare::class, ['product' => $products->first()])->html();

        $this->assertStringContainsString('Show More Matches', $html);
        $this->assertStringContainsString('wire:click="loadMore"', $html);
        $this->assertStringNotContainsString('limit=', $html);
    }

    /** @test */
    public function comparison_page_keeps_crawlable_limit_link(): void
    {
        [$category] = $this->scaffold(14);

        $html = Livewire::test(ProductCompare::class, ['slug' => $category->slug])->html();

        $this->assertStringContainsString('Show More Matches', $html);
        $this->assertMatchesRegularExpression('/<a href="[^"]*limit=24[^"]*"/', $html);
    }

    /** @test */
    public function product_page_load_more_still_raises_display_limit(): void
    {
        [, $products] = $this->scaffold(14);

        Livewire::test(ProductCompare::class, ['product' => $products->first()])
            ->assertSet('displayLimit', 12)
            ->call('loadMore')
            ->assertSet('displayLimit', 24);
    }

    /** @test */
    public function is_product_page_flag_is_locked(): void
    {
        [$category] = $this->scaffold(14);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(ProductCompare::class, ['slug' => $category->slug])
            ->set('isProductPage', true);
    }

    /** @test */
    public function comparison_page_with_twelve_or_fewer_products_has_no_show_more(): void
    {
        [$category] = $this->scaffold(12);

        $html = Livewire::test(ProductCompare::class, ['slug' => $category->slug])->html();

        $this->assertStringNotContainsString('Show More Matches', $html);
    }
}
