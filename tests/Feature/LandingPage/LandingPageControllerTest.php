<?php

declare(strict_types=1);

namespace Tests\Feature\LandingPage;

use App\Models\Brand;
use App\Models\Category;
use App\Models\LandingPage;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Spec 027 §9.1, §9.3, §9.8 — LandingPageController route/render/cache tests.
 *
 * LandingPageController::show() explicitly aborts 404 when tenancy is not
 * initialized, so (unlike some other feature suites) tenancy must remain
 * initialized through the HTTP request — do NOT call tenancy()->end() before
 * $this->get() here. This mirrors SitemapCursorTest / SitemapContentsTest,
 * which hit a controller with the same tenancy()->initialized guard.
 */
class LandingPageControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'lp-controller-tenant', 'name' => 'LP Controller Tenant']);
        $this->tenant = Tenant::find('lp-controller-tenant');
        tenancy()->initialize($this->tenant);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * A fully eligible product: brand, image, and a priced offer so
     * estimated_price/affiliate_url/image_url all resolve without error.
     */
    private function makeProduct(Category $category, string $slug, array $overrides = []): Product
    {
        $brand = Brand::factory()->create();

        $product = Product::factory()->create(array_merge([
            'category_id' => $category->id,
            'brand_id'    => $brand->id,
            'slug'        => $slug,
            'ai_summary'  => 'A great product summary.',
            'is_ignored'  => false,
            'status'      => null,
        ], $overrides));

        ProductOffer::create([
            'product_id'    => $product->id,
            'store_id'      => null,
            'url'           => "https://amazon.com/{$slug}",
            'raw_title'     => $product->name,
            'scraped_price' => 249.99,
            'image_url'     => "https://images.example.com/{$slug}.jpg",
            'stock_status'  => 'in_stock',
        ]);

        return $product->fresh();
    }

    private function extractH1(string $html): ?string
    {
        preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $html, $matches);
        return $matches[1] ?? null;
    }

    // =========================================================================
    // §9.1 — Route/controller
    // =========================================================================

    /** @test */
    public function published_landing_page_renders_200_with_h1(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-ctrl-published', 'name' => 'Espresso Machines']);
        $product  = $this->makeProduct($category, 'lp-ctrl-published-product');

        $page = LandingPage::factory()->published()->create([
            'category_id' => $category->id,
            'slug'        => 'best-lp-ctrl-published',
            'picks'       => [
                ['product_id' => $product->id, 'role' => 'overall', 'headline' => 'Top Pick', 'body' => '<p>Great machine.</p>'],
            ],
        ]);

        $response = $this->get('/best/' . $page->slug);

        $response->assertStatus(200);

        $h1 = $this->extractH1($response->getContent());
        $this->assertNotNull($h1, 'Rendered page must contain an <h1>');
        $this->assertStringContainsString('Espresso Machines', $h1);
        $this->assertStringContainsString((string) date('Y'), $h1);

        // The pick card's "Full product details" link must open in a new tab
        // so closing the product drawer doesn't strand the reader off /best/.
        $productUrl = route('product.show', ['product' => $product->slug]);
        $this->assertMatchesRegularExpression(
            '/<a[^>]*href="' . preg_quote($productUrl, '/') . '"[^>]*target="_blank"[^>]*>\s*Full product details/s',
            $response->getContent(),
            'Full product details link must carry target="_blank"'
        );
    }

    /** @test */
    public function draft_landing_page_returns_404(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-ctrl-draft-cat']);
        $product  = $this->makeProduct($category, 'lp-ctrl-draft-product');

        $page = LandingPage::factory()->create([
            'category_id' => $category->id,
            'slug'        => 'best-lp-ctrl-draft',
            'status'      => 'draft',
            'picks'       => [
                ['product_id' => $product->id, 'role' => 'overall', 'headline' => 'h', 'body' => 'b'],
            ],
        ]);

        $this->get('/best/' . $page->slug)->assertStatus(404);
    }

    /** @test */
    public function unknown_slug_returns_404(): void
    {
        $this->get('/best/this-slug-does-not-exist-anywhere')->assertStatus(404);
    }

    /** @test */
    public function tenant_isolation_tenant_a_cannot_see_tenant_bs_landing_page(): void
    {
        // We're initialized as tenant "lp-controller-tenant" (tenant A) via setUp().
        Tenant::create(['id' => 'lp-controller-tenant-b', 'name' => 'LP Controller Tenant B']);
        $tenantB = Tenant::find('lp-controller-tenant-b');

        // Switch into tenant B's context to seed its data.
        tenancy()->end();
        tenancy()->initialize($tenantB);

        $categoryB = Category::factory()->create(['slug' => 'lp-ctrl-tenant-b-cat', 'name' => 'Tenant B Category']);
        $productB  = $this->makeProduct($categoryB, 'lp-ctrl-tenant-b-product');

        LandingPage::factory()->published()->create([
            'category_id' => $categoryB->id,
            'slug'        => 'shared-best-slug',
            'picks'       => [
                ['product_id' => $productB->id, 'role' => 'overall', 'headline' => 'h', 'body' => 'b'],
            ],
        ]);

        // Switch back to tenant A — tenancy stays initialized throughout, which
        // is what LandingPageController::show()'s tenancy()->initialized guard needs.
        tenancy()->end();
        tenancy()->initialize($this->tenant);

        $this->get('/best/shared-best-slug')->assertStatus(
            404,
            'Tenant A must not be able to resolve a landing page that belongs to Tenant B, even with a matching slug'
        );
    }

    // =========================================================================
    // §9.3 — Render resilience
    // =========================================================================

    /** @test */
    public function render_skips_deleted_product_in_picks_and_still_returns_200(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-ctrl-render-resilience']);
        $keptProduct    = $this->makeProduct($category, 'lp-ctrl-render-kept');
        $deletedProduct = $this->makeProduct($category, 'lp-ctrl-render-deleted');
        $deletedId      = $deletedProduct->id;
        $deletedProduct->delete();

        $page = LandingPage::factory()->published()->create([
            'category_id' => $category->id,
            'slug'        => 'best-lp-ctrl-render-resilience',
            'picks'       => [
                ['product_id' => $keptProduct->id, 'role' => 'overall', 'headline' => 'Kept Pick Unique Headline', 'body' => 'Body A'],
                ['product_id' => $deletedId, 'role' => 'budget', 'headline' => 'Deleted Pick Unique Headline', 'body' => 'Body B'],
            ],
        ]);

        $response = $this->get('/best/' . $page->slug);

        $response->assertStatus(200);
        $response->assertSee('Kept Pick Unique Headline');
        $response->assertDontSee('Deleted Pick Unique Headline');
    }

    /**
     * Review S2 — the H1/<title>/ItemList `name` must derive N from the
     * FILTERED render-time picks (2 stored, 1 deleted -> 1 renders), not the
     * stored `picks` JSON count (which would wrongly claim "The 2 Best...").
     *
     * @test
     */
    public function h1_and_title_reflect_the_filtered_pick_count_not_the_stored_count(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-ctrl-pickcount', 'name' => 'Pick Count Widgets']);
        $keptProduct    = $this->makeProduct($category, 'lp-ctrl-pickcount-kept');
        $deletedProduct = $this->makeProduct($category, 'lp-ctrl-pickcount-deleted');
        $deletedId      = $deletedProduct->id;
        $deletedProduct->delete();

        $page = LandingPage::factory()->published()->create([
            'category_id' => $category->id,
            'slug'        => 'best-lp-ctrl-pickcount',
            'picks'       => [
                ['product_id' => $keptProduct->id, 'role' => 'overall', 'headline' => 'h', 'body' => 'b'],
                ['product_id' => $deletedId, 'role' => 'budget', 'headline' => 'h2', 'body' => 'b2'],
            ],
        ]);

        $response = $this->get('/best/' . $page->slug);
        $html     = $response->getContent();

        $h1 = $this->extractH1($html);
        $this->assertNotNull($h1);
        $this->assertStringContainsString('The 1 Best', $h1, 'H1 must count only the 1 pick that survives render-time filtering');
        $this->assertStringNotContainsString('The 2 Best', $h1, 'H1 must NOT use the stored picks-JSON count (2)');

        preg_match('/<title>(.*?)<\/title>/s', $html, $titleMatch);
        $this->assertStringContainsString('The 1 Best', $titleMatch[1] ?? '', '<title> must also reflect the filtered count');

        $this->assertStringContainsString('"name":"The 1 Best', $html, 'ItemList JSON-LD name must also reflect the filtered count');
    }

    /** @test */
    public function estimated_price_is_shown_rounded_and_raw_scraped_price_is_never_rendered(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-ctrl-price-cat']);
        // scraped_price 249.99 -> estimated_price rounds to nearest 10 -> 250.
        $product  = $this->makeProduct($category, 'lp-ctrl-price-product');

        $page = LandingPage::factory()->published()->create([
            'category_id' => $category->id,
            'slug'        => 'best-lp-ctrl-price',
            'picks'       => [
                ['product_id' => $product->id, 'role' => 'overall', 'headline' => 'Price Pick Headline', 'body' => 'Body'],
            ],
        ]);

        $html = $this->get('/best/' . $page->slug)->getContent();

        $this->assertStringContainsString('250', $html, 'Rounded estimated_price (250) must be displayed');
        $this->assertStringNotContainsString('249.99', $html, 'Raw scraped_price must never be rendered');
        $this->assertStringNotContainsString('249.9', $html, 'Raw scraped_price fragment must never be rendered');
    }

    // =========================================================================
    // §9.8 — Cache invalidation
    // =========================================================================

    /** @test */
    public function saving_the_landing_page_forgets_its_view_model_cache_key_and_the_sitemap_cache_key(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-ctrl-cache-cat']);
        $page     = LandingPage::factory()->create([
            'category_id' => $category->id,
            'slug'        => 'best-lp-ctrl-cache',
        ]);

        $sitemapKey = tenant_cache_key('sitemap:xml');

        Cache::put($page->cacheKey(), ['stale' => true], 3600);
        Cache::put($sitemapKey, '<xml>stale</xml>', 600);

        $this->assertTrue(Cache::has($page->cacheKey()));
        $this->assertTrue(Cache::has($sitemapKey));

        $page->intro = 'Updated intro after save.';
        $page->save();

        $this->assertFalse(Cache::has($page->cacheKey()), 'Saving must forget the landing page view-model cache key');
        $this->assertFalse(Cache::has($sitemapKey), 'Saving must forget the tenant sitemap cache key');
    }

    /** @test */
    public function http_render_reflects_updated_picks_after_save_invalidates_the_cache(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-ctrl-cache-http-cat']);
        $product  = $this->makeProduct($category, 'lp-ctrl-cache-http-product');

        $page = LandingPage::factory()->published()->create([
            'category_id' => $category->id,
            'slug'        => 'best-lp-ctrl-cache-http',
            'picks'       => [
                ['product_id' => $product->id, 'role' => 'overall', 'headline' => 'Original Headline Unique', 'body' => 'B'],
            ],
        ]);

        $this->get('/best/' . $page->slug)->assertSee('Original Headline Unique');

        $page->picks = [
            ['product_id' => $product->id, 'role' => 'overall', 'headline' => 'Updated Headline Unique', 'body' => 'B'],
        ];
        $page->save();

        $response = $this->get('/best/' . $page->slug);
        $response->assertSee('Updated Headline Unique');
        $response->assertDontSee('Original Headline Unique');
    }
}
