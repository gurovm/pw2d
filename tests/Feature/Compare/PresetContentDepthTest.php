<?php

declare(strict_types=1);

namespace Tests\Feature\Compare;

use App\Livewire\ProductCompare;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Feature;
use App\Models\FeaturePreset;
use App\Models\Preset;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Store;
use App\Models\Tenant;
use App\Support\SeoSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Spec 023 — Preset-Aware Compare Content Depth: view rendering + schema tests.
 *
 * Schema assertions (FAQPage, price absence) MUST use full HTTP render
 * ($this->get('/compare/{slug}?preset={p}')), NOT just Livewire::test().
 * The load-bearing lesson from docs/summaries/2026-06-06-seo-session-handoff.md:
 * layout-level bugs (schemas[0] only) are invisible to Livewire::test().
 *
 * Tests run on central domain (localhost). Tenancy is initialized manually
 * for factory seeding and ended before HTTP/Livewire calls.
 */
class PresetContentDepthTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    /** Monotonic counter to guarantee unique slugs within a test run. */
    private int $slugCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'preset-view-test', 'name' => 'Preset View Test Tenant']);
        $this->tenant = Tenant::find('preset-view-test');
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
     * Minimal category + feature + product — the minimum needed for the
     * ProductCompare component to render without errors.
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
     * Create a Preset with seo_content and attach it to a category.
     * Adds a weighted feature pivot entry.
     */
    private function makePresetWithContent(
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
            FeaturePreset::create(['preset_id' => $preset->id, 'feature_id' => $feature->id, 'weight' => 80]);
        }

        return $preset;
    }

    /**
     * Parse all application/ld+json blocks from an HTML response.
     *
     * @return list<array<string, mixed>>
     */
    private function extractJsonLdBlocks(string $html): array
    {
        preg_match_all(
            '/<script\s+type="application\/ld\+json"[^>]*>(.*?)<\/script>/si',
            $html,
            $matches,
        );

        $schemas = [];
        foreach ($matches[1] as $raw) {
            $decoded = json_decode(trim($raw), true);
            if (is_array($decoded)) {
                $schemas[] = $decoded;
            }
        }

        return $schemas;
    }

    // =========================================================================
    // §5 — SeoSchema FAQPage via HTTP render (load-bearing: must use $this->get)
    // =========================================================================

    /**
     * @test
     * With an active preset that has seo_content.faqs, the FAQPage JSON-LD
     * mainEntity must contain the preset's questions.
     */
    public function http_render_faq_page_contains_preset_questions_when_preset_active(): void
    {
        $category = $this->makeCategory($this->nextSlug('preset-faq'));
        $this->makeProduct($category, 'prod-preset-faq');

        $preset = $this->makePresetWithContent($category, 'Streamer', [
            'intro' => '<p>If you stream, choose a silent switch keyboard.</p>',
            'faqs'  => [
                ['question' => 'Are loud switches bad for streaming?', 'answer' => 'Yes — they bleed into mics.'],
                ['question' => 'What switch type for streamers?',     'answer' => 'Silent linear, e.g. Cherry MX Silent Red.'],
            ],
        ]);

        tenancy()->end();

        $presetSlug = Str::slug($preset->name); // 'streamer'
        $response   = $this->get("/compare/{$category->slug}?preset={$presetSlug}");
        $response->assertStatus(200);

        $html    = $response->getContent();
        $schemas = $this->extractJsonLdBlocks($html);

        $faqSchema = collect($schemas)->first(fn ($s) => ($s['@type'] ?? null) === 'FAQPage');

        $this->assertNotNull($faqSchema, 'FAQPage schema must be present in the rendered HTML when preset has seo_content.faqs');

        $questions = array_column($faqSchema['mainEntity'], 'name');

        $this->assertContains(
            'Are loud switches bad for streaming?',
            $questions,
            'FAQPage mainEntity must include the preset FAQ question'
        );
        $this->assertContains(
            'What switch type for streamers?',
            $questions,
            'FAQPage mainEntity must include all preset FAQ questions'
        );
    }

    /**
     * @test
     * Without ?preset=, the FAQPage must only contain category-level FAQs
     * (preset questions must NOT appear).
     */
    public function http_render_faq_page_contains_only_category_faqs_when_no_preset(): void
    {
        $categoryFaqQ = 'What is the best budget keyboard?';
        $presetFaqQ   = 'Are loud switches bad for streaming?';

        $category = $this->makeCategory($this->nextSlug('cat-faq-only'), [
            'buying_guide' => [
                'faqs' => [
                    ['question' => $categoryFaqQ, 'answer' => 'The Keychron K6 is an excellent value.'],
                ],
            ],
        ]);
        $this->makeProduct($category, 'prod-cat-faq-only');

        // Preset exists in DB but is not activated (no ?preset= in URL).
        $this->makePresetWithContent($category, 'Streamer', [
            'intro' => '<p>Streamer intro.</p>',
            'faqs'  => [
                ['question' => $presetFaqQ, 'answer' => 'Yes.'],
            ],
        ]);

        tenancy()->end();

        $html    = $this->get("/compare/{$category->slug}")->getContent();
        $schemas = $this->extractJsonLdBlocks($html);

        $faqSchema = collect($schemas)->first(fn ($s) => ($s['@type'] ?? null) === 'FAQPage');

        $this->assertNotNull($faqSchema, 'FAQPage must be present because category has buying_guide.faqs');

        $questions = array_column($faqSchema['mainEntity'], 'name');

        $this->assertContains($categoryFaqQ, $questions, 'Category FAQ question must appear');
        $this->assertNotContains($presetFaqQ, $questions, 'Preset FAQ question must NOT appear when no preset is active');
    }

    /**
     * @test
     * When a preset is active and both preset + category have FAQs, the merged
     * FAQPage must have preset questions FIRST, then non-duplicate category questions.
     */
    public function http_render_faq_page_merges_preset_first_then_category_deduped(): void
    {
        $sharedQuestion  = 'Is mechanical keyboard good for typing?';
        $presetQuestion  = 'Are loud switches bad for streaming?';
        $categoryQuestion = 'What is the best budget keyboard?';

        $category = $this->makeCategory($this->nextSlug('merged-faqs'), [
            'buying_guide' => [
                'faqs' => [
                    ['question' => $sharedQuestion,   'answer' => 'Category shared answer.'],
                    ['question' => $categoryQuestion, 'answer' => 'Category-only answer.'],
                ],
            ],
        ]);
        $this->makeProduct($category, 'prod-merged-faqs');

        $preset = $this->makePresetWithContent($category, 'Streamer', [
            'intro' => '<p>Streamer intro.</p>',
            'faqs'  => [
                ['question' => $presetQuestion,  'answer' => 'Preset-specific answer.'],
                ['question' => $sharedQuestion,  'answer' => 'Preset shared answer (should win).'],
            ],
        ]);

        tenancy()->end();

        $presetSlug = Str::slug($preset->name);
        $html       = $this->get("/compare/{$category->slug}?preset={$presetSlug}")->getContent();
        $schemas    = $this->extractJsonLdBlocks($html);

        $faqSchema = collect($schemas)->first(fn ($s) => ($s['@type'] ?? null) === 'FAQPage');
        $this->assertNotNull($faqSchema);

        $mainEntity = $faqSchema['mainEntity'];
        $questions  = array_column($mainEntity, 'name');

        // Preset questions appear first.
        $this->assertSame($presetQuestion, $questions[0], 'Preset FAQ must be first in merged FAQPage');
        $this->assertSame($sharedQuestion, $questions[1], 'Shared question (preset version) must be second');

        // Shared question appears exactly once (deduplication).
        $this->assertSame(
            1,
            count(array_filter($questions, fn ($q) => $q === $sharedQuestion)),
            'Shared question must appear exactly once (deduped)'
        );

        // Category-only question appears (not deduped, it is unique).
        $this->assertContains($categoryQuestion, $questions, 'Category-only FAQ must appear in merged list');
    }

    /**
     * @test
     * Load-bearing policy assertion (seo-schema-policy): no price / priceCurrency
     * in any JSON-LD block on a product page render.
     *
     * This mirrors the assertion in SeoSchemaTest but at HTTP level, where the
     * layout emits all schemas. If a schema change ever accidentally reintroduces
     * pricing, this test catches it.
     */
    public function http_render_product_page_has_no_price_or_price_currency_in_json_ld(): void
    {
        $category = $this->makeCategory($this->nextSlug('price-policy'));
        $product  = $this->makeProduct($category, 'price-policy-product');

        // Add an offer with a real price to give the schema something to work with.
        $store = Store::create([
            'tenant_id'        => null,
            'name'             => 'Amazon Test',
            'slug'             => 'amazon-price-test-' . uniqid(),
            'affiliate_params' => 'tag=test-20',
            'commission_rate'  => 5.0,
            'priority'         => 1,
            'is_active'        => true,
        ]);
        ProductOffer::create([
            'product_id'    => $product->id,
            'store_id'      => $store->id,
            'tenant_id'     => null,
            'url'           => 'https://www.amazon.com/dp/PRICETEST',
            'scraped_price' => 149.99,
            'raw_title'     => 'Price Policy Product',
            'stock_status'  => 'in_stock',
        ]);

        tenancy()->end();

        $html = $this->get("/product/{$product->slug}")->getContent();

        $schemas = $this->extractJsonLdBlocks($html);

        $this->assertNotEmpty($schemas, 'Product page must emit at least one JSON-LD block');

        // Encode the full schema array and check for forbidden keys.
        $allSchemasJson = json_encode($schemas);

        $this->assertStringNotContainsString(
            '"price"',
            $allSchemasJson,
            'Amazon Associates ToS: "price" must NOT appear in any JSON-LD schema on a product page'
        );
        $this->assertStringNotContainsString(
            '"priceCurrency"',
            $allSchemasJson,
            'Amazon Associates ToS: "priceCurrency" must NOT appear in any JSON-LD schema on a product page'
        );
    }

    // =========================================================================
    // §5 — SeoSchema meta description preference chain (Spec 023 §6)
    // =========================================================================

    /**
     * @test
     * When preset has seo_description, it wins over preset intro → category fallback.
     */
    public function seo_schema_uses_preset_seo_description_when_set(): void
    {
        $category = Category::factory()->create([
            'name' => 'Gaming Keyboards',
            'slug' => $this->nextSlug('meta-desc-preset'),
        ]);
        Feature::factory()->create(['category_id' => $category->id]);
        $products = collect();

        $preset = $this->makePresetWithContent(
            $category,
            'Streamer',
            ['intro' => '<p>Streamer intro that is longer than the seo_description.</p>', 'faqs' => []],
            'Custom preset seo_description text.',
        );

        $activeSlug = Str::slug($preset->name);
        $seo        = SeoSchema::forCategoryPage($category, collect(), null, null, $activeSlug, $products);

        $this->assertSame(
            'Custom preset seo_description text.',
            $seo['description'],
            'seo_description must take priority over preset intro in meta description'
        );
    }

    /**
     * @test
     * When preset has no seo_description but has seo_content.intro,
     * the meta description is derived from the intro (stripped + truncated).
     */
    public function seo_schema_uses_preset_intro_for_meta_description_when_no_seo_description(): void
    {
        $category = Category::factory()->create([
            'name' => 'Gaming Keyboards',
            'slug' => $this->nextSlug('meta-desc-intro'),
        ]);
        Feature::factory()->create(['category_id' => $category->id]);
        $products = collect();

        $preset = $this->makePresetWithContent(
            $category,
            'Remote Worker',
            [
                'intro' => '<p>If you buy a keyboard for remote work, the most important thing is switch noise level.</p>',
                'faqs'  => [],
            ],
            null, // no seo_description
        );

        $activeSlug = Str::slug($preset->name);
        $seo        = SeoSchema::forCategoryPage($category, collect(), null, null, $activeSlug, $products);

        // The description must NOT contain HTML tags (strip_tags was applied).
        $this->assertStringNotContainsString('<p>', $seo['description']);
        $this->assertStringContainsString('remote work', $seo['description']);
        $this->assertLessThanOrEqual(160, mb_strlen($seo['description']), 'Meta description from intro must be truncated to ~155 chars');
    }

    /**
     * @test
     * When preset has neither seo_description nor seo_content, the meta description
     * falls back to the generic use-case template.
     */
    public function seo_schema_falls_back_to_generic_description_when_preset_has_no_content(): void
    {
        $category = Category::factory()->create([
            'name' => 'Gaming Keyboards',
            'slug' => $this->nextSlug('meta-desc-fallback'),
        ]);
        Feature::factory()->create(['category_id' => $category->id]);

        // Preset with no seo_description and no seo_content.
        $preset = Preset::factory()->create([
            'category_id'     => $category->id,
            'name'            => 'FPS Gamer',
            'seo_description' => null,
            'seo_content'     => null,
        ]);

        $activeSlug = Str::slug($preset->name);
        $seo        = SeoSchema::forCategoryPage($category, collect(), null, null, $activeSlug, collect());

        // Should fall through to the generic fallback (not empty, not an HTML bleed).
        $this->assertNotEmpty($seo['description']);
        $this->assertStringNotContainsString('<p>', $seo['description']);
        // The generic fallback mentions category name or preset name.
        $this->assertTrue(
            str_contains($seo['description'], 'Gaming Keyboards') || str_contains($seo['description'], 'FPS Gamer'),
            'Fallback description must reference category or preset name'
        );
    }

    // =========================================================================
    // §5 — SeoSchema FAQPage unit-level assertions (direct call to forCategoryPage)
    // =========================================================================

    /**
     * @test
     * forCategoryPage with active preset that has seo_content.faqs emits FAQPage
     * as the third schema entry with the preset questions.
     */
    public function seo_schema_emits_faq_page_with_preset_questions_when_preset_active(): void
    {
        $category = Category::factory()->create([
            'name'         => 'Gaming Keyboards',
            'slug'         => $this->nextSlug('faq-unit'),
            'buying_guide' => [
                'faqs' => [
                    ['question' => 'Category-level FAQ?', 'answer' => 'Category answer.'],
                ],
            ],
        ]);
        Feature::factory()->create(['category_id' => $category->id]);
        $category->load('features');

        $preset = $this->makePresetWithContent($category, 'Streamer', [
            'intro' => '<p>Streamer intro.</p>',
            'faqs'  => [
                ['question' => 'Preset FAQ for streamers?', 'answer' => 'Preset answer.'],
            ],
        ]);

        $activeSlug = Str::slug($preset->name);
        $seo        = SeoSchema::forCategoryPage($category, collect(), null, null, $activeSlug, collect());

        $schemas = $seo['schemas'];
        $this->assertCount(3, $schemas, 'Must emit 3 schemas: ItemList, BreadcrumbList, FAQPage');
        $this->assertSame('FAQPage', $schemas[2]['@type']);

        $questions = array_column($schemas[2]['mainEntity'], 'name');
        $this->assertContains('Preset FAQ for streamers?', $questions, 'Preset FAQ must appear in FAQPage');
        $this->assertContains('Category-level FAQ?', $questions, 'Category FAQ must appear too (merged)');

        // Preset question must appear BEFORE category question.
        $presetIdx   = array_search('Preset FAQ for streamers?', $questions, true);
        $categoryIdx = array_search('Category-level FAQ?', $questions, true);
        $this->assertLessThan($categoryIdx, $presetIdx, 'Preset FAQ must appear before category FAQ');
    }

    /**
     * @test
     * forCategoryPage with no active preset emits FAQPage from category faqs only.
     */
    public function seo_schema_emits_faq_page_from_category_only_when_no_preset(): void
    {
        $category = Category::factory()->create([
            'name'         => 'Studio Mics',
            'slug'         => $this->nextSlug('no-preset-faq'),
            'buying_guide' => [
                'faqs' => [
                    ['question' => 'Which mic for vocals?', 'answer' => 'The SM7B.'],
                ],
            ],
        ]);
        Feature::factory()->create(['category_id' => $category->id]);
        $category->load('features');

        // Preset exists but not activated.
        $this->makePresetWithContent($category, 'Podcaster', [
            'intro' => '<p>Podcaster intro.</p>',
            'faqs'  => [['question' => 'Preset-only FAQ?', 'answer' => 'Preset answer.']],
        ]);

        // No activePresetSlug passed.
        $seo = SeoSchema::forCategoryPage($category, collect(), null, null, null, collect());

        $schemas = $seo['schemas'];
        $this->assertCount(3, $schemas, 'Must emit FAQPage from category faqs');

        $questions = array_column($schemas[2]['mainEntity'], 'name');
        $this->assertContains('Which mic for vocals?', $questions);
        $this->assertNotContains('Preset-only FAQ?', $questions, 'Preset FAQ must NOT appear without active preset');
    }

    // =========================================================================
    // §3 — ProductCompare view rendering (Livewire level)
    // =========================================================================

    /**
     * @test
     * When a preset is active and has seo_content.intro, the preset intro renders
     * in place of the category intro.
     */
    public function livewire_renders_preset_intro_when_preset_has_seo_content_intro(): void
    {
        $categoryIntro = 'Category-level intro text that should be replaced.';
        $presetIntro   = 'Streamer-specific intro: if you stream, silent switches matter.';

        $category = $this->makeCategory($this->nextSlug('preset-intro'), [
            'buying_guide' => ['intro' => "<p>{$categoryIntro}</p>"],
        ]);
        $this->makeProduct($category, 'prod-preset-intro');

        $preset     = $this->makePresetWithContent($category, 'Streamer', [
            'intro' => "<p>{$presetIntro}</p>",
            'faqs'  => [],
        ]);
        $presetSlug = Str::slug($preset->name);

        tenancy()->end();

        Livewire::test(ProductCompare::class, ['slug' => $category->slug])
            ->set('activePresetSlug', $presetSlug)
            ->assertStatus(200)
            ->assertSee($presetIntro)
            ->assertDontSee($categoryIntro);
    }

    /**
     * @test
     * When no preset is active (or preset has no seo_content.intro), the component
     * falls back to rendering the category buying_guide.intro.
     */
    public function livewire_falls_back_to_category_intro_when_preset_has_no_intro(): void
    {
        $categoryIntro = 'Category fallback intro that must appear.';

        $category = $this->makeCategory($this->nextSlug('category-intro-fallback'), [
            'buying_guide' => ['intro' => "<p>{$categoryIntro}</p>"],
        ]);
        $this->makeProduct($category, 'prod-cat-intro-fallback');

        // Preset has seo_content with EMPTY intro.
        $preset = Preset::factory()->create([
            'category_id' => $category->id,
            'name'        => 'Minimalist',
            'seo_content' => ['intro' => '', 'faqs' => []],
        ]);
        $presetSlug = Str::slug($preset->name);

        tenancy()->end();

        Livewire::test(ProductCompare::class, ['slug' => $category->slug])
            ->set('activePresetSlug', $presetSlug)
            ->assertStatus(200)
            ->assertSee($categoryIntro);
    }

    /**
     * @test
     * When no preset is active at all, the component renders the category intro.
     */
    public function livewire_renders_category_intro_when_no_preset_active(): void
    {
        $categoryIntro = 'Category intro shown when no preset is active.';

        $category = $this->makeCategory($this->nextSlug('no-active-preset'), [
            'buying_guide' => ['intro' => "<p>{$categoryIntro}</p>"],
        ]);
        $this->makeProduct($category, 'prod-no-preset');

        tenancy()->end();

        Livewire::test(ProductCompare::class, ['slug' => $category->slug])
            ->assertStatus(200)
            ->assertSee($categoryIntro);
    }

    /**
     * @test
     * FAQ deduplication: a category FAQ whose question matches a preset FAQ question
     * appears only once in the rendered page.
     */
    public function livewire_deduplicates_faqs_by_question_between_preset_and_category(): void
    {
        $sharedQuestion  = 'What is the best switch for typing?';
        $presetOnlyQ     = 'Are clicky switches bad for streaming?';
        $categoryOnlyQ   = 'How do I lube a keyboard switch?';

        $category = $this->makeCategory($this->nextSlug('faq-dedup'), [
            'buying_guide' => [
                'faqs' => [
                    ['question' => $sharedQuestion,  'answer' => 'Category version of shared answer.'],
                    ['question' => $categoryOnlyQ,   'answer' => 'Lubing guide.'],
                ],
            ],
        ]);
        $this->makeProduct($category, 'prod-faq-dedup');

        $preset = $this->makePresetWithContent($category, 'Streamer', [
            'intro' => '<p>Streamer preset intro.</p>',
            'faqs'  => [
                ['question' => $presetOnlyQ,    'answer' => 'Yes, clicky switches are bad.'],
                ['question' => $sharedQuestion, 'answer' => 'Preset version of shared answer.'],
            ],
        ]);
        $presetSlug = Str::slug($preset->name);

        tenancy()->end();

        $livewire = Livewire::test(ProductCompare::class, ['slug' => $category->slug])
            ->set('activePresetSlug', $presetSlug)
            ->assertStatus(200);

        $html = $livewire->html();

        // Shared question appears exactly once.
        $this->assertSame(
            1,
            substr_count($html, htmlspecialchars($sharedQuestion)),
            'Shared FAQ question must appear exactly once in the rendered output (deduplication)'
        );

        // All unique questions appear.
        $this->assertStringContainsString($presetOnlyQ,   $html, 'Preset-only FAQ must appear');
        $this->assertStringContainsString($categoryOnlyQ, $html, 'Category-only FAQ must appear');
    }

    /**
     * @test
     * When a preset is active and has FAQs, those FAQs render BEFORE category FAQs
     * in the page output.
     */
    public function livewire_renders_preset_faqs_before_category_faqs(): void
    {
        $presetQ   = 'Are loud switches bad for streaming? (preset)';
        $categoryQ = 'What is the best budget keyboard? (category)';

        $category = $this->makeCategory($this->nextSlug('faq-order'), [
            'buying_guide' => [
                'faqs' => [
                    ['question' => $categoryQ, 'answer' => 'Category answer.'],
                ],
            ],
        ]);
        $this->makeProduct($category, 'prod-faq-order');

        $preset = $this->makePresetWithContent($category, 'Streamer', [
            'intro' => '<p>Streamer intro.</p>',
            'faqs'  => [
                ['question' => $presetQ, 'answer' => 'Yes, loud switches are bad for streaming.'],
            ],
        ]);
        $presetSlug = Str::slug($preset->name);

        tenancy()->end();

        $html = Livewire::test(ProductCompare::class, ['slug' => $category->slug])
            ->set('activePresetSlug', $presetSlug)
            ->assertStatus(200)
            ->html();

        $presetPos   = strpos($html, htmlspecialchars($presetQ));
        $categoryPos = strpos($html, htmlspecialchars($categoryQ));

        $this->assertNotFalse($presetPos, 'Preset FAQ must appear in the rendered HTML');
        $this->assertNotFalse($categoryPos, 'Category FAQ must appear in the rendered HTML');
        $this->assertLessThan($categoryPos, $presetPos, 'Preset FAQ must appear before category FAQ in the rendered output');
    }

    /**
     * @test
     * Full HTTP render: when a preset with seo_content.faqs is active, the page
     * emits 3 JSON-LD blocks (ItemList + BreadcrumbList + FAQPage) containing
     * the preset questions.
     *
     * This is the load-bearing HTTP-level test required by the spec and handoff lesson.
     */
    public function http_render_emits_three_schemas_with_preset_faqs_when_preset_active(): void
    {
        $presetQ = 'What makes a keyboard good for streaming?';

        $category = $this->makeCategory($this->nextSlug('http-three-schemas'));
        $this->makeProduct($category, 'prod-http-three-schemas');

        $preset = $this->makePresetWithContent($category, 'Streamer', [
            'intro' => '<p>Streamer intro.</p>',
            'faqs'  => [
                ['question' => $presetQ, 'answer' => 'Silent switches and no USB polling lag.'],
            ],
        ]);

        tenancy()->end();

        $presetSlug = Str::slug($preset->name);
        $response   = $this->get("/compare/{$category->slug}?preset={$presetSlug}");
        $response->assertStatus(200);

        $html       = $response->getContent();
        $scriptCount = substr_count($html, '<script type="application/ld+json">');

        $this->assertSame(
            3,
            $scriptCount,
            "Expected 3 JSON-LD blocks (ItemList + BreadcrumbList + FAQPage), got {$scriptCount}"
        );

        $this->assertStringContainsString('"@type":"ItemList"', $html);
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);
        $this->assertStringContainsString('"@type":"FAQPage"', $html);

        // The preset question must be inside the rendered FAQPage.
        $this->assertStringContainsString($presetQ, $html);
    }
}
