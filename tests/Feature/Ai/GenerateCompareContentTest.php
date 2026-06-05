<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\Category;
use App\Models\Feature;
use App\Models\Product;
use App\Models\Brand;
use App\Models\Tenant;
use App\Services\AiService;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateCompareContentTest extends TestCase
{
    use RefreshDatabase;

    /** @var \App\Models\Tenant */
    protected Tenant $tenant;

    /** Counter to guarantee unique product slugs within each test method. */
    private int $slugCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->slugCounter = 0;
        Tenant::create(['id' => 'test-ai', 'name' => 'Test AI Tenant']);
        $this->tenant = Tenant::find('test-ai');
        tenancy()->initialize($this->tenant);
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helper: bind a GeminiService fake that returns a given response text
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Helper: build a minimal category with eager-loaded features + products
    // -------------------------------------------------------------------------

    private function makeCategory(array $overrides = []): Category
    {
        $category = Category::factory()->create(array_merge([
            'name' => 'Studio Microphones',
            'slug' => 'studio-microphones',
        ], $overrides));

        Feature::factory()->count(3)->create(['category_id' => $category->id]);
        $brand = Brand::factory()->create();

        for ($i = 0; $i < 2; $i++) {
            $this->slugCounter++;
            Product::factory()->create([
                'category_id'          => $category->id,
                'brand_id'             => $brand->id,
                'slug'                 => 'product-ai-' . $this->slugCounter,
                'amazon_reviews_count' => 100,
            ]);
        }

        return $category->load(['features', 'products']);
    }

    // -------------------------------------------------------------------------
    // Valid JSON response fixture
    // -------------------------------------------------------------------------

    private function validJsonResponse(): string
    {
        return json_encode([
            'intro'       => '<p>The best studio microphones for professional recording.</p>',
            'methodology' => 'We rank microphones by Sound Quality, Build Quality, and Frequency Response using real Amazon review data.',
            'faqs'        => [
                ['question' => 'What is the best mic for beginners?', 'answer' => 'The Rode NT-USB Mini is a great starter choice.'],
                ['question' => 'How much should I spend?', 'answer' => 'Budget $100-200 for a solid entry-level condenser.'],
            ],
        ]);
    }

    // =========================================================================
    // Test 1: Happy path — returns expected array shape
    // =========================================================================

    /** @test */
    public function generate_compare_content_returns_expected_array_shape(): void
    {
        $this->bindGeminiFake($this->validJsonResponse());

        $category = $this->makeCategory();
        $ai       = app(AiService::class);
        $result   = $ai->generateCompareContent($category);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('intro', $result);
        $this->assertArrayHasKey('methodology', $result);
        $this->assertArrayHasKey('faqs', $result);

        $this->assertIsString($result['intro']);
        $this->assertIsString($result['methodology']);
        $this->assertIsArray($result['faqs']);
        $this->assertNotEmpty($result['faqs']);

        // Confirm values passed through faithfully
        $this->assertStringContainsString('best studio microphones', $result['intro']);
        $this->assertStringContainsString('Sound Quality', $result['methodology']);
    }

    // =========================================================================
    // Test 2: Malformed JSON throws InvalidArgumentException
    // =========================================================================

    /** @test */
    public function generate_compare_content_throws_on_malformed_json_response(): void
    {
        $this->bindGeminiFake('not valid json at all !!');

        $category = $this->makeCategory();
        $ai       = app(AiService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/malformed JSON/i');

        $ai->generateCompareContent($category);
    }

    // =========================================================================
    // Test 3: Missing required key throws InvalidArgumentException
    // =========================================================================

    /** @test */
    public function generate_compare_content_throws_on_missing_required_keys(): void
    {
        // Response has intro + methodology but missing faqs entirely
        $responseWithoutFaqs = json_encode([
            'intro'       => '<p>Some intro.</p>',
            'methodology' => 'Some methodology.',
        ]);

        $this->bindGeminiFake($responseWithoutFaqs);

        $category = $this->makeCategory();
        $ai       = app(AiService::class);

        $this->expectException(\InvalidArgumentException::class);

        $ai->generateCompareContent($category);
    }

    // =========================================================================
    // Test 4: FAQ entry missing 'answer' key throws InvalidArgumentException
    // =========================================================================

    /** @test */
    public function generate_compare_content_validates_each_faq_shape(): void
    {
        // faqs array present but one entry is missing 'answer'
        $malformedFaqs = json_encode([
            'intro'       => '<p>Intro text.</p>',
            'methodology' => 'Methodology text.',
            'faqs'        => [
                ['question' => 'Good FAQ with both keys?', 'answer' => 'Yes.'],
                ['question' => 'This FAQ is missing answer key.'],  // <- no 'answer'
            ],
        ]);

        $this->bindGeminiFake($malformedFaqs);

        $category = $this->makeCategory();
        $ai       = app(AiService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/faqs\[\d\]/');

        $ai->generateCompareContent($category);
    }

    // =========================================================================
    // Test 5: Empty faqs array throws InvalidArgumentException
    // =========================================================================

    /** @test */
    public function generate_compare_content_throws_when_faqs_array_is_empty(): void
    {
        $emptyFaqs = json_encode([
            'intro'       => '<p>Intro text.</p>',
            'methodology' => 'Methodology text.',
            'faqs'        => [],  // empty — not allowed
        ]);

        $this->bindGeminiFake($emptyFaqs);

        $category = $this->makeCategory();
        $ai       = app(AiService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/non-empty array/i');

        $ai->generateCompareContent($category);
    }

    // =========================================================================
    // Test 6: Markdown code fences are stripped and JSON still parses
    // =========================================================================

    /** @test */
    public function generate_compare_content_strips_markdown_code_fences(): void
    {
        // Wrap valid JSON in ```json ... ``` fences as Gemini sometimes does
        $fencedJson = "```json\n" . $this->validJsonResponse() . "\n```";

        $this->bindGeminiFake($fencedJson);

        $category = $this->makeCategory();
        $ai       = app(AiService::class);

        // Should NOT throw — fences must be stripped before parsing
        $result = $ai->generateCompareContent($category);

        $this->assertArrayHasKey('intro', $result);
        $this->assertArrayHasKey('methodology', $result);
        $this->assertArrayHasKey('faqs', $result);
    }

    // =========================================================================
    // Test 7: GeminiService exception propagates unchanged
    // =========================================================================

    /** @test */
    public function generate_compare_content_propagates_gemini_service_exception(): void
    {
        $throwing = new class extends GeminiService {
            public function __construct() {}

            public function generate(string $prompt, array $config = [], ?string $model = null): array
            {
                throw new \Exception('Gemini rate limit hit. Please retry.');
            }
        };

        app()->instance(GeminiService::class, $throwing);

        $category = $this->makeCategory();
        $ai       = app(AiService::class);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Gemini rate limit hit. Please retry.');

        $ai->generateCompareContent($category);
    }

    // =========================================================================
    // Test 8: FAQ missing 'question' key also fails validation
    // =========================================================================

    /** @test */
    public function generate_compare_content_validates_faq_question_key(): void
    {
        $missingQuestion = json_encode([
            'intro'       => '<p>Intro.</p>',
            'methodology' => 'Methodology.',
            'faqs'        => [
                ['answer' => 'Answer without a question key.'],  // <- no 'question'
            ],
        ]);

        $this->bindGeminiFake($missingQuestion);

        $category = $this->makeCategory();
        $ai       = app(AiService::class);

        $this->expectException(\InvalidArgumentException::class);

        $ai->generateCompareContent($category);
    }
}
