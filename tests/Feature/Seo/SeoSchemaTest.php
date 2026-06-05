<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Feature;
use App\Models\Preset;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Store;
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

    // -------------------------------------------------------------------------
    // Spec 018 — Item A: Absolute image URL in forSelectedProduct
    // -------------------------------------------------------------------------

    /**
     * @test
     * §4.3 — image_path is set → schema['image'] must be an absolute URL.
     */
    public function test_for_selected_product_emits_absolute_image_url_when_image_path_is_set(): void
    {
        $product = Product::factory()->create([
            'slug'       => 'mic-with-image',
            'image_path' => 'products/images/test.webp',
        ]);
        $product->load('offers.store', 'brand');

        $seo    = SeoSchema::forSelectedProduct($product);
        $schema = $seo['schemas'][0];

        $this->assertArrayHasKey('image', $schema, 'schema must contain an "image" key when image_path is set');
        $this->assertTrue(
            str_starts_with($schema['image'], 'http'),
            "Expected absolute URL, got: {$schema['image']}"
        );
    }

    /**
     * @test
     * §4.3 — image_path is empty → schema must not contain an 'image' key.
     */
    public function test_for_selected_product_omits_image_when_image_path_is_empty(): void
    {
        $product = Product::factory()->create([
            'slug'       => 'mic-without-image',
            'image_path' => null,
        ]);
        $product->load('offers.store', 'brand');

        $seo    = SeoSchema::forSelectedProduct($product);
        $schema = $seo['schemas'][0];

        $this->assertArrayNotHasKey('image', $schema, 'schema must not contain "image" when image_path is null');
    }

    // -------------------------------------------------------------------------
    // Spec 018 — Item B: Offer block in forSelectedProduct
    // -------------------------------------------------------------------------

    /**
     * Helper: create a Store row (no StoreFactory exists).
     */
    private function makeStore(string $name = 'Amazon', string $affiliateParams = 'tag=pw2d-20'): Store
    {
        return Store::create([
            'tenant_id'        => null,
            'name'             => $name,
            'slug'             => 'store-' . uniqid(),
            'affiliate_params' => $affiliateParams,
            'commission_rate'  => 5.00,
            'priority'         => 1,
            'is_active'        => true,
        ]);
    }

    /**
     * Helper: create a ProductOffer and attach it to the given product.
     */
    private function makeOffer(
        Product $product,
        Store|null $store,
        float $price,
        string $stockStatus = 'in_stock',
    ): ProductOffer {
        return ProductOffer::create([
            'product_id'    => $product->id,
            'store_id'      => $store?->id,
            'tenant_id'     => null,
            'url'           => 'https://www.amazon.com/dp/TEST123',
            'scraped_price' => $price,
            'raw_title'     => $product->name,
            'stock_status'  => $stockStatus,
        ]);
    }

    /**
     * @test
     * §5.4 — best_price > 0 → schema must contain a valid Offer block.
     */
    public function test_for_selected_product_emits_offer_when_best_price_is_positive(): void
    {
        $store   = $this->makeStore();
        $product = Product::factory()->create(['slug' => 'product-with-offer']);
        $this->makeOffer($product, $store, 99.99, 'in_stock');
        $product->load('offers.store', 'brand');

        $seo    = SeoSchema::forSelectedProduct($product);
        $schema = $seo['schemas'][0];

        $this->assertArrayHasKey('offers', $schema, 'schema must include "offers" when best_price > 0');
        $this->assertSame('Offer', $schema['offers']['@type']);
        $this->assertSame('99.99', $schema['offers']['price'], 'price must be formatted as a 2-decimal string');
        $this->assertSame('USD', $schema['offers']['priceCurrency']);
    }

    /**
     * @test
     * §5.4 — no offers at all → schema must not contain "offers" key.
     */
    public function test_for_selected_product_omits_offer_when_product_has_no_offers(): void
    {
        $product = Product::factory()->create(['slug' => 'product-no-offers']);
        $product->load('offers.store', 'brand');

        $seo    = SeoSchema::forSelectedProduct($product);
        $schema = $seo['schemas'][0];

        $this->assertArrayNotHasKey('offers', $schema, 'schema must not contain "offers" when there are no priced offers');
    }

    /**
     * @test
     * §5.4 — all offers have null price → schema must not contain "offers" key.
     */
    public function test_for_selected_product_omits_offer_when_best_price_is_null(): void
    {
        $store   = $this->makeStore();
        $product = Product::factory()->create(['slug' => 'product-null-price']);
        // Offer with null price simulates unscraped/missing price
        ProductOffer::create([
            'product_id'    => $product->id,
            'store_id'      => $store->id,
            'tenant_id'     => null,
            'url'           => 'https://www.amazon.com/dp/NULLPRICE',
            'scraped_price' => null,
            'raw_title'     => 'No price yet',
            'stock_status'  => 'in_stock',
        ]);
        $product->load('offers.store', 'brand');

        $seo    = SeoSchema::forSelectedProduct($product);
        $schema = $seo['schemas'][0];

        $this->assertArrayNotHasKey('offers', $schema, 'schema must not emit "offers" when all scraped_prices are null');
    }

    /**
     * @test
     * §5.4 — Offer.availability maps correctly for each stock_status value.
     */
    public function test_offer_availability_respects_stock_status(): void
    {
        $store = $this->makeStore();

        $cases = [
            'in_stock'     => 'https://schema.org/InStock',
            'out_of_stock' => 'https://schema.org/OutOfStock',
            'unknown_val'  => 'https://schema.org/InStock',  // default arm
            null            => 'https://schema.org/InStock',  // null → default
        ];

        foreach ($cases as $stockStatus => $expectedAvailability) {
            $product = Product::factory()->create(['slug' => 'stock-test-' . uniqid()]);
            ProductOffer::create([
                'product_id'    => $product->id,
                'store_id'      => $store->id,
                'tenant_id'     => null,
                'url'           => 'https://www.amazon.com/dp/STOCK',
                'scraped_price' => 49.99,
                'raw_title'     => 'Stock Test Product',
                'stock_status'  => $stockStatus,
            ]);
            $product->load('offers.store', 'brand');

            $seo    = SeoSchema::forSelectedProduct($product);
            $schema = $seo['schemas'][0];

            $this->assertArrayHasKey('offers', $schema);
            $this->assertSame(
                $expectedAvailability,
                $schema['offers']['availability'],
                "stock_status={$stockStatus} should map to {$expectedAvailability}"
            );
        }
    }

    /**
     * @test
     * §5.4 — Offer.seller.name falls back to "Multiple retailers" when offer has no store.
     */
    public function test_offer_seller_name_falls_back_when_store_is_null(): void
    {
        $product = Product::factory()->create(['slug' => 'product-no-store']);
        ProductOffer::create([
            'product_id'    => $product->id,
            'store_id'      => null,   // no store associated
            'tenant_id'     => null,
            'url'           => 'https://www.amazon.com/dp/NOSTORE',
            'scraped_price' => 29.99,
            'raw_title'     => 'Storeless Offer',
            'stock_status'  => 'in_stock',
        ]);
        $product->load('offers.store', 'brand');

        $seo    = SeoSchema::forSelectedProduct($product);
        $schema = $seo['schemas'][0];

        $this->assertArrayHasKey('offers', $schema);
        $this->assertSame(
            'Multiple retailers',
            $schema['offers']['seller']['name'],
            'seller.name must fall back to "Multiple retailers" when store is null'
        );
    }

    /**
     * @test
     * §5.4 — Offer.url resolves via affiliate_url accessor (may include affiliate params).
     */
    public function test_offer_url_uses_affiliate_url(): void
    {
        $store   = $this->makeStore('Amazon', 'tag=pw2d-20');
        $product = Product::factory()->create(['slug' => 'product-affiliate-url']);
        $this->makeOffer($product, $store, 79.99);
        $product->load('offers.store', 'brand');

        $seo    = SeoSchema::forSelectedProduct($product);
        $schema = $seo['schemas'][0];

        $this->assertArrayHasKey('offers', $schema);
        $offerUrl = $schema['offers']['url'];

        $this->assertNotEmpty($offerUrl, 'Offer.url must not be empty');
        $this->assertTrue(
            str_starts_with($offerUrl, 'http'),
            "Offer.url must start with http, got: {$offerUrl}"
        );
        // The store has affiliate_params='tag=pw2d-20', so the url should include it.
        $this->assertStringContainsString('tag=pw2d-20', $offerUrl, 'Offer.url must include affiliate tag from store.affiliate_params');
    }

    // -------------------------------------------------------------------------
    // Spec 018 — Item C: Templated meta description in forLeafCategory
    // -------------------------------------------------------------------------

    /**
     * Helper: produce a $visibleProducts collection of N minimal products for a category.
     * Products are not persisted — SeoSchema only calls ->count() and ->first() on the
     * collection for description logic, so plain model instances suffice for Item C tests.
     * For the ogImage fallback test we do persist them.
     */
    private function makeVisibleProducts(Category $category, int $count): Collection
    {
        $products = collect();
        for ($i = 1; $i <= $count; $i++) {
            $products->push(Product::factory()->make([
                'category_id' => $category->id,
                'slug'        => "product-{$i}-" . uniqid(),
            ]));
        }
        return $products;
    }

    /**
     * @test
     * §6.4 — description contains "Compare N top" with the actual product count.
     */
    public function test_for_leaf_category_description_uses_templated_form_with_product_count(): void
    {
        $category = Category::factory()->create(['name' => 'Podcast Mics']);
        $products = $this->makeVisibleProducts($category, 5);

        $seo = SeoSchema::forCategoryPage($category, collect(), null, null, null, $products);

        $this->assertStringContainsString('Compare 5 top', $seo['description']);
    }

    /**
     * @test
     * §6.4 — with 3+ features, description lists first 3 with Oxford comma and "and".
     */
    public function test_for_leaf_category_description_includes_top_3_feature_names_with_oxford_comma(): void
    {
        $category = Category::factory()->create(['name' => 'Studio Mics']);

        // Create 5 features; only the first 3 (by id order) should appear.
        Feature::factory()->create(['category_id' => $category->id, 'name' => 'Alpha']);
        Feature::factory()->create(['category_id' => $category->id, 'name' => 'Beta']);
        Feature::factory()->create(['category_id' => $category->id, 'name' => 'Gamma']);
        Feature::factory()->create(['category_id' => $category->id, 'name' => 'Delta']);
        Feature::factory()->create(['category_id' => $category->id, 'name' => 'Epsilon']);

        // Reload so $category->features is populated.
        $category->load('features');

        $products = $this->makeVisibleProducts($category, 3);

        $seo = SeoSchema::forCategoryPage($category, collect(), null, null, null, $products);

        $this->assertStringContainsString(
            'Alpha, Beta, and Gamma',
            $seo['description'],
            'First 3 features must appear with Oxford comma'
        );
        $this->assertStringNotContainsString('Delta', $seo['description'], 'Fourth feature must not appear');
        $this->assertStringNotContainsString('Epsilon', $seo['description'], 'Fifth feature must not appear');
    }

    /**
     * @test
     * §6.4 — features_clause varies correctly for 0, 1, and 2 feature counts.
     */
    public function test_for_leaf_category_description_handles_zero_one_two_features(): void
    {
        // --- 0 features ---
        $cat0 = Category::factory()->create(['name' => 'Headphones']);
        $cat0->load('features');
        $products0 = $this->makeVisibleProducts($cat0, 2);
        $seo0 = SeoSchema::forCategoryPage($cat0, collect(), null, null, null, $products0);
        $this->assertStringNotContainsString(
            'AI-ranks them by',
            $seo0['description'],
            '0 features: features_clause must be empty, no "AI-ranks them by" in description'
        );
        // The description should still have "Compare N top ..." and "Find your perfect match in seconds."
        $this->assertStringContainsString('Compare 2 top Headphones.', $seo0['description']);
        $this->assertStringContainsString('Find your perfect match in seconds.', $seo0['description']);

        // --- 1 feature ---
        $cat1 = Category::factory()->create(['name' => 'Keyboards']);
        Feature::factory()->create(['category_id' => $cat1->id, 'name' => 'Tactility']);
        $cat1->load('features');
        $products1 = $this->makeVisibleProducts($cat1, 2);
        $seo1 = SeoSchema::forCategoryPage($cat1, collect(), null, null, null, $products1);
        $this->assertStringContainsString(
            'AI-ranks them by Tactility.',
            $seo1['description'],
            '1 feature: must use single-feature clause without comma or "and"'
        );
        $this->assertStringNotContainsString(' and ', $seo1['description']);

        // --- 2 features ---
        $cat2 = Category::factory()->create(['name' => 'Monitors']);
        Feature::factory()->create(['category_id' => $cat2->id, 'name' => 'Resolution']);
        Feature::factory()->create(['category_id' => $cat2->id, 'name' => 'Refresh Rate']);
        $cat2->load('features');
        $products2 = $this->makeVisibleProducts($cat2, 2);
        $seo2 = SeoSchema::forCategoryPage($cat2, collect(), null, null, null, $products2);
        $this->assertStringContainsString(
            'AI-ranks them by Resolution and Refresh Rate.',
            $seo2['description'],
            '2 features: must use "X and Y" without Oxford comma'
        );
        $this->assertStringNotContainsString(',', $seo2['description']);
    }

    /**
     * @test
     * §6.4 — 0 visible products → description falls back to generic form.
     */
    public function test_for_leaf_category_description_with_zero_visible_products_falls_back_to_generic_form(): void
    {
        $category = Category::factory()->create(['name' => 'Webcams']);
        $category->load('features');

        $seo = SeoSchema::forCategoryPage($category, collect(), null, null, null, collect());

        $this->assertStringContainsString(
            'Compare the absolute best',
            $seo['description'],
            'Zero visible products must produce the generic fallback description'
        );
        $this->assertStringContainsString('Webcams', $seo['description']);
        $this->assertStringNotContainsString('Compare 0 top', $seo['description']);
    }

    /**
     * @test
     * §6.4 — preset with seo_description → description equals preset text (regression).
     */
    public function test_for_leaf_category_with_active_preset_uses_preset_seo_description(): void
    {
        $category = Category::factory()->create(['name' => 'Speakers', 'slug' => 'speakers']);
        $preset   = Preset::factory()->create([
            'category_id'     => $category->id,
            'name'            => 'Home Studio',
            'seo_description' => 'Custom preset description.',
        ]);
        $category->load('features');

        // forCategoryPage resolves the preset by slug-matching $activePresetSlug to Str::slug($preset->name).
        $activeSlug = \Illuminate\Support\Str::slug($preset->name); // "home-studio"

        $products = $this->makeVisibleProducts($category, 3);
        $seo = SeoSchema::forCategoryPage($category, collect(), null, null, $activeSlug, $products);

        $this->assertSame(
            'Custom preset description.',
            $seo['description'],
            'When an active preset has seo_description, it must override the templated description'
        );
        $this->assertNotNull($seo['activePreset']);
        $this->assertSame($preset->id, $seo['activePreset']->id);
    }

    /**
     * @test
     * §6.4 — buying_guide['how_to_decide'] must NOT appear in the meta description (regression).
     */
    public function test_for_leaf_category_description_no_longer_uses_buying_guide_how_to_decide(): void
    {
        $category = Category::factory()->create([
            'name'          => 'Microphones',
            'buying_guide'  => [
                'how_to_decide' => 'This is the buying guide intro that used to leak into meta.',
            ],
        ]);
        $category->load('features');

        Feature::factory()->create(['category_id' => $category->id, 'name' => 'Sensitivity']);
        Feature::factory()->create(['category_id' => $category->id, 'name' => 'Frequency Response']);
        $category->load('features'); // reload after creating features

        $products = $this->makeVisibleProducts($category, 3);
        $seo = SeoSchema::forCategoryPage($category, collect(), null, null, null, $products);

        $this->assertStringNotContainsString(
            'buying guide intro',
            $seo['description'],
            'buying_guide["how_to_decide"] must not leak into the meta description'
        );
        // Confirm the templated form is used instead
        $this->assertStringContainsString('Compare 3 top Microphones.', $seo['description']);
    }

    /**
     * @test
     * §6.3 — buildItemListSchema still uses buying_guide text in schema description.
     *
     * We verify this indirectly: when buying_guide is set, the ItemList schema's
     * 'description' field should contain the buying-guide text (up to 200 chars,
     * no trailing ellipsis per Str::limit(..., 200, '')).
     * The meta description is the templated form; the schema body is the buying-guide.
     */
    public function test_build_item_list_schema_still_uses_buying_guide_as_schema_description(): void
    {
        $guideText = 'Choosing the right microphone is about matching it to your space and voice.';
        $category  = Category::factory()->create([
            'name'         => 'Podcast Gear',
            'buying_guide' => ['how_to_decide' => $guideText],
        ]);
        $category->load('features');

        $products = $this->makeVisibleProducts($category, 2);
        $seo = SeoSchema::forCategoryPage($category, collect(), null, null, null, $products);

        $itemListSchema = $seo['schemas'][0];
        $this->assertSame('ItemList', $itemListSchema['@type']);
        $this->assertStringContainsString(
            'Choosing the right microphone',
            $itemListSchema['description'],
            'ItemList schema description must still use buying_guide text after Spec 018 changes'
        );

        // The meta description must be the NEW templated form, NOT the buying-guide text.
        $this->assertStringNotContainsString('Choosing the right microphone', $seo['description']);
        $this->assertStringContainsString('Compare 2 top Podcast Gear.', $seo['description']);
    }
}
