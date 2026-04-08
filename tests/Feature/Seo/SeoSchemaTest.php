<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Tenant;
use App\Support\SeoSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Tests\TestCase;

class SeoSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // NOTE: Tenant::create() returns an object whose `id` is the sqlite rowid
        // (e.g. '1'), not the string PK we passed. Re-fetch via find() so
        // getTenantKey() returns the real PK ('acme') for tenancy initialization
        // and BelongsToTenant FK assignment.
        Tenant::create(['id' => 'acme', 'name' => 'Acme']);
        $tenant = Tenant::find('acme');
        $tenant->brand_name = 'Acme Shop';
        $tenant->save();

        tenancy()->initialize($tenant);
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        parent::tearDown();
    }

    public function test_for_homepage_returns_tenant_scoped_title(): void
    {
        $seo = SeoSchema::forHomepage();

        $this->assertStringContainsString('Acme Shop', $seo['title']);
        $this->assertStringNotContainsString('pw2d', $seo['title']);
        $this->assertSame('website', $seo['ogType']);
        $this->assertNull($seo['activePreset']);
        $this->assertNotEmpty($seo['schemas']);
        $this->assertSame('WebSite', $seo['schemas'][0]['@type']);
        $this->assertSame('Acme Shop', $seo['schemas'][0]['name']);
    }

    public function test_for_leaf_category_falls_back_to_top_product_image_for_og_image(): void
    {
        $category = Category::factory()->create(['name' => 'Espresso Machines']);

        $product = Product::factory()->create([
            'category_id' => $category->id,
            'slug'        => 'test-espresso-machine',
            'is_ignored'  => false,
            'status'      => null,
        ]);

        // Create a ProductOffer with an image URL
        ProductOffer::create([
            'product_id' => $product->id,
            'store_id'   => null,
            'url'        => 'https://amazon.com/test',
            'raw_title'  => 'Test Espresso Machine',
            'price'      => 299.99,
            'image_url'  => 'https://images.amazon.com/espresso.jpg',
        ]);

        // Load product with offers relation
        $visibleProducts = Product::with('offers')->where('id', $product->id)->get();

        $seo = SeoSchema::forCategoryPage(
            $category,
            collect(),    // no subcategories → leaf
            null,
            null,
            null,
            $visibleProducts,
        );

        $this->assertSame('https://images.amazon.com/espresso.jpg', $seo['ogImage']);
    }

    public function test_for_leaf_category_falls_back_to_tenant_default_image_when_no_offer_image(): void
    {
        $category        = Category::factory()->create(['name' => 'Grinders']);
        $visibleProducts = collect();

        $seo = SeoSchema::forCategoryPage(
            $category,
            collect(),
            null,
            null,
            null,
            $visibleProducts,
        );

        // Should fall back to tenant_seo('default_image') — not null, not pw2d logo
        $this->assertNotNull($seo['ogImage']);
    }

    public function test_for_parent_category_includes_has_part_for_subcategories(): void
    {
        $parent = Category::factory()->create(['name' => 'Coffee Makers']);
        $child1 = Category::factory()->create(['name' => 'Espresso', 'parent_id' => $parent->id]);
        $child2 = Category::factory()->create(['name' => 'Drip', 'parent_id' => $parent->id]);

        $subcategories = collect([$child1, $child2]);

        $seo = SeoSchema::forCategoryPage(
            $parent,
            $subcategories,
            null,
            null,
            null,
            collect(),
        );

        $schema = $seo['schemas'][0];
        $this->assertSame('CollectionPage', $schema['@type']);
        $this->assertCount(2, $schema['hasPart']);
        $this->assertSame('Espresso', $schema['hasPart'][0]['name']);
        $this->assertSame('Drip', $schema['hasPart'][1]['name']);
    }

    public function test_tenant_seo_helper_returns_brand_based_defaults_when_keys_unset(): void
    {
        // Tenant 'acme' has brand_name='Acme Shop' but no seo_* keys set
        $this->assertSame('Acme Shop', tenant_seo('title_suffix'));
        $this->assertSame('Acme Shop — AI Product Recommendations', tenant_seo('default_title'));
        $this->assertStringContainsString('Acme Shop', tenant_seo('default_description'));
    }

    public function test_tenant_seo_helper_returns_explicit_value_when_key_is_set(): void
    {
        $tenant = tenancy()->tenant;
        $tenant->seo_title_suffix  = 'Acme Custom Suffix';
        $tenant->seo_default_title = 'Acme Custom Title';
        $tenant->save();

        $this->assertSame('Acme Custom Suffix', tenant_seo('title_suffix'));
        $this->assertSame('Acme Custom Title', tenant_seo('default_title'));
    }
}
