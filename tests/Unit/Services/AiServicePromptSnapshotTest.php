<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AiService;
use App\Support\BouncerRules;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pins `AiService::evaluateProduct()`'s assembled Gemini prompt byte-for-byte.
 *
 * `tests/Fixtures/evaluate_product_prompt.snapshot.txt` was re-captured on
 * purpose for Spec 041 (rule D, category notes, `model` field, amended NAME
 * RULE; the session-only addendum is gone). Any further prompt edit must
 * re-capture it deliberately: run the test, read the diff, then overwrite the
 * fixture with the new prompt. Regenerating it blindly defeats the pin.
 */
class AiServicePromptSnapshotTest extends TestCase
{
    private const FEATURES = [
        'Feature A' => ['unit' => null, 'is_higher_better' => true],
        'Feature B' => ['unit' => 'dB', 'is_higher_better' => false],
    ];

    private function capturePrompt(?string $categoryNotes): string
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

        app(AiService::class)->evaluateProduct(
            'Test Product Name',
            99.99,
            'Mid-range ($50-$150)',
            '4.5/5 stars (100 reviews)',
            'Test Category',
            self::FEATURES,
            categoryNotes: $categoryNotes,
        );

        $this->assertNotNull($captured);

        return $captured;
    }

    /** @test */
    public function evaluate_product_prompt_matches_the_snapshot(): void
    {
        $this->assertSame(
            file_get_contents(base_path('tests/Fixtures/evaluate_product_prompt.snapshot.txt')),
            $this->capturePrompt(null),
            'The Gemini prompt changed. If intended, re-capture tests/Fixtures/evaluate_product_prompt.snapshot.txt.'
        );
    }

    /** @test */
    public function category_notes_appear_once_directly_after_rule_d(): void
    {
        $notes = 'Exclude mice, numpads and keyboard-and-mouse combos.';
        $prompt = $this->capturePrompt($notes);

        $this->assertSame(1, substr_count($prompt, $notes));
        $this->assertStringContainsString(
            '"reason": "wrong_category"}' . "\n\n" . 'Category-specific rules: ' . $notes . "\n\n=== STAGE 2",
            $prompt
        );
    }

    /** @test */
    public function no_notes_line_is_emitted_when_notes_are_null_or_blank(): void
    {
        $this->assertStringNotContainsString('Category-specific rules', $this->capturePrompt(null));
        $this->assertStringNotContainsString('Category-specific rules', $this->capturePrompt("  \n "));
        $this->assertSame(BouncerRules::text('X'), BouncerRules::text('X', '   '));
    }

    /** @test */
    public function rule_d_and_the_model_field_are_in_the_gemini_prompt_and_the_session_addendum_is_gone(): void
    {
        $prompt = $this->capturePrompt(null);

        $this->assertStringContainsString('IGNORE RULE D', $prompt);
        $this->assertStringContainsString('"reason": "wrong_category"', $prompt);
        $this->assertStringContainsString('"model": "Model Without Brand"', $prompt);
        $this->assertStringNotContainsString('SESSION-ONLY', $prompt);
        $this->assertFalse(method_exists(BouncerRules::class, 'sessionAddendum'));
    }
}
