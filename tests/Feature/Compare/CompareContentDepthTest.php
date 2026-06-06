<?php

declare(strict_types=1);

namespace Tests\Feature\Compare;

use App\Livewire\ProductCompare;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Feature;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Spec 021 — View rendering tests.
 *
 * Tests run on the central domain (localhost) so tenancy is NOT initialized
 * by the middleware. We initialize it manually to allow BelongsToTenant
 * factory seeding, then end it before the Livewire component runs — which
 * matches the pattern used in ProductCompareTest.php.
 *
 * The Livewire component reads $category->buying_guide from the already-
 * seeded DB row, so the content assertions work without a live tenant context.
 */
class CompareContentDepthTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'view-test', 'name' => 'View Test Tenant']);
        $this->tenant = Tenant::find('view-test');
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
    // Helper: build a minimal category + feature for Livewire component to load
    // -------------------------------------------------------------------------

    private function makeCategory(string $slug, array $overrides = []): Category
    {
        $category = Category::factory()->create(array_merge([
            'slug' => $slug,
            'name' => ucfirst(str_replace('-', ' ', $slug)),
        ], $overrides));

        Feature::factory()->create(['category_id' => $category->id]);

        return $category;
    }

    private function makeProduct(Category $category, string $slug): Product
    {
        $brand = Brand::factory()->create();

        return Product::factory()->create([
            'category_id' => $category->id,
            'brand_id'    => $brand->id,
            'slug'        => $slug,
        ]);
    }

    // =========================================================================
    // Test 17: Compare page renders intro paragraph when intro is set
    // =========================================================================

    /** @test */
    public function compare_page_renders_intro_paragraph_when_buying_guide_intro_is_set(): void
    {
        $introText = 'Welcome to our definitive guide to studio microphones for home recording.';

        $category = $this->makeCategory('intro-set-cat', [
            'buying_guide' => [
                'intro' => "<p>{$introText}</p>",
            ],
        ]);

        $this->makeProduct($category, 'mic-intro-test');

        // End tenancy before Livewire renders (matches central-domain test pattern)
        tenancy()->end();

        Livewire::test(ProductCompare::class, ['slug' => 'intro-set-cat'])
            ->assertStatus(200)
            ->assertSee($introText);
    }

    // =========================================================================
    // Test 18: Compare page omits intro section when buying_guide.intro is absent
    // =========================================================================

    /** @test */
    public function compare_page_omits_intro_section_when_buying_guide_intro_is_empty(): void
    {
        // Category with no intro key in buying_guide
        $category = $this->makeCategory('no-intro-cat', [
            'buying_guide' => [
                'how_to_decide' => '<p>Some existing guide content.</p>',
                // deliberately no 'intro' key
            ],
        ]);

        $this->makeProduct($category, 'mic-no-intro');

        tenancy()->end();

        // The prose container only renders inside the @if (!empty($category->buying_guide['intro'])) block.
        // We assert it doesn't contain the distinctive wrapper class used only for intro.
        Livewire::test(ProductCompare::class, ['slug' => 'no-intro-cat'])
            ->assertStatus(200)
            ->assertDontSee('prose prose-sm max-w-none mb-4 text-gray-700 leading-relaxed', false);
    }

    // =========================================================================
    // Test 19: Compare page renders FAQ accordion when faqs are set
    // =========================================================================

    /** @test */
    public function compare_page_renders_faq_accordion_when_faqs_is_set(): void
    {
        $category = $this->makeCategory('faq-cat', [
            'buying_guide' => [
                'faqs' => [
                    ['question' => 'What is the best mic under $200?', 'answer' => 'The Audio-Technica AT2020 is excellent.'],
                    ['question' => 'Do I need a preamp?',              'answer' => 'Yes, most condenser mics benefit from a preamp.'],
                ],
            ],
        ]);

        $this->makeProduct($category, 'mic-faq-test');

        tenancy()->end();

        Livewire::test(ProductCompare::class, ['slug' => 'faq-cat'])
            ->assertStatus(200)
            ->assertSee('Frequently Asked Questions')
            ->assertSee('What is the best mic under $200?')
            ->assertSee('Do I need a preamp?');
    }

    // =========================================================================
    // Test 20: Compare page omits FAQ section when faqs is absent
    // =========================================================================

    /** @test */
    public function compare_page_omits_faq_section_when_buying_guide_faqs_is_absent(): void
    {
        $category = $this->makeCategory('no-faq-cat', [
            'buying_guide' => [
                'how_to_decide' => '<p>Guide content only, no FAQs.</p>',
            ],
        ]);

        $this->makeProduct($category, 'mic-no-faq');

        tenancy()->end();

        Livewire::test(ProductCompare::class, ['slug' => 'no-faq-cat'])
            ->assertStatus(200)
            ->assertDontSee('Frequently Asked Questions');
    }

    // =========================================================================
    // Test 21: Compare page omits FAQ section when faqs is an empty array
    // =========================================================================

    /** @test */
    public function compare_page_omits_faq_section_when_faqs_is_empty_array(): void
    {
        $category = $this->makeCategory('empty-faq-cat', [
            'buying_guide' => [
                'faqs' => [],  // explicitly empty
            ],
        ]);

        $this->makeProduct($category, 'mic-empty-faq');

        tenancy()->end();

        Livewire::test(ProductCompare::class, ['slug' => 'empty-faq-cat'])
            ->assertStatus(200)
            ->assertDontSee('Frequently Asked Questions');
    }

    // =========================================================================
    // Test 22: Compare page renders methodology callout when methodology is set
    // =========================================================================

    /** @test */
    public function compare_page_renders_methodology_callout_when_buying_guide_methodology_is_set(): void
    {
        $methodologyText = 'We rank microphones by Sound Quality, Build Quality, and Frequency Response.';

        $category = $this->makeCategory('methodology-cat', [
            'buying_guide' => [
                'methodology' => $methodologyText,
            ],
        ]);

        $this->makeProduct($category, 'mic-methodology');

        tenancy()->end();

        Livewire::test(ProductCompare::class, ['slug' => 'methodology-cat'])
            ->assertStatus(200)
            ->assertSee('How We Rank')
            ->assertSee($methodologyText);
    }

    // =========================================================================
    // Test 23: Compare page omits methodology callout when methodology is absent
    // =========================================================================

    /** @test */
    public function compare_page_omits_methodology_callout_when_buying_guide_methodology_is_absent(): void
    {
        $category = $this->makeCategory('no-methodology-cat', [
            'buying_guide' => [
                'how_to_decide' => '<p>Guide only, no methodology.</p>',
            ],
        ]);

        $this->makeProduct($category, 'mic-no-methodology');

        tenancy()->end();

        Livewire::test(ProductCompare::class, ['slug' => 'no-methodology-cat'])
            ->assertStatus(200)
            ->assertDontSee('How We Rank');
    }

    // =========================================================================
    // Test 24: Page renders cleanly with all three new keys present
    // =========================================================================

    /** @test */
    public function compare_page_renders_cleanly_with_all_three_new_buying_guide_keys(): void
    {
        $category = $this->makeCategory('full-content-cat', [
            'buying_guide' => [
                'intro'       => '<p>Complete intro paragraph for studio microphones.</p>',
                'methodology' => 'We rank by Sound Quality, Frequency Response, and Build Quality.',
                'faqs'        => [
                    ['question' => 'What is XLR vs USB?', 'answer' => 'XLR requires an audio interface; USB is plug-and-play.'],
                ],
            ],
        ]);

        $this->makeProduct($category, 'full-mic');

        tenancy()->end();

        Livewire::test(ProductCompare::class, ['slug' => 'full-content-cat'])
            ->assertStatus(200)
            ->assertSee('Complete intro paragraph for studio microphones')
            ->assertSee('How We Rank')
            ->assertSee('We rank by Sound Quality')
            ->assertSee('Frequently Asked Questions')
            ->assertSee('What is XLR vs USB?');
    }

    // =========================================================================
    // Test 25: Page renders cleanly when buying_guide is null (regression guard)
    // =========================================================================

    /** @test */
    public function compare_page_renders_cleanly_when_buying_guide_is_null(): void
    {
        $category = $this->makeCategory('null-guide-cat', [
            'buying_guide' => null,
        ]);

        $this->makeProduct($category, 'mic-null-guide');

        tenancy()->end();

        // Must not throw a PHP error when buying_guide is null
        Livewire::test(ProductCompare::class, ['slug' => 'null-guide-cat'])
            ->assertStatus(200)
            ->assertDontSee('Frequently Asked Questions')
            ->assertDontSee('How We Rank');
    }

    // =========================================================================
    // Regression: full-page HTTP render must emit ALL schemas (ItemList +
    // BreadcrumbList + FAQPage), not just the first. The layout previously
    // only encoded schemas[0]; Specs 020/021 shipped but silently never
    // rendered until the schemas-emission fix.
    // =========================================================================

    /** @test */
    public function compare_page_emits_all_three_schemas_when_faqs_are_present(): void
    {
        $category = $this->makeCategory('regression-cat', [
            'buying_guide' => [
                'faqs' => [
                    ['question' => 'Q1?', 'answer' => 'A1.'],
                    ['question' => 'Q2?', 'answer' => 'A2.'],
                ],
            ],
        ]);

        $this->makeProduct($category, 'regression-prod');

        tenancy()->end();

        $html = $this->get('/compare/regression-cat')->getContent();

        $scriptCount = substr_count($html, '<script type="application/ld+json">');
        $this->assertSame(
            3,
            $scriptCount,
            "Expected 3 JSON-LD blocks (ItemList + BreadcrumbList + FAQPage), got {$scriptCount}",
        );

        $this->assertStringContainsString('"@type":"ItemList"', $html);
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);
        $this->assertStringContainsString('"@type":"FAQPage"', $html);
    }

    /** @test */
    public function compare_page_emits_two_schemas_when_no_faqs(): void
    {
        $category = $this->makeCategory('two-schema-cat', [
            'buying_guide' => null,
        ]);

        $this->makeProduct($category, 'two-schema-prod');

        tenancy()->end();

        $html = $this->get('/compare/two-schema-cat')->getContent();

        $scriptCount = substr_count($html, '<script type="application/ld+json">');
        $this->assertSame(
            2,
            $scriptCount,
            "Expected 2 JSON-LD blocks (ItemList + BreadcrumbList) when no faqs, got {$scriptCount}",
        );

        $this->assertStringContainsString('"@type":"ItemList"', $html);
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);
        $this->assertStringNotContainsString('"@type":"FAQPage"', $html);
    }
}
