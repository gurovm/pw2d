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

    // -------------------------------------------------------------------------
    // S5 — Thinking-parts parsing
    // -------------------------------------------------------------------------

    public function test_single_part_without_thought_flag_returns_its_text(): void
    {
        // Standard response: no thinking, just one output part.
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content'      => ['parts' => [
                        ['text' => '{"name": "Shure MV7", "brand": "Shure"}'],
                    ]],
                    'finishReason' => 'STOP',
                ]],
            ]),
        ]);

        $service = new GeminiService();
        $result = $service->generate('Test prompt');

        $this->assertSame('{"name": "Shure MV7", "brand": "Shure"}', $result['content']);
        $this->assertSame(['name' => 'Shure MV7', 'brand' => 'Shure'], $result['parsed']);
    }

    public function test_thought_part_followed_by_output_part_returns_output_text(): void
    {
        // Thinking enabled: parts[0] is the internal reasoning (thought: true),
        // parts[1] is the actual answer. Must return parts[1].
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content'      => ['parts' => [
                        ['thought' => true, 'text' => 'Let me reason about this product...'],
                        ['text' => '{"name": "Test Product", "brand": "TestBrand"}'],
                    ]],
                    'finishReason' => 'STOP',
                ]],
            ]),
        ]);

        $service = new GeminiService();
        $result = $service->generate('Test prompt');

        // The thinking part must be ignored; only the final output part is returned.
        $this->assertSame('{"name": "Test Product", "brand": "TestBrand"}', $result['content']);
        $this->assertSame(['name' => 'Test Product', 'brand' => 'TestBrand'], $result['parsed']);
        $this->assertStringNotContainsString('Let me reason', $result['content']);
    }

    public function test_multiple_thought_parts_returns_last_non_thought_part(): void
    {
        // Some models emit multiple thought chunks before the final output.
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content'      => ['parts' => [
                        ['thought' => true, 'text' => 'First reasoning chunk...'],
                        ['thought' => true, 'text' => 'Second reasoning chunk...'],
                        ['text' => '{"score": 85}'],
                    ]],
                    'finishReason' => 'STOP',
                ]],
            ]),
        ]);

        $service = new GeminiService();
        $result = $service->generate('Test prompt');

        $this->assertSame('{"score": 85}', $result['content']);
        $this->assertSame(['score' => 85], $result['parsed']);
    }

    public function test_empty_parts_array_returns_empty_content_and_null_parsed(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content'      => ['parts' => []],
                    'finishReason' => 'STOP',
                ]],
            ]),
        ]);

        $service = new GeminiService();
        $result = $service->generate('Test prompt');

        $this->assertSame('', $result['content']);
        $this->assertNull($result['parsed']);
    }

    // -------------------------------------------------------------------------
    // P4 — Gemini 429 retry behaviour
    // -------------------------------------------------------------------------

    public function test_429_then_200_succeeds_without_throwing(): void
    {
        // First call returns 429, second returns a valid 200 response.
        // With retry(3, ..., when: 429 only), the service should recover transparently.
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push('Rate limited', 429)
                ->push([
                    'candidates' => [[
                        'content'      => ['parts' => [['text' => '{"ok": true}']]],
                        'finishReason' => 'STOP',
                    ]],
                ], 200),
        ]);

        $service = new GeminiService();
        $result = $service->generate('Test prompt');

        $this->assertSame(['ok' => true], $result['parsed']);
        // Exactly 2 requests were sent: the failing one and the successful retry.
        Http::assertSentCount(2);
    }

    public function test_three_consecutive_429s_throw_rate_limit_exception(): void
    {
        // All retries exhausted — the service must throw the rate-limit message.
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push('Rate limited', 429)
                ->push('Rate limited', 429)
                ->push('Rate limited', 429),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Gemini rate limit hit. Please retry.');

        $service = new GeminiService();
        $service->generate('Test prompt');
    }

    public function test_500_does_not_retry_and_throws_immediately(): void
    {
        // 500 must NOT satisfy the retry `when` predicate (which is 429-only).
        // The sequence has a 200 as a safety net; it should never be reached.
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push('Internal Server Error', 500)
                ->push([
                    'candidates' => [[
                        'content'      => ['parts' => [['text' => '{"ok": true}']]],
                        'finishReason' => 'STOP',
                    ]],
                ], 200),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Gemini API error: 500');

        $service = new GeminiService();
        $service->generate('Test prompt');

        // Only one request should have been sent — no retry on 500.
        Http::assertSentCount(1);
    }
}
