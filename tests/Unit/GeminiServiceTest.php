<?php

namespace Tests\Unit;

use App\Services\GeminiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.api_key'    => 'test-api-key-123',
            'services.gemini.site_model' => 'gemini-2.5-flash',
        ]);
    }

    public function test_api_key_is_sent_in_header_not_query_string(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content'      => ['parts' => [['text' => '{"result": "ok"}']]],
                    'finishReason' => 'STOP',
                ]],
            ]),
        ]);

        $service = new GeminiService();
        $service->generate('Test prompt');

        Http::assertSent(function ($request) {
            // API key must be in the header
            $hasHeader = $request->hasHeader('x-goog-api-key', 'test-api-key-123');

            // API key must NOT be in the URL query string
            $urlHasKey = str_contains($request->url(), 'key=');

            return $hasHeader && !$urlHasKey;
        });
    }

    public function test_markdown_fences_are_stripped_from_response(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content'      => ['parts' => [['text' => "```json\n{\"name\": \"Test Product\"}\n```"]]],
                    'finishReason' => 'STOP',
                ]],
            ]),
        ]);

        $service = new GeminiService();
        $result = $service->generate('Test prompt');

        $this->assertSame('{"name": "Test Product"}', $result['content']);
        $this->assertSame(['name' => 'Test Product'], $result['parsed']);
        $this->assertSame('STOP', $result['finish_reason']);
    }

    public function test_429_status_throws_rate_limit_exception(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response('Rate limited', 429),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Gemini rate limit hit. Please retry.');

        $service = new GeminiService();
        $service->generate('Test prompt');
    }

    public function test_500_status_throws_generic_api_error(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response('Server error', 500),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Gemini API error: 500');

        $service = new GeminiService();
        $service->generate('Test prompt');
    }

    public function test_max_tokens_finish_reason_throws(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content'      => ['parts' => [['text' => '{"partial": "data']]],
                    'finishReason' => 'MAX_TOKENS',
                ]],
            ]),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Gemini response truncated (MAX_TOKENS).');

        $service = new GeminiService();
        $service->generate('Test prompt');
    }

    public function test_successful_response_returns_parsed_json(): void
    {
        $jsonPayload = ['name' => 'Shure MV7', 'brand' => 'Shure', 'ai_summary' => 'Great mic.'];

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content'      => ['parts' => [['text' => json_encode($jsonPayload)]]],
                    'finishReason' => 'STOP',
                ]],
            ]),
        ]);

        $service = new GeminiService();
        $result = $service->generate('Test prompt');

        $this->assertSame($jsonPayload, $result['parsed']);
        $this->assertSame('STOP', $result['finish_reason']);
    }

    public function test_config_overrides_are_merged_into_generation_config(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content'      => ['parts' => [['text' => '{}']]],
                    'finishReason' => 'STOP',
                ]],
            ]),
        ]);

        $service = new GeminiService();
        $service->generate('Test prompt', [
            'maxOutputTokens' => 1024,
            'thinkingConfig'  => ['thinkingBudget' => 0],
        ]);

        Http::assertSent(function ($request) {
            $body = $request->data();
            $genConfig = $body['generationConfig'] ?? [];

            return $genConfig['maxOutputTokens'] === 1024
                && $genConfig['temperature'] === 0.3
                && $genConfig['thinkingConfig'] === ['thinkingBudget' => 0];
        });
    }

    public function test_custom_model_override(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content'      => ['parts' => [['text' => '{}']]],
                    'finishReason' => 'STOP',
                ]],
            ]),
        ]);

        $service = new GeminiService();
        $service->generate('Test prompt', [], 'gemini-2.5-pro');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'gemini-2.5-pro:generateContent');
        });
    }

    public function test_non_json_response_returns_null_parsed(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content'      => ['parts' => [['text' => 'This is plain text, not JSON']]],
                    'finishReason' => 'STOP',
                ]],
            ]),
        ]);

        $service = new GeminiService();
        $result = $service->generate('Test prompt');

        $this->assertNull($result['parsed']);
        $this->assertSame('This is plain text, not JSON', $result['content']);
    }
}
