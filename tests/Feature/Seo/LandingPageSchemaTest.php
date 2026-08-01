<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Models\Brand;
use App\Models\Category;
use App\Models\LandingPage;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\SeoSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 027 §9.4 — SeoSchema::forLandingPage(): ItemList rating gate (reuses
 * the Spec 026 buildListItem() helper), BreadcrumbList, FAQPage, and the
 * no-price/no-offers regression guard.
 */
class LandingPageSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'lp-schema-tenant', 'name' => 'LP Schema Tenant']);
        $tenant = Tenant::find('lp-schema-tenant');
        tenancy()->initialize($tenant);
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

    private function makePage(Category $category, array $overrides = []): LandingPage
    {
        $category->load('parent');

        return LandingPage::factory()->create(array_merge([
            'category_id' => $category->id,
        ], $overrides));
    }

    private function itemListSchema(array $schemas): ?array
    {
        return collect($schemas)->first(fn ($s) => ($s['@type'] ?? null) === 'ItemList');
    }

    private function breadcrumbSchema(array $schemas): ?array
    {
        return collect($schemas)->first(fn ($s) => ($s['@type'] ?? null) === 'BreadcrumbList');
    }

    private function faqSchema(array $schemas): ?array
    {
        return collect($schemas)->first(fn ($s) => ($s['@type'] ?? null) === 'FAQPage');
    }

    // =========================================================================
    // Rating gate (Spec 026 reuse)
    // =========================================================================

    /** @test */
    public function rated_pick_emits_nested_product_with_aggregate_rating(): void
    {
        $category = Category::factory()->create(['name' => 'Espresso Machines', 'slug' => 'lp-schema-rated-cat']);
        $brand    = Brand::factory()->create(['name' => 'JURA']);
        $product  = Product::factory()->create([
            'category_id'          => $category->id,
            'brand_id'             => $brand->id,
            'name'                 => 'JURA X10 Dark Inox',
            'slug'                 => 'lp-schema-rated-product',
            'amazon_rating'        => 4.6,
            'amazon_reviews_count' => 312,
        ]);

        $page = $this->makePage($category, [
            'slug'  => 'best-lp-schema-rated',
            'picks' => [['product_id' => $product->id, 'role' => 'overall', 'headline' => 'h', 'body' => 'b']],
        ]);

        $seo     = SeoSchema::forLandingPage($page, collect([$product]));
        $element = $this->itemListSchema($seo['schemas'])['itemListElement'][0];

        $this->assertArrayHasKey('item', $element, 'Rated pick must carry a nested Product entity');
        $this->assertSame('Product', $element['item']['@type']);
        $this->assertSame('JURA X10 Dark Inox', $element['item']['name']);
        $this->assertArrayHasKey('aggregateRating', $element['item']);
        $this->assertSame(4.6, $element['item']['aggregateRating']['ratingValue']);
        $this->assertSame(312, $element['item']['aggregateRating']['reviewCount']);
    }

    /** @test */
    public function rating_less_pick_emits_url_only_list_item(): void
    {
        $category = Category::factory()->create(['name' => 'Espresso Machines', 'slug' => 'lp-schema-unrated-cat']);
        $product  = Product::factory()->create([
            'category_id'          => $category->id,
            'slug'                 => 'lp-schema-unrated-product',
            'amazon_rating'        => null,
            'amazon_reviews_count' => 0,
        ]);

        $page = $this->makePage($category, [
            'slug'  => 'best-lp-schema-unrated',
            'picks' => [['product_id' => $product->id, 'role' => 'overall', 'headline' => 'h', 'body' => 'b']],
        ]);

        $seo     = SeoSchema::forLandingPage($page, collect([$product]));
        $element = $this->itemListSchema($seo['schemas'])['itemListElement'][0];

        $this->assertSame(
            ['@type', 'position', 'url'],
            array_keys($element),
            'Rating-less pick must be a URL-only ListItem — no nested item key'
        );
        $this->assertArrayNotHasKey('item', $element);
    }

    /** @test */
    public function mixed_rated_and_unrated_picks_keep_sequential_positions(): void
    {
        $category = Category::factory()->create(['name' => 'Espresso Machines', 'slug' => 'lp-schema-mixed-cat']);

        $rated = Product::factory()->create([
            'category_id'          => $category->id,
            'slug'                 => 'lp-schema-mixed-rated',
            'amazon_rating'        => 4.8,
            'amazon_reviews_count' => 500,
        ]);
        $unrated = Product::factory()->create([
            'category_id'          => $category->id,
            'slug'                 => 'lp-schema-mixed-unrated',
            'amazon_rating'        => null,
            'amazon_reviews_count' => 0,
        ]);

        $page = $this->makePage($category, [
            'slug'  => 'best-lp-schema-mixed',
            'picks' => [
                ['product_id' => $rated->id, 'role' => 'overall', 'headline' => 'h1', 'body' => 'b1'],
                ['product_id' => $unrated->id, 'role' => 'budget', 'headline' => 'h2', 'body' => 'b2'],
            ],
        ]);

        $seo      = SeoSchema::forLandingPage($page, collect([$rated, $unrated]));
        $elements = $this->itemListSchema($seo['schemas'])['itemListElement'];

        $this->assertCount(2, $elements);
        $this->assertSame([1, 2], array_column($elements, 'position'));
        $this->assertArrayHasKey('item', $elements[0]);
        $this->assertArrayNotHasKey('item', $elements[1]);
    }

    // =========================================================================
    // BreadcrumbList
    // =========================================================================

    /** @test */
    public function breadcrumb_list_is_home_category_then_page_for_a_top_level_category(): void
    {
        $category = Category::factory()->create(['name' => 'Espresso Machines', 'slug' => 'lp-schema-breadcrumb-top']);
        $product  = Product::factory()->create([
            'category_id'          => $category->id,
            'slug'                 => 'lp-schema-breadcrumb-top-product',
            'amazon_rating'        => 4.5,
            'amazon_reviews_count' => 100,
        ]);

        $page = $this->makePage($category, [
            'slug'  => 'best-lp-schema-breadcrumb-top',
            'picks' => [['product_id' => $product->id, 'role' => 'overall', 'headline' => 'h', 'body' => 'b']],
        ]);

        $seo        = SeoSchema::forLandingPage($page, collect([$product]));
        $breadcrumb = $this->breadcrumbSchema($seo['schemas']);

        $this->assertNotNull($breadcrumb);
        $names = array_column($breadcrumb['itemListElement'], 'name');
        $this->assertSame(['Home', 'Espresso Machines', $page->title], $names);
        $this->assertSame([1, 2, 3], array_column($breadcrumb['itemListElement'], 'position'));
    }

    /** @test */
    public function breadcrumb_list_includes_parent_category_for_a_child_category(): void
    {
        $parent = Category::factory()->create(['name' => 'Coffee Makers', 'slug' => 'lp-schema-breadcrumb-parent']);
        $child  = Category::factory()->create(['name' => 'Espresso Machines', 'slug' => 'lp-schema-breadcrumb-child', 'parent_id' => $parent->id]);

        $product = Product::factory()->create([
            'category_id'          => $child->id,
            'slug'                 => 'lp-schema-breadcrumb-child-product',
            'amazon_rating'        => 4.5,
            'amazon_reviews_count' => 100,
        ]);

        $page = $this->makePage($child, [
            'slug'  => 'best-lp-schema-breadcrumb-child',
            'picks' => [['product_id' => $product->id, 'role' => 'overall', 'headline' => 'h', 'body' => 'b']],
        ]);

        $seo        = SeoSchema::forLandingPage($page, collect([$product]));
        $breadcrumb = $this->breadcrumbSchema($seo['schemas']);

        $names = array_column($breadcrumb['itemListElement'], 'name');
        $this->assertSame(['Home', 'Coffee Makers', 'Espresso Machines', $page->title], $names);
    }

    // =========================================================================
    // FAQPage
    // =========================================================================

    /** @test */
    public function faq_page_schema_is_present_when_page_has_faqs(): void
    {
        $category = Category::factory()->create(['name' => 'Espresso Machines', 'slug' => 'lp-schema-faq-present-cat']);
        $product  = Product::factory()->create([
            'category_id'          => $category->id,
            'slug'                 => 'lp-schema-faq-present-product',
            'amazon_rating'        => 4.5,
            'amazon_reviews_count' => 100,
        ]);

        $page = $this->makePage($category, [
            'slug'  => 'best-lp-schema-faq-present',
            'picks' => [['product_id' => $product->id, 'role' => 'overall', 'headline' => 'h', 'body' => 'b']],
            'faqs'  => [
                ['question' => 'How were these ranked?', 'answer' => 'By our scoring algorithm.'],
                ['question' => 'Are these updated?', 'answer' => 'Yes, periodically.'],
            ],
        ]);

        $seo = SeoSchema::forLandingPage($page, collect([$product]));
        $faq = $this->faqSchema($seo['schemas']);

        $this->assertNotNull($faq, 'FAQPage schema must be present when the page has faqs');
        $this->assertSame('FAQPage', $faq['@type']);
        $questions = array_column($faq['mainEntity'], 'name');
        $this->assertSame(['How were these ranked?', 'Are these updated?'], $questions);
    }

    /** @test */
    public function faq_page_schema_is_absent_when_page_has_no_faqs(): void
    {
        $category = Category::factory()->create(['name' => 'Espresso Machines', 'slug' => 'lp-schema-faq-absent-cat']);
        $product  = Product::factory()->create([
            'category_id'          => $category->id,
            'slug'                 => 'lp-schema-faq-absent-product',
            'amazon_rating'        => 4.5,
            'amazon_reviews_count' => 100,
        ]);

        $page = $this->makePage($category, [
            'slug'  => 'best-lp-schema-faq-absent',
            'picks' => [['product_id' => $product->id, 'role' => 'overall', 'headline' => 'h', 'body' => 'b']],
            'faqs'  => null,
        ]);

        $seo = SeoSchema::forLandingPage($page, collect([$product]));

        $this->assertNull($this->faqSchema($seo['schemas']), 'FAQPage schema must be absent when faqs is empty/null');
        $this->assertCount(2, $seo['schemas'], 'Only ItemList + BreadcrumbList when there are no faqs');
    }

    // =========================================================================
    // Regression guard: no price/priceCurrency/offers keys anywhere
    // =========================================================================

    /** @test */
    public function no_price_or_offer_keys_appear_anywhere_in_landing_page_schemas(): void
    {
        $category = Category::factory()->create(['name' => 'Espresso Machines', 'slug' => 'lp-schema-no-price-cat']);
        $brand    = Brand::factory()->create();

        $rated = Product::factory()->create([
            'category_id'          => $category->id,
            'brand_id'             => $brand->id,
            'slug'                 => 'lp-schema-no-price-rated',
            'amazon_rating'        => 4.5,
            'amazon_reviews_count' => 100,
        ]);
        $unrated = Product::factory()->create([
            'category_id'          => $category->id,
            'slug'                 => 'lp-schema-no-price-unrated',
            'amazon_rating'        => null,
            'amazon_reviews_count' => 0,
        ]);

        $page = $this->makePage($category, [
            'slug'  => 'best-lp-schema-no-price',
            'picks' => [
                ['product_id' => $rated->id, 'role' => 'overall', 'headline' => 'h1', 'body' => 'b1'],
                ['product_id' => $unrated->id, 'role' => 'budget', 'headline' => 'h2', 'body' => 'b2'],
            ],
            'faqs' => [['question' => 'Q?', 'answer' => 'A.']],
        ]);

        $seo  = SeoSchema::forLandingPage($page, collect([$rated, $unrated]));
        $json = json_encode($seo['schemas'], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('"price"', $json);
        $this->assertStringNotContainsString('priceCurrency', $json);
        $this->assertStringNotContainsString('"offers"', $json);
        $this->assertStringNotContainsString('"Offer"', $json);
    }
}
