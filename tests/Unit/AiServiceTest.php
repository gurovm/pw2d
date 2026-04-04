<?php

namespace Tests\Unit;

use App\Models\SearchLog;
use App\Services\AiService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiServiceTest extends TestCase
{
    private function fakeGeminiResponse(array $jsonBody, string $finishReason = 'STOP'): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode($jsonBody)]]],
                    'finishReason' => $finishReason,
                ]],
            ]),
        ]);
    }

    private function fakeGeminiTextResponse(string $text, string $finishReason = 'STOP'): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => $text]]],
                    'finishReason' => $finishReason,
                ]],
            ]),
        ]);
    }

    /** @test */
    public function evaluate_product_returns_parsed_json(): void
    {
        $this->fakeGeminiResponse([
            'name' => 'Breville Barista Express',
            'brand' => 'Breville',
            'ai_summary' => 'A solid machine.',
            'price_tier' => 3,
            'features' => ['Build Quality' => ['score' => 85, 'reason' => 'Great build.']],
        ]);

        $service = app(AiService::class);
        $result = $service->evaluateProduct(
            'Breville Barista Express BES870XL', 549.95, 'Premium (over $300)', '4.4/5 stars (27342 reviews)',
            'Semi-Automatic Espresso Machines', ['Build Quality' => ['unit' => null, 'is_higher_better' => true]]
        );

        $this->assertEquals('Breville Barista Express', $result['parsed']['name']);
        $this->assertEquals('Breville', $result['parsed']['brand']);
    }

    /** @test */
    public function rescan_features_returns_feature_scores(): void
    {
        $this->fakeGeminiResponse([
            'features' => ['Sound Quality' => ['score' => 78, 'reason' => 'Clear audio.']],
        ]);

        $service = app(AiService::class);
        $result = $service->rescanFeatures(
            'Sony WH-1000XM5', 'Premium (over $200)', '4.7/5 stars',
            ['Sound Quality' => ['unit' => null, 'is_higher_better' => true]]
        );

        $this->assertEquals(78, $result['parsed']['features']['Sound Quality']['score']);
    }

    /** @test */
    public function parse_search_query_returns_category_slug(): void
    {
        $this->fakeGeminiResponse([
            'suggested_category_slug' => 'gaming-headsets',
            'reasoning' => 'User wants gaming audio.',
        ]);

        $service = app(AiService::class);
        $result = $service->parseSearchQuery('best headset for gaming', [
            ['name' => 'Gaming Headsets', 'slug' => 'gaming-headsets', 'presets' => []],
        ]);

        $this->assertEquals('gaming-headsets', $result['parsed']['suggested_category_slug']);
    }

    /** @test */
    public function chat_response_returns_weights(): void
    {
        $this->fakeGeminiResponse([
            'status' => 'complete',
            'message' => 'Optimized for podcasting.',
            'weights' => ['1' => 90, '2' => 30],
            'price_weight' => 60,
            'amazon_rating_weight' => 70,
        ]);

        $service = app(AiService::class);
        $result = $service->chatResponse(
            'USB Microphones', ['1' => ['name' => 'Voice Clarity'], '2' => ['name' => 'Build Quality']],
            'I need a mic for podcasting', []
        );

        $this->assertEquals('complete', $result['parsed']['status']);
        $this->assertEquals(90, $result['parsed']['weights']['1']);
    }

    /** @test */
    public function extract_product_from_text_returns_product_data(): void
    {
        $this->fakeGeminiResponse([
            'name' => 'Logitech MX Master 3S',
            'brand' => 'Logitech',
            'features' => ['DPI' => 8000, 'Weight' => 141],
        ]);

        $service = app(AiService::class);
        $result = $service->extractProductFromText(
            'Logitech MX Master 3S mouse with 8000 DPI...',
            ['DPI' => ['unit' => 'dpi', 'is_higher_better' => true], 'Weight' => ['unit' => 'grams', 'is_higher_better' => false]]
        );

        $this->assertEquals('Logitech MX Master 3S', $result['parsed']['name']);
    }

    /** @test */
    public function evaluate_product_uses_admin_model(): void
    {
        $this->fakeGeminiResponse(['name' => 'Test', 'brand' => 'Test', 'features' => []]);

        $service = app(AiService::class);
        $service->evaluateProduct('Test', 10.0, 'Budget', 'no rating', 'Test Category', []);

        Http::assertSent(fn ($request) => str_contains($request->url(), config('services.gemini.admin_model')));
    }

    /** @test */
    public function parse_search_query_uses_site_model(): void
    {
        $this->fakeGeminiResponse(['suggested_category_slug' => 'test', 'reasoning' => 'test']);

        $service = app(AiService::class);
        $service->parseSearchQuery('test query', []);

        Http::assertSent(fn ($request) => str_contains($request->url(), config('services.gemini.site_model')));
    }

    /** @test */
    public function analyze_search_trends_returns_markdown_string(): void
    {
        $markdownReport = "## Trending Intents\n- Users are searching for espresso machines.\n\n## Actionable Recommendations\n1. Add more products.";
        $this->fakeGeminiTextResponse($markdownReport);

        $logs = new Collection([
            (object) ['type' => 'homepage_ai', 'query' => 'best espresso machine', 'results_count' => 1, 'category_name' => 'Espresso', 'response_summary' => 'Redirected to espresso'],
            (object) ['type' => 'category_ai', 'query' => 'breville barista', 'results_count' => 5, 'category_name' => 'Semi-Auto', 'response_summary' => null],
        ]);

        $service = app(AiService::class);
        $result = $service->analyzeSearchTrends($logs);

        $this->assertIsString($result);
        $this->assertStringContainsString('Trending Intents', $result);
        $this->assertStringContainsString('Actionable Recommendations', $result);
    }

    /** @test */
    public function analyze_search_trends_uses_admin_model(): void
    {
        $this->fakeGeminiTextResponse('# Report');

        $logs = new Collection([
            (object) ['type' => 'global_search', 'query' => 'test', 'results_count' => 3, 'category_name' => null, 'response_summary' => null],
        ]);

        $service = app(AiService::class);
        $service->analyzeSearchTrends($logs);

        Http::assertSent(fn ($request) => str_contains($request->url(), config('services.gemini.admin_model')));
    }

    /** @test */
    public function analyze_search_trends_handles_empty_collection(): void
    {
        $this->fakeGeminiTextResponse('# Empty Report');

        $service = app(AiService::class);
        $result = $service->analyzeSearchTrends(new Collection([]));

        $this->assertIsString($result);
    }
}