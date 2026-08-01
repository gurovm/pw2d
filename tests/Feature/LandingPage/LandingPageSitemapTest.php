<?php

declare(strict_types=1);

namespace Tests\Feature\LandingPage;

use App\Models\Category;
use App\Models\LandingPage;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 027 §9.5 — SitemapController includes published landing pages,
 * excludes drafts.
 */
class LandingPageSitemapTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'lp-sitemap-tenant', 'name' => 'LP Sitemap Tenant']);
        $this->tenant = Tenant::find('lp-sitemap-tenant');
        tenancy()->initialize($this->tenant);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    private function makeCategoryWithProduct(string $slug): array
    {
        $category = Category::factory()->create(['slug' => $slug, 'name' => ucfirst(str_replace('-', ' ', $slug))]);
        $product  = Product::factory()->create([
            'category_id' => $category->id,
            'slug'        => $slug . '-product',
            'is_ignored'  => false,
            'status'      => null,
        ]);

        return [$category, $product];
    }

    /** @test */
    public function sitemap_includes_published_landing_page_url(): void
    {
        [$category, $product] = $this->makeCategoryWithProduct('lp-sitemap-published-cat');

        LandingPage::factory()->published()->create([
            'category_id' => $category->id,
            'slug'        => 'lp-sitemap-published-page',
            'picks'       => [['product_id' => $product->id, 'role' => 'overall', 'headline' => 'h', 'body' => 'b']],
        ]);

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $response->assertSee('/best/lp-sitemap-published-page');
    }

    /** @test */
    public function sitemap_excludes_draft_landing_page_url(): void
    {
        [$category, $product] = $this->makeCategoryWithProduct('lp-sitemap-draft-cat');

        LandingPage::factory()->create([
            'category_id' => $category->id,
            'slug'        => 'lp-sitemap-draft-page',
            'status'      => 'draft',
            'picks'       => [['product_id' => $product->id, 'role' => 'overall', 'headline' => 'h', 'body' => 'b']],
        ]);

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $response->assertDontSee('/best/lp-sitemap-draft-page');
    }
}
