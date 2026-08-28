<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiUsage;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Feature;
use App\Models\FeaturePreset;
use App\Models\Preset;
use App\Models\Product;
use App\Models\ProductFeatureValue;
use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Spec 038 §2 item 3 — pin every one of the 13 `purpose` strings AiService can
 * record on `ai_usage`, exercised through the real AiService method (only the
 * external Gemini HTTP call is faked, per the project's testing standards).
 *
 * `evaluate_product`, `rescan_features`, and `match_product` are deliberately
 * NOT duplicated here — they are exhaustively pinned end-to-end (through the
 * real queued job / matchProduct() call, with the exact production no-tenancy
 * constraint) in AiUsageJobAttributionTest.php. Re-testing them against a bare
 * AiService call here would be a weaker near-duplicate of that coverage, not
 * an addition to it.
 */
class AiUsagePurposeAttributionTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGeminiJson(array $jsonBody): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content'      => ['parts' => [['text' => json_encode($jsonBody)]]],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => [
                    'promptTokenCount'     => 100,
                    'candidatesTokenCount' => 50,
                ],
            ]),
        ]);
    }

    private function fakeGeminiText(string $text): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content'      => ['parts' => [['text' => $text]]],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => [
                    'promptTokenCount'     => 100,
                    'candidatesTokenCount' => 50,
                ],
            ]),
        ]);
    }

    /** @test */
    public function parse_search_query_records_the_parse_search_query_purpose(): void
    {
        $this->fakeGeminiJson([
            'suggested_category_slug' => 'gaming-headsets',
            'reasoning'               => 'User wants gaming audio.',
        ]);

        app(AiService::class)->parseSearchQuery('best headset for gaming', [
            ['name' => 'Gaming Headsets', 'slug' => 'gaming-headsets', 'presets' => []],
        ]);

        $this->assertSame('parse_search_query', AiUsage::sole()->purpose);
    }

    /** @test */
    public function chat_response_records_the_chat_response_purpose(): void
    {
        $this->fakeGeminiJson([
            'status'               => 'complete',
            'message'              => 'Optimized for podcasting.',
            'weights'              => ['1' => 90],
            'price_weight'         => 60,
            'amazon_rating_weight' => 70,
        ]);

        app(AiService::class)->chatResponse(
            'USB Microphones',
            ['1' => ['name' => 'Voice Clarity']],
            'I need a mic for podcasting',
        );

        $this->assertSame('chat_response', AiUsage::sole()->purpose);
    }

    /** @test */
    public function sweep_category_pollution_records_the_sweep_category_purpose(): void
    {
        $category = Category::factory()->create();
        $product  = Product::factory()->create([
            'category_id' => $category->id,
            'slug'        => 'sweep-purpose-' . uniqid(),
            'status'      => null,
        ]);

        $this->fakeGeminiJson([]);

        app(AiService::class)->sweepCategoryPollution($category, collect([$product]));

        $this->assertSame('sweep_category', AiUsage::sole()->purpose);
    }

    /** @test */
    public function assign_categories_records_the_assign_categories_purpose(): void
    {
        $category = Category::factory()->create();
        $product  = Product::factory()->create([
            'category_id' => null,
            'slug'        => 'assign-purpose-' . uniqid(),
        ]);

        $this->fakeGeminiJson([
            ['id' => $product->id, 'category_id' => $category->id, 'reason' => 'Matches category.'],
        ]);

        app(AiService::class)->assignCategories(
            collect([$product]),
            [['id' => $category->id, 'name' => $category->name, 'description' => $category->description]],
        );

        $this->assertSame('assign_categories', AiUsage::sole()->purpose);
    }

    /** @test */
    public function analyze_search_trends_records_the_analyze_search_trends_purpose(): void
    {
        $this->fakeGeminiText("## Trending Intents\n- Users search for espresso machines.");

        $logs = new Collection([
            (object) [
                'type'             => 'global_search',
                'query'            => 'best espresso machine',
                'results_count'    => 3,
                'category_name'    => 'Espresso',
                'response_summary' => null,
            ],
        ]);

        app(AiService::class)->analyzeSearchTrends($logs);

        $this->assertSame('analyze_search_trends', AiUsage::sole()->purpose);
    }

    /** @test */
    public function generate_category_content_records_the_category_content_purpose(): void
    {
        $this->fakeGeminiText('Well-written, specific category buying-guide prose.');

        app(AiService::class)->generateCategoryContent('Write buying-guide content for Studio Microphones.');

        $this->assertSame('category_content', AiUsage::sole()->purpose);
    }

    /** @test */
    public function extract_product_from_text_records_the_extract_product_purpose(): void
    {
        $this->fakeGeminiJson([
            'name'     => 'Logitech MX Master 3S',
            'brand'    => 'Logitech',
            'features' => ['DPI' => 8000],
        ]);

        app(AiService::class)->extractProductFromText(
            'Logitech MX Master 3S mouse with 8000 DPI...',
            ['DPI' => ['unit' => 'dpi', 'is_higher_better' => true]],
        );

        $this->assertSame('extract_product', AiUsage::sole()->purpose);
    }

    /** @test */
    public function generate_preset_content_records_the_preset_content_purpose(): void
    {
        $category = Category::factory()->create([
            'name' => 'Mechanical Gaming Keyboards',
            'slug' => 'mech-keyboards-purpose-' . uniqid(),
        ]);
        $feature = Feature::factory()->create(['category_id' => $category->id, 'name' => 'Switch Noise Level']);
        $brand   = Brand::factory()->create();

        Product::factory()->create([
            'category_id' => $category->id,
            'brand_id'    => $brand->id,
            'slug'        => 'keyboard-purpose-' . uniqid(),
            'is_ignored'  => false,
            'status'      => null,
        ]);

        $preset = Preset::factory()->create(['category_id' => $category->id, 'name' => 'Streamer']);
        FeaturePreset::create(['preset_id' => $preset->id, 'feature_id' => $feature->id, 'weight' => 90]);
        $preset->load(['category.features', 'presetFeatures.feature']);

        $this->fakeGeminiJson([
            'intro' => '<p>If you are buying a mechanical keyboard for streaming, silent switches matter most.</p>',
            'faqs'  => [
                ['question' => 'Are louder switches bad for streaming?', 'answer' => 'Yes, clicky switches register on most microphones.'],
            ],
        ]);

        app(AiService::class)->generatePresetContent($preset);

        $this->assertSame('preset_content', AiUsage::sole()->purpose);
    }

    /** @test */
    public function generate_compare_content_records_the_compare_content_purpose(): void
    {
        $category = Category::factory()->create([
            'name' => 'Studio Microphones',
            'slug' => 'studio-mics-purpose-' . uniqid(),
        ]);
        Feature::factory()->create(['category_id' => $category->id]);
        $brand = Brand::factory()->create();
        Product::factory()->create([
            'category_id'          => $category->id,
            'brand_id'             => $brand->id,
            'slug'                 => 'mic-purpose-' . uniqid(),
            'amazon_reviews_count' => 100,
        ]);
        $category->load(['features', 'products']);

        $this->fakeGeminiJson([
            'intro'       => '<p>The best studio microphones for professional recording.</p>',
            'methodology' => 'We rank microphones by Sound Quality and Build Quality using real Amazon review data.',
            'faqs'        => [
                ['question' => 'What is the best mic for beginners?', 'answer' => 'The Rode NT-USB Mini is a great starter choice.'],
            ],
        ]);

        app(AiService::class)->generateCompareContent($category);

        $this->assertSame('compare_content', AiUsage::sole()->purpose);
    }

    /** @test */
    public function generate_landing_page_content_records_the_landing_page_content_purpose(): void
    {
        $category = Category::factory()->create([
            'name' => 'Studio Microphones',
            'slug' => 'studio-mics-landing-purpose-' . uniqid(),
        ]);
        $feature = Feature::factory()->create(['category_id' => $category->id, 'name' => 'Sound Quality']);
        $brand   = Brand::factory()->create(['name' => 'Rode']);

        $product = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id'    => $brand->id,
            'slug'        => 'rode-nt-usb-landing-purpose-' . uniqid(),
            'ai_summary'  => 'Solid budget USB mic.',
            'price_tier'  => 1,
        ]);
        ProductFeatureValue::factory()->create([
            'product_id' => $product->id,
            'feature_id' => $feature->id,
            'raw_value'  => 80,
        ]);
        $product->load(['brand', 'featureValues.feature', 'offers']);

        $picks = [
            ['product_id' => $product->id, 'role' => 'overall', 'product' => $product],
        ];

        $this->fakeGeminiJson([
            'intro' => '<p>We scored every studio microphone in our database and ranked them below.</p>',
            'picks' => [
                ['product_id' => $product->id, 'headline' => 'Best overall mic for the price', 'body' => '<p>A clear, honest pick with a real tradeoff.</p>'],
            ],
            'faqs' => [
                ['question' => 'What is the best mic for streaming?', 'answer' => 'The pick above balances price and sound quality well.'],
            ],
            'methodology_note' => 'We weighted Sound Quality most heavily for this category.',
        ]);

        app(AiService::class)->generateLandingPageContent($category, $picks, [], 1);

        $this->assertSame('landing_page_content', AiUsage::sole()->purpose);
    }
}
