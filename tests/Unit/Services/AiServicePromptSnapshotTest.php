<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Spec 039 T3 — pins `AiService::evaluateProduct()`'s assembled Gemini prompt
 * byte-for-byte across the `BouncerRules` extraction.
 *
 * `tests/Fixtures/evaluate_product_prompt.snapshot.txt` was captured from the
 * PRE-refactor `evaluateProduct()` (the inline Stage 1/2/2.5/3 text, before
 * any of it moved into `App\Support\BouncerRules::text()`) for the exact
 * fixed inputs used below. If this test ever needs its fixture regenerated,
 * that regeneration must happen against a version of the code that has NOT
 * yet been refactored — regenerating it against the current code would
 * defeat the entire point of the pin.
 */
class AiServicePromptSnapshotTest extends TestCase
{
    /** @test */
    public function evaluate_product_prompt_is_byte_identical_to_the_pre_refactor_snapshot(): void
    {
        $captured = null;

        Http::fake(function ($request) use (&$captured) {
            $captured = $request->data()['contents'][0]['parts'][0]['text'];

            return Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode([
                        'name' => 'x', 'brand' => 'y', 'ai_summary' => 'z', 'features' => [],
                    ])]]],
                    'finishReason' => 'STOP',
                ]],
            ]);
        });

        $service = app(AiService::class);
        $service->evaluateProduct(
            'Test Product Name',
            99.99,
            'Mid-range ($50-$150)',
            '4.5/5 stars (100 reviews)',
            'Test Category',
            [
                'Feature A' => ['unit' => null, 'is_higher_better' => true],
                'Feature B' => ['unit' => 'dB', 'is_higher_better' => false],
            ]
        );

        $this->assertNotNull($captured);

        $expected = file_get_contents(base_path('tests/Fixtures/evaluate_product_prompt.snapshot.txt'));

        $this->assertSame(
            $expected,
            $captured,
            'The BouncerRules extraction must not change a single byte of the Gemini prompt.'
        );
    }

    /** @test */
    public function bouncer_rules_session_addendum_never_reaches_the_gemini_prompt(): void
    {
        $captured = null;

        Http::fake(function ($request) use (&$captured) {
            $captured = $request->data()['contents'][0]['parts'][0]['text'];

            return Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode([
                        'name' => 'x', 'brand' => 'y', 'ai_summary' => 'z', 'features' => [],
                    ])]]],
                    'finishReason' => 'STOP',
                ]],
            ]);
        });

        $service = app(AiService::class);
        $service->evaluateProduct('Test Product Name', 99.99, 'Mid-range ($50-$150)', '4.5/5 stars (100 reviews)', 'Test Category', []);

        $this->assertStringNotContainsString('SESSION-ONLY', $captured);
        $this->assertStringNotContainsString('wrong_category', $captured);
    }
}
