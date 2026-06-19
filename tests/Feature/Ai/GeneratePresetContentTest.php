<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Feature;
use App\Models\FeaturePreset;
use App\Models\Preset;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\AiService;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 023 — AiService::generatePresetContent tests.
 *
 * Mocks the GeminiService transport only (not the full AiService),
 * following the exact pattern from GenerateCompareContentTest.
 */
class GeneratePresetContentTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'preset-ai-test', 'name' => 'Preset AI Test Tenant']);
        $this->tenant = Tenant::find('preset-ai-test');
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
     * Bind a GeminiService fake that returns the given text as content.
     */
    private function bindGeminiFake(string $responseText): void
    {
        $fake = new class ($responseText) extends GeminiService {
            public function __construct(private string $text) {}

            public function generate(string $prompt, array $config = [], ?string $model = null): array
            {
                return [
                    'content'       => $this->text,
                    'parsed'        => json_decode($this->text, true),
                    'finish_reason' => 'STOP',
                ];
            }
        };

        app()->instance(GeminiService::class, $fake);
    }

    /**
     * Build a Preset with its category, features, and preset-feature weights
     * fully eager-loaded — exactly what the command passes to generatePresetContent.
     */
    private function makePreset(array $presetOverrides = []): Preset
    {
        $category = Category::factory()->create([
            'name' => 'Mechanical Gaming Keyboards',
            'slug' => 'mechanical-gaming-keyboards-ai-' . uniqid(),
        ]);

        $feature1 = Feature::factory()->create(['category_id' => $category->id, 'name' => 'Switch Noise Level']);
        $feature2 = Feature::factory()->create(['category_id' => $category->id, 'name' => 'RGB Lighting']);
        $feature3 = Feature::factory()->create(['category_id' => $category->id, 'name' => 'Build Quality']);

        $brand = Brand::factory()->create();

        Product::factory()->create([
            'category_id' => $category->id,
            'brand_id'    => $brand->id,
            'slug'        => 'keyboard-preset-ai-' . uniqid(),
            'is_ignored'  => false,
            'status'      => null,
        ]);

        $preset = Preset::factory()->create(array_merge([
            'category_id' => $category->id,
            'name'        => 'Streamer',
        ], $presetOverrides));

        // Attach features with weights via pivot.
        FeaturePreset::create(['preset_id' => $preset->id, 'feature_id' => $feature1->id, 'weight' => 90]);
        FeaturePreset::create(['preset_id' => $preset->id, 'feature_id' => $feature2->id, 'weight' => 70]);
        FeaturePreset::create(['preset_id' => $preset->id, 'feature_id' => $feature3->id, 'weight' => 50]);

        // Eager-load exactly what the command loads.
        return $preset->load(['category.features', 'presetFeatures.feature']);
    }

    /**
     * Valid JSON response fixture for a preset.
     */
    private function validJsonResponse(): string
    {
        return json_encode([
            'intro' => '<p>If you are buying a mechanical keyboard for streaming, the most important factor is Switch Noise Level. Loud clicky switches will bleed into your microphone during live streams. The top-ranked keyboards for streamers prioritize silent or tactile switches without the clicky sound signature. Switch Noise Level, RGB Lighting, and Build Quality are the three axes that separate streaming-grade keyboards from general-purpose boards.</p>',
            'faqs'  => [
                ['question' => 'Are louder switches bad for streaming?', 'answer' => 'Yes. Clicky switches register clearly on most microphones even with noise gates. Silent or tactile switches are the correct choice for any streamer using an open mic setup.'],
                ['question' => 'What switch type is best for streamers?', 'answer' => 'Silent linear or silent tactile switches (e.g., Cherry MX Silent Red, Topre) are standard. They deliver fast actuation without the audible click that ruins stream audio.'],
                ['question' => 'Does RGB affect streaming performance?', 'answer' => 'Indirectly. RGB draws viewer attention in webcam shots and can cause glare. A keyboard with controllable per-key lighting is preferable so you can dial it down during recording.'],
            ],
        ]);
    }

    // =========================================================================
    // Test 1: Happy path — returns correct shape
    // =========================================================================

    /** @test */
    public function generate_preset_content_returns_expected_array_shape(): void
    {
        $this->bindGeminiFake($this->validJsonResponse());

        $preset = $this->makePreset();
        $ai     = app(AiService::class);
        $result = $ai->generatePresetContent($preset);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('intro', $result);
        $this->assertArrayHasKey('faqs', $result);

        $this->assertIsString($result['intro']);
        $this->assertNotEmpty($result['intro']);

        $this->assertIsArray($result['faqs']);
        $this->assertNotEmpty($result['faqs']);

        // Each FAQ must have question + answer strings.
        foreach ($result['faqs'] as $faq) {
            $this->assertArrayHasKey('question', $faq);
            $this->assertArrayHasKey('answer', $faq);
            $this->assertIsString($faq['question']);
            $this->assertIsString($faq['answer']);
        }

        // Confirm content passed through faithfully.
        $this->assertStringContainsString('streaming', $result['intro']);
    }

    // =========================================================================
    // Test 2: generatePresetContent does NOT return a 'methodology' key
    // =========================================================================

    /** @test */
    public function generate_preset_content_does_not_return_methodology_key(): void
    {
        $this->bindGeminiFake($this->validJsonResponse());

        $preset = $this->makePreset();
        $result = app(AiService::class)->generatePresetContent($preset);

        $this->assertArrayNotHasKey(
            'methodology',
            $result,
            'generatePresetContent must not return a "methodology" key — that is generateCompareContent only'
        );
    }

    // =========================================================================
    // Test 3: Malformed JSON throws InvalidArgumentException
    // =========================================================================

    /** @test */
    public function generate_preset_content_throws_on_malformed_json_response(): void
    {
        $this->bindGeminiFake('not valid json !!{]');

        $preset = $this->makePreset();
        $ai     = app(AiService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/malformed JSON/i');

        $ai->generatePresetContent($preset);
    }

    // =========================================================================
    // Test 4: Missing 'intro' key throws InvalidArgumentException
    // =========================================================================

    /** @test */
    public function generate_preset_content_throws_on_missing_intro_key(): void
    {
        $responseWithoutIntro = json_encode([
            'faqs' => [
                ['question' => 'Q?', 'answer' => 'A.'],
            ],
            // 'intro' missing
        ]);

        $this->bindGeminiFake($responseWithoutIntro);

        $preset = $this->makePreset();

        $this->expectException(\InvalidArgumentException::class);

        app(AiService::class)->generatePresetContent($preset);
    }

    // =========================================================================
    // Test 5: Missing 'faqs' key throws InvalidArgumentException
    // =========================================================================

    /** @test */
    public function generate_preset_content_throws_on_missing_faqs_key(): void
    {
        $responseWithoutFaqs = json_encode([
            'intro' => '<p>Some intro.</p>',
            // 'faqs' missing
        ]);

        $this->bindGeminiFake($responseWithoutFaqs);

        $preset = $this->makePreset();

        $this->expectException(\InvalidArgumentException::class);

        app(AiService::class)->generatePresetContent($preset);
    }

    // =========================================================================
    // Test 6: FAQ entry missing 'answer' key throws InvalidArgumentException
    // =========================================================================

    /** @test */
    public function generate_preset_content_throws_when_faq_entry_missing_answer(): void
    {
        $malformedFaqs = json_encode([
            'intro' => '<p>Intro text.</p>',
            'faqs'  => [
                ['question' => 'Good FAQ?', 'answer' => 'Yes.'],
                ['question' => 'Missing answer?'],  // no 'answer' key
            ],
        ]);

        $this->bindGeminiFake($malformedFaqs);

        $preset = $this->makePreset();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/faqs\[\d\]/');

        app(AiService::class)->generatePresetContent($preset);
    }

    // =========================================================================
    // Test 7: FAQ entry missing 'question' key throws InvalidArgumentException
    // =========================================================================

    /** @test */
    public function generate_preset_content_throws_when_faq_entry_missing_question(): void
    {
        $malformedFaqs = json_encode([
            'intro' => '<p>Intro text.</p>',
            'faqs'  => [
                ['answer' => 'Answer without question key.'],  // no 'question'
            ],
        ]);

        $this->bindGeminiFake($malformedFaqs);

        $preset = $this->makePreset();

        $this->expectException(\InvalidArgumentException::class);

        app(AiService::class)->generatePresetContent($preset);
    }

    // =========================================================================
    // Test 8: Empty faqs array throws InvalidArgumentException
    // =========================================================================

    /** @test */
    public function generate_preset_content_throws_when_faqs_is_empty_array(): void
    {
        $emptyFaqs = json_encode([
            'intro' => '<p>Intro text.</p>',
            'faqs'  => [],  // empty — not allowed
        ]);

        $this->bindGeminiFake($emptyFaqs);

        $preset = $this->makePreset();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/non-empty array/i');

        app(AiService::class)->generatePresetContent($preset);
    }

    // =========================================================================
    // Test 9: Markdown code fences are stripped before parsing
    // =========================================================================

    /** @test */
    public function generate_preset_content_strips_markdown_code_fences(): void
    {
        $fencedJson = "```json\n" . $this->validJsonResponse() . "\n```";

        $this->bindGeminiFake($fencedJson);

        $preset = $this->makePreset();

        // Must not throw — fences must be stripped before JSON decode.
        $result = app(AiService::class)->generatePresetContent($preset);

        $this->assertArrayHasKey('intro', $result);
        $this->assertArrayHasKey('faqs', $result);
    }

    // =========================================================================
    // Test 10: Uses admin_model (not site_model)
    // =========================================================================

    /** @test */
    public function generate_preset_content_uses_admin_model(): void
    {
        $usedModel = null;

        $spy = new class ($usedModel) extends GeminiService {
            public ?string $usedModel = null;

            public function __construct(?string &$ref) {}

            public function generate(string $prompt, array $config = [], ?string $model = null): array
            {
                $this->usedModel = $model;
                return [
                    'content'       => json_encode([
                        'intro' => '<p>Intro.</p>',
                        'faqs'  => [['question' => 'Q?', 'answer' => 'A.']],
                    ]),
                    'finish_reason' => 'STOP',
                ];
            }
        };

        app()->instance(GeminiService::class, $spy);

        $preset = $this->makePreset();
        app(AiService::class)->generatePresetContent($preset);

        $this->assertSame(
            config('services.gemini.admin_model'),
            $spy->usedModel,
            'generatePresetContent must use admin_model, not site_model'
        );
    }

    // =========================================================================
    // Test 11: GeminiService exception propagates unchanged
    // =========================================================================

    /** @test */
    public function generate_preset_content_propagates_gemini_exception(): void
    {
        $throwing = new class extends GeminiService {
            public function __construct() {}

            public function generate(string $prompt, array $config = [], ?string $model = null): array
            {
                throw new \Exception('Gemini quota exceeded.');
            }
        };

        app()->instance(GeminiService::class, $throwing);

        $preset = $this->makePreset();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Gemini quota exceeded.');

        app(AiService::class)->generatePresetContent($preset);
    }
}
