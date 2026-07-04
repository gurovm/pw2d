<?php

namespace Tests\Feature;

use App\Jobs\ProcessPendingProduct;
use App\Models\Category;
use App\Models\Feature;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression coverage for the AI-name/slug length cap (prevention fix): the
 * AI Bouncer can occasionally echo the raw, verbose Amazon marketing title
 * back as the "clean" name. ProcessPendingProduct must defensively cap the
 * stored name (and the slug derived from it) regardless of what the AI
 * returns.
 */
class ProductNameSlugCapTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::factory()->create([
            'budget_max'   => 50,
            'midrange_max' => 150,
        ]);
        Feature::factory()->count(2)->create(['category_id' => $this->category->id]);
    }

    /**
     * Fake a single Gemini response shaped like AiService::evaluateProduct's
     * expected JSON payload.
     */
    private function fakeEvaluateProductResponse(string $name, string $brand): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content'      => ['parts' => [['text' => json_encode([
                        'name'       => $name,
                        'brand'      => $brand,
                        'ai_summary' => 'Adequate performer for the price.',
                        'price_tier' => 2,
                        'features'   => [],
                    ])]]],
                    'finishReason' => 'STOP',
                ]],
            ]),
        ]);
    }

    /** @test */
    public function verbose_ai_name_is_capped_to_eight_words_with_bounded_slug(): void
    {
        // The exact bug pattern: AI Bouncer echoes the raw 25-word marketing
        // title back as "name" instead of a clean "Brand Model".
        $rawTitle = 'Keychron K6 Bluetooth 5.1 Wireless Mechanical Keyboard with Keychron K Pro Brown Switch LED Backlit Rechargeable Battery 68 Keys Compact Layout for Mac and Windows';

        $product = Product::create([
            'category_id' => $this->category->id,
            'name'        => mb_substr($rawTitle, 0, 255),
            'slug'        => 'stub-slug-' . uniqid(),
            'price_tier'  => 2,
            'status'      => 'pending_ai',
            'is_ignored'  => false,
        ]);

        $this->fakeEvaluateProductResponse($rawTitle, 'Keychron');

        (new ProcessPendingProduct($product->id, $this->category->id))->handle();

        $product->refresh();

        $this->assertNull($product->status, 'Product should be fully processed.');

        $words = preg_split('/\s+/', trim($product->name));
        $this->assertLessThanOrEqual(8, count($words), "Stored name has too many words: \"{$product->name}\"");

        $stopwords = ['with', 'for', 'and', 'the', 'of', 'in'];
        $this->assertNotContains(
            mb_strtolower((string) end($words)),
            $stopwords,
            "Name ends with a trailing stopword: \"{$product->name}\""
        );

        // 8 words + '-' + 5-char random suffix should comfortably stay under ~75 chars.
        $this->assertLessThan(75, mb_strlen($product->slug), "Slug too long: \"{$product->slug}\"");
    }

    /** @test */
    public function clean_short_ai_name_passes_through_unchanged(): void
    {
        // Name is intentionally >= 20 chars so ProcessPendingProduct's existing
        // "AI returned just the brand" guard (mb_strlen($aiName) < 20) does not
        // kick in and swap it for the original scraped title.
        $cleanName = 'Keychron K6 Wireless Keyboard';

        $product = Product::create([
            'category_id' => $this->category->id,
            'name'        => 'Keychron K6 Bluetooth Mechanical Keyboard Compact 68 Keys',
            'slug'        => 'stub-slug-' . uniqid(),
            'price_tier'  => 2,
            'status'      => 'pending_ai',
            'is_ignored'  => false,
        ]);

        $this->fakeEvaluateProductResponse($cleanName, 'Keychron');

        (new ProcessPendingProduct($product->id, $this->category->id))->handle();

        $product->refresh();

        $this->assertSame($cleanName, $product->name);
        $this->assertStringStartsWith('keychron-k6-wireless-keyboard-', $product->slug);
    }
}
