<?php

declare(strict_types=1);

namespace Tests\Feature\Compare;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Feature;
use App\Models\FeaturePreset;
use App\Models\Preset;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Spec 025 — Compare-Page Above-Fold UX: PHP/Blade regression guards.
 *
 * SCOPE: Tests that verify content ordering and presence after the Spec 025
 * reorder relocated the intro + tabs + methodology blocks from above the product
 * grid into the new `compare-content.blade.php` partial (included BELOW the grid).
 *
 * What these tests do NOT cover (tested manually per spec §4 QA checklist):
 *   - sessionStorage auto-open behaviour
 *   - backdrop blur removal
 *   - live re-rank while the drawer is open
 *
 * Architecture note: tests run on the central domain (localhost) so tenancy is
 * NOT automatically initialized by middleware. Tenancy is initialized manually
 * for factory seeding, then ended before the HTTP request fires — matching the
 * pattern established in CompareContentDepthTest and PresetContentDepthTest.
 */
class CompareContentOrderTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    /** Monotonic counter to guarantee unique slugs within a test run. */
    private int $slugCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'order-test', 'name' => 'Content Order Test Tenant']);
        $this->tenant = Tenant::find('order-test');
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

    private function nextSlug(string $prefix = 'cat'): string
    {
        $this->slugCounter++;
        return $prefix . '-' . $this->slugCounter;
    }

    /**
     * Minimal category + feature; the Feature is required for the compare page
     * to render the product grid section.
     */
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
            'is_ignored'  => false,
            'status'      => null,
        ]);
    }

    /**
     * Create a Preset with seo_content attached to a category.
     * Adds a weighted feature pivot entry so the preset actually affects scoring.
     */
    private function makePreset(
        Category $category,
        string $presetName,
        array $seoContent,
        ?string $seoDescription = null,
    ): Preset {
        $feature = $category->features()->first();

        $preset = Preset::factory()->create([
            'category_id'     => $category->id,
            'name'            => $presetName,
            'seo_content'     => $seoContent,
            'seo_description' => $seoDescription,
        ]);

        if ($feature) {
            FeaturePreset::create([
                'preset_id'  => $preset->id,
                'feature_id' => $feature->id,
                'weight'     => 80,
            ]);
        }

        return $preset;
    }

    // =========================================================================
    // Test 1 — DOM order: "How We Rank" AFTER the product grid
    //
    // The product grid renders with class "grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4".
    // After Spec 025 reorder, the methodology block (containing "How We Rank") must appear
    // AFTER the grid in the raw HTML byte stream.
    // =========================================================================

    /** @test */
    public function methodology_block_appears_after_product_grid_in_rendered_html(): void
    {
        $methodologyText = 'We rank studio microphones by Sound Quality, Build Quality, and Frequency Response.';

        $category = $this->makeCategory($this->nextSlug('dom-order-methodology'), [
            'buying_guide' => [
                'methodology' => $methodologyText,
            ],
        ]);

        $this->makeProduct($category, 'dom-order-mic-1');

        tenancy()->end();

        $html = $this->get("/compare/{$category->slug}")
            ->assertStatus(200)
            ->getContent();

        // Verify both markers are present before doing a position comparison.
        $this->assertStringContainsString('How We Rank', $html, '"How We Rank" must be present in the rendered HTML');
        $this->assertStringContainsString('Personal Match Score', $html, '"Personal Match Score" grid marker must be present');

        $gridPos        = strpos($html, 'Personal Match Score');
        $methodologyPos = strpos($html, 'How We Rank');

        $this->assertGreaterThan(
            $gridPos,
            $methodologyPos,
            'Spec 025 reorder: "How We Rank" methodology block must appear AFTER the product grid ("Personal Match Score") in the HTML. If this fails, the content drifted back above the grid.',
        );
    }

    // =========================================================================
    // Test 2 — DOM order: "How to Decide" tabs AFTER the product grid
    //
    // The "How to Decide" tab is part of the relocated compare-content partial.
    // The "Read full guide" button text (stable per-partial marker) must appear
    // after the grid, not before it.
    // =========================================================================

    /** @test */
    public function how_to_decide_tabs_appear_after_product_grid_in_rendered_html(): void
    {
        $category = $this->makeCategory($this->nextSlug('dom-order-tabs'), [
            'buying_guide' => [
                'how_to_decide' => '<p>Choose a microphone based on your recording environment and budget.</p>',
            ],
        ]);

        $this->makeProduct($category, 'dom-order-mic-2');

        tenancy()->end();

        $html = $this->get("/compare/{$category->slug}")
            ->assertStatus(200)
            ->getContent();

        // "Read full guide" is the expand-button text rendered in compare-content.blade.php.
        $this->assertStringContainsString('Read full guide', $html, '"Read full guide" expand button must be present when how_to_decide content exists');
        $this->assertStringContainsString('Personal Match Score', $html, '"Personal Match Score" grid marker must be present');

        $gridPos   = strpos($html, 'Personal Match Score');
        $tabsPos   = strpos($html, 'Read full guide');

        $this->assertGreaterThan(
            $gridPos,
            $tabsPos,
            'Spec 025 reorder: "Read full guide" (How to Decide tab block) must appear AFTER the product grid in the HTML.',
        );
    }

    // =========================================================================
    // Test 3 — DOM order: H1 stays ABOVE the product grid
    //
    // The H1 (category name / "Best X for Y" when preset active) must remain
    // above-fold — i.e., its position in the HTML must be BEFORE the grid marker.
    // This is the anti-regression for the compact header block that must NOT move.
    // =========================================================================

    /** @test */
    public function h1_category_name_appears_before_product_grid_in_rendered_html(): void
    {
        $category = $this->makeCategory($this->nextSlug('h1-above-grid'), [
            'buying_guide' => [
                'methodology' => 'Ranked by frequency response and build quality.',
            ],
        ]);

        $this->makeProduct($category, 'h1-above-mic');

        tenancy()->end();

        $html = $this->get("/compare/{$category->slug}")
            ->assertStatus(200)
            ->getContent();

        // The H1 contains the category name (title-cased from slug by our helper).
        $categoryName = ucfirst(str_replace('-', ' ', $category->slug));
        $this->assertStringContainsString($categoryName, $html, 'Category name must appear in the page');
        $this->assertStringContainsString('Personal Match Score', $html, '"Personal Match Score" grid marker must be present');

        // Use the <h1 tag as the position marker — it is unique on the page.
        $h1Pos   = strpos($html, '<h1');
        $gridPos = strpos($html, 'Personal Match Score');

        $this->assertLessThan(
            $gridPos,
            $h1Pos,
            'The <h1> element must appear BEFORE the product grid in the HTML (above-the-fold header must stay above grid).',
        );
    }

    // =========================================================================
    // Test 4 — DOM order: H1 with preset name appears before the grid
    //
    // When a preset is active, the H1 reads "Best {category} for {preset}".
    // This must still appear above the grid (before "Personal Match Score").
    // =========================================================================

    /** @test */
    public function h1_with_preset_name_appears_before_product_grid_when_preset_active(): void
    {
        $category = $this->makeCategory($this->nextSlug('h1-preset-above'), [
            'buying_guide' => [
                'methodology' => 'Ranked by noise rejection and sensitivity.',
            ],
        ]);

        $this->makeProduct($category, 'h1-preset-mic');

        $preset = $this->makePreset($category, 'Home Recording', [
            'intro' => '<p>For home recording, a condenser mic is ideal.</p>',
            'faqs'  => [],
        ], 'The best studio microphones for home recording, ranked by your priorities.');

        tenancy()->end();

        $presetSlug = Str::slug($preset->name); // 'home-recording'

        $html = $this->get("/compare/{$category->slug}?preset={$presetSlug}")
            ->assertStatus(200)
            ->getContent();

        // With preset active, H1 = "Best {category} for {preset}"
        $this->assertStringContainsString('Home Recording', $html, 'Preset name must appear in the page');
        $this->assertStringContainsString('Personal Match Score', $html, '"Personal Match Score" grid marker must be present');

        $h1Pos   = strpos($html, '<h1');
        $gridPos = strpos($html, 'Personal Match Score');

        $this->assertLessThan(
            $gridPos,
            $h1Pos,
            'The <h1> element must appear BEFORE the product grid even when a preset is active.',
        );
    }

    // =========================================================================
    // Test 5 — Content presence (no-preset render): intro + tabs + methodology + FAQs
    //
    // The reorder must MOVE content below the grid, not drop it. Assert all four
    // content blocks are present in the rendered HTML for a category with full
    // buying_guide data.
    // =========================================================================

    /** @test */
    public function deep_content_is_present_in_rendered_html_without_preset(): void
    {
        $introText       = 'Choosing the right studio microphone requires understanding polar patterns.';
        $methodologyText = 'We evaluate microphones across frequency response, sensitivity, and noise floor.';
        $faqQuestion     = 'What is a condenser microphone?';

        $category = $this->makeCategory($this->nextSlug('full-content-no-preset'), [
            'buying_guide' => [
                'intro'        => "<p>{$introText}</p>",
                'how_to_decide' => '<p>Consider polar pattern, frequency response, and budget.</p>',
                'methodology'  => $methodologyText,
                'faqs'         => [
                    ['question' => $faqQuestion, 'answer' => 'A condenser mic uses a capacitor to convert sound to electrical signal.'],
                ],
            ],
        ]);

        $this->makeProduct($category, 'full-content-mic');

        tenancy()->end();

        $html = $this->get("/compare/{$category->slug}")
            ->assertStatus(200)
            ->getContent();

        // Intro
        $this->assertStringContainsString(
            $introText,
            $html,
            'Intro text must be present in the rendered HTML (moved below grid, not dropped)',
        );

        // How to Decide tab (the expand button marks the block is rendered)
        $this->assertStringContainsString(
            'How to Decide',
            $html,
            '"How to Decide" tab must be present in rendered HTML',
        );

        // Methodology
        $this->assertStringContainsString(
            'How We Rank',
            $html,
            '"How We Rank" methodology heading must be present',
        );
        $this->assertStringContainsString(
            $methodologyText,
            $html,
            'Methodology text must be present in rendered HTML',
        );

        // FAQs
        $this->assertStringContainsString(
            'Frequently Asked Questions',
            $html,
            '"Frequently Asked Questions" heading must be present',
        );
        $this->assertStringContainsString(
            $faqQuestion,
            $html,
            'FAQ question text must be present in rendered HTML',
        );
    }

    // =========================================================================
    // Test 6 — Content presence (preset active): preset intro + methodology + FAQs
    //
    // When a preset is active with seo_content.intro, the preset intro must
    // replace the category intro (Spec 023 behaviour), AND the methodology and
    // FAQs must still appear below the grid.
    // =========================================================================

    /** @test */
    public function deep_content_is_present_with_preset_active(): void
    {
        $categoryIntro   = 'Category-level intro that should be replaced by preset intro.';
        $presetIntro     = 'For streaming, a dynamic microphone reduces background noise significantly.';
        $methodologyText = 'We rank by polar pattern, self-noise, and frequency response range.';
        $presetFaqQ      = 'Are dynamic mics better for streaming?';
        $categoryFaqQ    = 'What is the best budget microphone?';

        $category = $this->makeCategory($this->nextSlug('preset-content-present'), [
            'buying_guide' => [
                'intro'       => "<p>{$categoryIntro}</p>",
                'methodology' => $methodologyText,
                'faqs'        => [
                    ['question' => $categoryFaqQ, 'answer' => 'The Audio-Technica AT2020 is a great budget choice.'],
                ],
            ],
        ]);

        $this->makeProduct($category, 'preset-content-mic');

        // Use the PresetFactory::seoContent() state to build a preset with full content.
        $preset = Preset::factory()
            ->seoContent(
                "<p>{$presetIntro}</p>",
                [['question' => $presetFaqQ, 'answer' => 'Yes — dynamic mics reject more ambient noise.']],
            )
            ->create([
                'category_id'     => $category->id,
                'name'            => 'Streamer',
                'seo_description' => 'Best microphones for streamers, ranked by noise rejection.',
            ]);

        $feature = $category->features()->first();
        if ($feature) {
            FeaturePreset::create(['preset_id' => $preset->id, 'feature_id' => $feature->id, 'weight' => 90]);
        }

        tenancy()->end();

        $presetSlug = Str::slug($preset->name); // 'streamer'

        $html = $this->get("/compare/{$category->slug}?preset={$presetSlug}")
            ->assertStatus(200)
            ->getContent();

        // Preset intro must appear (category intro replaced).
        $this->assertStringContainsString(
            $presetIntro,
            $html,
            'Preset intro must appear in rendered HTML when preset is active',
        );

        // Category intro must NOT appear (preset replaces it).
        $this->assertStringNotContainsString(
            $categoryIntro,
            $html,
            'Category intro must NOT appear when preset intro is active',
        );

        // Methodology (from category buying_guide) must still appear.
        $this->assertStringContainsString(
            'How We Rank',
            $html,
            '"How We Rank" must appear below the grid even when preset is active',
        );

        // Preset FAQ must appear.
        $this->assertStringContainsString(
            $presetFaqQ,
            $html,
            'Preset FAQ question must appear in the rendered HTML',
        );

        // Category FAQ must appear (merged, not dropped).
        $this->assertStringContainsString(
            $categoryFaqQ,
            $html,
            'Category FAQ question must appear in merged FAQ list',
        );
    }

    // =========================================================================
    // Test 7 — Graceful empty render: no buying_guide, no preset content
    //
    // A category with null buying_guide and no preset must render a 200 with the
    // product grid present, and no PHP errors. The @if guards in the partials
    // must suppress all deep-content blocks without crashing.
    // =========================================================================

    /** @test */
    public function page_renders_200_with_grid_present_when_buying_guide_is_null(): void
    {
        $category = $this->makeCategory($this->nextSlug('null-guide-order'), [
            'buying_guide' => null,
        ]);

        $this->makeProduct($category, 'null-guide-mic');

        tenancy()->end();

        $html = $this->get("/compare/{$category->slug}")
            ->assertStatus(200)
            ->getContent();

        // Product grid must still render.
        $this->assertStringContainsString(
            'Personal Match Score',
            $html,
            'Product grid ("Personal Match Score") must render even when buying_guide is null',
        );

        // Deep content blocks must be absent (guards worked correctly).
        $this->assertStringNotContainsString('How We Rank', $html, '"How We Rank" must NOT render when methodology is absent');
        $this->assertStringNotContainsString('Frequently Asked Questions', $html, 'FAQs must NOT render when faqs are absent');
    }

    // =========================================================================
    // Test 8 — Graceful empty render: empty buying_guide array (no keys)
    //
    // Edge case: buying_guide is an empty array. All @if/!empty guards must
    // correctly suppress output without PHP notice about missing array keys.
    // =========================================================================

    /** @test */
    public function page_renders_200_with_grid_present_when_buying_guide_is_empty_array(): void
    {
        $category = $this->makeCategory($this->nextSlug('empty-guide-order'), [
            'buying_guide' => [],
        ]);

        $this->makeProduct($category, 'empty-guide-mic');

        tenancy()->end();

        $html = $this->get("/compare/{$category->slug}")
            ->assertStatus(200)
            ->getContent();

        // Product grid must still render.
        $this->assertStringContainsString(
            'Personal Match Score',
            $html,
            'Product grid must render when buying_guide is an empty array',
        );

        $this->assertStringNotContainsString('How We Rank', $html);
        $this->assertStringNotContainsString('Frequently Asked Questions', $html);
    }

    // =========================================================================
    // Test 9 — seo_description hook stays above the grid
    //
    // The preset's seo_description short hook (a 1-2 sentence intent line)
    // must appear in the compact header block BEFORE the grid, not after it.
    // This was explicitly kept above the fold in Spec 025 §3.1.
    // =========================================================================

    /** @test */
    public function preset_seo_description_appears_before_product_grid_in_rendered_html(): void
    {
        $seoDescription = 'Best condenser microphones for home recording in 2026, ranked by your priorities.';

        $category = $this->makeCategory($this->nextSlug('seo-desc-above'), [
            'buying_guide' => null,
        ]);

        $this->makeProduct($category, 'seo-desc-mic');

        $preset = $this->makePreset(
            $category,
            'Home Studio',
            ['intro' => '<p>Intro below the grid.</p>', 'faqs' => []],
            $seoDescription,
        );

        tenancy()->end();

        $presetSlug = Str::slug($preset->name);

        $html = $this->get("/compare/{$category->slug}?preset={$presetSlug}")
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString($seoDescription, $html, 'seo_description must be present in rendered HTML');
        $this->assertStringContainsString('Personal Match Score', $html, '"Personal Match Score" grid marker must be present');

        $descPos = strpos($html, $seoDescription);
        $gridPos = strpos($html, 'Personal Match Score');

        $this->assertLessThan(
            $gridPos,
            $descPos,
            'The preset seo_description hook must appear BEFORE the product grid (kept above-fold per Spec 025 §3.1).',
        );
    }

    // =========================================================================
    // Test 10 — All deep content blocks appear AFTER the grid (combined assertion)
    //
    // Belt-and-suspenders test: with a fully-populated category + active preset,
    // assert that ALL relocated blocks (intro, "How to Decide", methodology, FAQs)
    // appear after the grid marker. This is the most comprehensive ordering guard.
    // =========================================================================

    /** @test */
    public function all_deep_content_appears_after_product_grid_when_fully_populated(): void
    {
        $introText       = 'Streaming microphones need low self-noise above all else.';
        $methodologyText = 'Products are scored on self-noise, polar pattern, and frequency extension.';
        $faqQuestion     = 'What polar pattern is best for streaming?';

        $category = $this->makeCategory($this->nextSlug('all-deep-content-order'), [
            'buying_guide' => [
                'intro'         => "<p>{$introText}</p>",
                'how_to_decide' => '<p>Consider noise floor first, then polar pattern, then budget.</p>',
                'methodology'   => $methodologyText,
                'faqs'          => [
                    ['question' => $faqQuestion, 'answer' => 'Cardioid — it rejects side and rear noise.'],
                ],
            ],
        ]);

        $this->makeProduct($category, 'all-deep-content-mic-1');
        $this->makeProduct($category, 'all-deep-content-mic-2');

        tenancy()->end();

        $html = $this->get("/compare/{$category->slug}")
            ->assertStatus(200)
            ->getContent();

        $gridPos = strpos($html, 'Personal Match Score');
        $this->assertNotFalse($gridPos, '"Personal Match Score" grid marker must be present');

        // Intro must be after grid.
        $introPos = strpos($html, $introText);
        $this->assertNotFalse($introPos, 'Intro text must be present');
        $this->assertGreaterThan($gridPos, $introPos, 'Intro block must appear AFTER the product grid');

        // "How to Decide" tab must be after grid (use the tab button text as marker).
        $tabsPos = strpos($html, 'How to Decide');
        $this->assertNotFalse($tabsPos, '"How to Decide" tab must be present');
        $this->assertGreaterThan($gridPos, $tabsPos, '"How to Decide" tab must appear AFTER the product grid');

        // Methodology must be after grid.
        $methodologyPos = strpos($html, 'How We Rank');
        $this->assertNotFalse($methodologyPos, '"How We Rank" heading must be present');
        $this->assertGreaterThan($gridPos, $methodologyPos, '"How We Rank" must appear AFTER the product grid');

        // FAQs must be after grid.
        $faqPos = strpos($html, 'Frequently Asked Questions');
        $this->assertNotFalse($faqPos, '"Frequently Asked Questions" heading must be present');
        $this->assertGreaterThan($gridPos, $faqPos, '"Frequently Asked Questions" must appear AFTER the product grid');

        // FAQs must be after methodology (FAQ partial is included after compare-content).
        $this->assertGreaterThan($methodologyPos, $faqPos, '"Frequently Asked Questions" must appear AFTER the methodology block');
    }
}
