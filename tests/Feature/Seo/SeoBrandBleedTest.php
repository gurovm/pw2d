<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Models\Category;
use App\Models\Preset;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Brand-bleed regression suite.
 *
 * Each scenario asserts that rendered HTML under a non-pw2d tenant:
 *   - Contains the tenant's own brand name
 *   - Contains ZERO occurrences of the string "pw2d" (case-sensitive)
 */
class SeoBrandBleedTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // See SeoSchemaTest::setUp() for why we re-fetch via find() — Tenant::create()
        // returns a model whose id has been overwritten with the sqlite rowid.
        Tenant::create(['id' => 'acme', 'name' => 'Acme']);
        $this->tenant = Tenant::find('acme');
        $this->tenant->brand_name = 'Acme Shop';
        $this->tenant->save();

        tenancy()->initialize($this->tenant);
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        parent::tearDown();
    }

    public function test_homepage_title_uses_tenant_brand_name(): void
    {
        $response = $this->get('/');
        $response->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('Acme Shop', $html);
        $this->assertStringNotContainsString('| pw2d', $html);
    }

    public function test_og_site_name_uses_tenant_brand_name(): void
    {
        $response = $this->get('/');
        $response->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('Acme Shop', $html);

        // og:site_name must NOT contain the literal "pw2d" string
        $this->assertStringNotContainsString('og:site_name" content="pw2d', $html);
    }

    public function test_homepage_og_image_falls_back_to_tenant_default_not_pw2d_logo(): void
    {
        // Set explicit og image on the tenant so we can assert it appears
        $this->tenant->seo_default_image = 'https://acme.com/og-image.png';
        $this->tenant->save();

        $response = $this->get('/');
        $response->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('https://acme.com/og-image.png', $html);
    }

    public function test_parent_category_title_uses_tenant_brand_name(): void
    {
        $parent = Category::factory()->create(['name' => 'Brewing Methods']);
        $child  = Category::factory()->create([
            'name'      => 'Espresso',
            'parent_id' => $parent->id,
        ]);

        $response = $this->get('/compare/' . $parent->slug);
        $response->assertOk();

        $html = $response->getContent();
        // Title suffix must be brand name, not "pw2d"
        $this->assertStringNotContainsString('| pw2d', $html);
        $this->assertStringContainsString('Acme Shop', $html);
    }

    public function test_leaf_category_title_uses_tenant_brand_name(): void
    {
        $category = Category::factory()->create(['name' => 'Espresso Machines']);

        $response = $this->get('/compare/' . $category->slug);
        $response->assertOk();

        $html = $response->getContent();
        $this->assertStringNotContainsString('| pw2d', $html);
        $this->assertStringContainsString('Acme Shop', $html);
    }

    public function test_preset_page_title_uses_tenant_brand_name(): void
    {
        $category = Category::factory()->create(['name' => 'Espresso Machines']);
        $preset   = Preset::factory()->create([
            'name'        => 'Home Barista',
            'category_id' => $category->id,
        ]);

        $presetSlug = \Illuminate\Support\Str::slug($preset->name);
        $response   = $this->get('/compare/' . $category->slug . '?preset=' . $presetSlug);
        $response->assertOk();

        $html = $response->getContent();
        $this->assertStringNotContainsString('| pw2d', $html);
        $this->assertStringContainsString('Acme Shop', $html);
    }

    public function test_product_page_title_uses_tenant_brand_name(): void
    {
        $category = Category::factory()->create(['name' => 'Espresso Machines']);
        $product  = Product::factory()->create([
            'name'        => 'DeLonghi Dedica',
            'slug'        => 'delonghi-dedica',
            'category_id' => $category->id,
            'is_ignored'  => false,
            'status'      => null,
        ]);

        $response = $this->get('/product/' . $product->slug);
        $response->assertOk();

        $html = $response->getContent();
        // Product pages use the product name for title — just ensure no pw2d bleed
        $this->assertStringNotContainsString('| pw2d', $html);
        $this->assertStringNotContainsString('og:site_name" content="pw2d', $html);
    }
}
