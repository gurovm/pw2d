<?php

declare(strict_types=1);

namespace Tests\Feature\VsPages;

use App\Actions\SelectRivals;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SelectRivalsTest extends TestCase
{
    use RefreshDatabase, VsFixtures;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initTenant('rivals-tenant');
        $this->category = Category::factory()->create(['name' => 'Espresso', 'slug' => 'espresso']);
    }

    protected function tearDown(): void
    {
        $this->endTenancy();
        parent::tearDown();
    }

    private function rivalNames(Product $p): array
    {
        return app(SelectRivals::class)->handle($p)->pluck('name')->all();
    }

    /** @test */
    public function it_orders_by_price_distance_then_editorial_score_and_limits_to_three(): void
    {
        $f = $this->makeFeature($this->category, 'Speed');
        $base = $this->makeLiveProduct($this->category, 'Base 100', 1000, [$f->id => 50]);

        $this->makeLiveProduct($this->category, 'Near Low Score 200', 1100, [$f->id => 40]);
        $this->makeLiveProduct($this->category, 'Near High Score 300', 900, [$f->id => 90]);
        $this->makeLiveProduct($this->category, 'Closest 400', 1010, [$f->id => 10]);
        $this->makeLiveProduct($this->category, 'Far 500', 1350, [$f->id => 99]);

        $this->assertSame(
            ['Closest 400', 'Near High Score 300', 'Near Low Score 200'],
            $this->rivalNames($base),
        );
    }

    /** @test */
    public function it_applies_the_forty_percent_price_window(): void
    {
        $base = $this->makeLiveProduct($this->category, 'Base 100', 1000);
        $this->makeLiveProduct($this->category, 'In Window 200', 1400);
        $this->makeLiveProduct($this->category, 'Too Dear 300', 1500);
        $this->makeLiveProduct($this->category, 'Too Cheap 400', 500);

        $this->assertSame(['In Window 200'], $this->rivalNames($base));
    }

    /** @test */
    public function it_excludes_other_categories_hidden_pending_and_ineligible_products(): void
    {
        $base = $this->makeLiveProduct($this->category, 'Base 100', 1000);

        $other = Category::factory()->create(['name' => 'Grinders', 'slug' => 'grinders']);
        $this->makeLiveProduct($other, 'Other Category 200', 1000);
        $this->makeLiveProduct($this->category, 'Hidden 300', 1000, [], ['is_ignored' => true]);
        $this->makeLiveProduct($this->category, 'Pending 400', 1000, [], ['status' => 'pending']);

        $unchecked = $this->makeLiveProduct($this->category, 'Unchecked 500', 1000);
        ProductOffer::where('product_id', $unchecked->id)->update(['health_checked_at' => null]);

        $flagged = $this->makeLiveProduct($this->category, 'Flagged 600', 1000);
        ProductOffer::where('product_id', $flagged->id)->update(['listing_flags' => ['unavailable']]);

        $ok = $this->makeLiveProduct($this->category, 'Fine 700', 1000);

        $this->assertSame(['Fine 700'], $this->rivalNames($base));
        $this->assertNotNull($ok);
    }

    /** @test */
    public function it_excludes_the_same_model_but_keeps_other_models_of_the_same_brand(): void
    {
        $brand = Brand::factory()->create(['name' => 'Jura']);
        $base  = $this->makeLiveProduct($this->category, 'Jura E8 Black', 1000, [], [], $brand);

        $this->makeLiveProduct($this->category, 'Jura E8 White', 1000, [], [], $brand);
        $this->makeLiveProduct($this->category, 'Jura E6 Black', 1050, [], [], $brand);

        $this->assertSame(['Jura E6 Black'], $this->rivalNames($base));
    }

    /** @test */
    public function a_product_without_a_price_has_no_rivals(): void
    {
        $base = $this->makeLiveProduct($this->category, 'Base 100', 1000);
        $this->makeLiveProduct($this->category, 'Other 200', 1000);
        ProductOffer::where('product_id', $base->id)->update(['scraped_price' => null]);

        $this->assertSame([], $this->rivalNames($base->fresh()));
    }

    /** @test */
    public function the_result_is_cached_per_tenant_and_product(): void
    {
        $base = $this->makeLiveProduct($this->category, 'Base 100', 1000);
        $rival = $this->makeLiveProduct($this->category, 'Rival 200', 1000);

        $this->assertSame(['Rival 200'], $this->rivalNames($base));

        // A new, closer rival added after the first call is not seen within the 24h cache.
        $this->makeLiveProduct($this->category, 'Newcomer 300', 1000);
        $this->assertSame(['Rival 200'], $this->rivalNames($base));

        // ...but a rival hidden after caching is dropped on the way out.
        $rival->update(['is_ignored' => true]);
        $this->assertSame([], $this->rivalNames($base));
    }

    /** @test */
    public function the_cache_key_is_tenant_scoped(): void
    {
        $base = $this->makeLiveProduct($this->category, 'Base 100', 1000);
        $this->makeLiveProduct($this->category, 'Rival 200', 1000);
        $this->rivalNames($base);

        $this->assertTrue(\Illuminate\Support\Facades\Cache::has("trivals-tenant:rivals:{$base->id}:3"));
    }
}
