<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Exceptions\InvalidProductEvaluation;
use App\Support\ProductEvaluation;
use Tests\TestCase;

/**
 * Spec 039 T1 — ProductEvaluation::fromArray() validation. One test per rule
 * in spec §4's first bullet, plus the accessor contract (null feature entries
 * dropped) and the `status` compatibility note documented on the class
 * itself (Gemini's real "scored" payload never includes a `status` key).
 */
class ProductEvaluationTest extends TestCase
{
    private function validScoredPayload(array $overrides = []): array
    {
        return array_merge([
            'name'       => 'Sony WH-1000XM5',
            'brand'      => 'Sony',
            'ai_summary' => 'Solid, if unremarkable.',
            'price_tier' => 2,
            'features'   => ['Comfort' => ['score' => 80, 'reason' => 'Plush earcups.']],
        ], $overrides);
    }

    private function validIgnoredPayload(string $reason): array
    {
        return ['status' => 'ignored', 'reason' => $reason];
    }

    // -------------------------------------------------------------------
    // Valid rows
    // -------------------------------------------------------------------

    /** @test */
    public function a_valid_scored_row_builds_successfully(): void
    {
        $eval = ProductEvaluation::fromArray($this->validScoredPayload());

        $this->assertFalse($eval->isIgnored());
        $this->assertNull($eval->reason());
        $this->assertSame('Sony WH-1000XM5', $eval->name());
        $this->assertSame('Sony', $eval->brand());
        $this->assertSame('Solid, if unremarkable.', $eval->aiSummary());
        $this->assertSame(2, $eval->priceTier());
        $this->assertSame(
            ['Comfort' => ['score' => 80.0, 'reason' => 'Plush earcups.']],
            $eval->features()
        );
    }

    /** @test */
    public function a_scored_row_without_status_key_is_still_valid_matching_the_real_gemini_payload_shape(): void
    {
        // AiService::evaluateProduct()'s prompt never asks for a "status" key
        // on a scored response — only the "ignored" shape has one.
        $payload = $this->validScoredPayload();
        $this->assertArrayNotHasKey('status', $payload);

        $eval = ProductEvaluation::fromArray($payload);

        $this->assertFalse($eval->isIgnored());
    }

    /**
     * @test
     * @dataProvider ignoredReasonProvider
     */
    public function a_valid_ignored_row_is_accepted_for_every_reason(string $reason): void
    {
        $eval = ProductEvaluation::fromArray($this->validIgnoredPayload($reason));

        $this->assertTrue($eval->isIgnored());
        $this->assertSame($reason, $eval->reason());
        $this->assertNull($eval->name());
        $this->assertNull($eval->brand());
    }

    public static function ignoredReasonProvider(): array
    {
        return [
            'accessory_or_bundle'    => ['accessory_or_bundle'],
            'generic_white_label'    => ['generic_white_label'],
            'renewed_or_refurbished' => ['renewed_or_refurbished'],
            'wrong_category'         => ['wrong_category'],
        ];
    }

    // -------------------------------------------------------------------
    // Invalid rows — one test per rule (spec §4 T1)
    // -------------------------------------------------------------------

    /** @test */
    public function missing_name_when_scored_is_invalid(): void
    {
        $this->expectException(InvalidProductEvaluation::class);
        $this->expectExceptionMessageMatches('/"name"/');

        ProductEvaluation::fromArray($this->validScoredPayload(['name' => '']));
    }

    /** @test */
    public function missing_brand_when_scored_is_invalid(): void
    {
        $payload = $this->validScoredPayload();
        unset($payload['brand']);

        $this->expectException(InvalidProductEvaluation::class);
        $this->expectExceptionMessageMatches('/"brand"/');

        ProductEvaluation::fromArray($payload);
    }

    /** @test */
    public function missing_ai_summary_when_scored_is_invalid(): void
    {
        $payload = $this->validScoredPayload();
        unset($payload['ai_summary']);

        $this->expectException(InvalidProductEvaluation::class);
        $this->expectExceptionMessageMatches('/"ai_summary"/');

        ProductEvaluation::fromArray($payload);
    }

    /** @test */
    public function reason_present_when_scored_is_invalid(): void
    {
        $payload = $this->validScoredPayload(['reason' => 'accessory_or_bundle']);

        $this->expectException(InvalidProductEvaluation::class);
        $this->expectExceptionMessageMatches('/"reason"/');

        ProductEvaluation::fromArray($payload);
    }

    /**
     * Spec 039 review M2 — the four-value enum is enforced only at T4 apply
     * time ({@see \App\Actions\ApplyProductEvaluations}), not here: Gemini's
     * prompt says "return EXACTLY" but LLMs drift ("accessory", "bundle",
     * "accessory_or_bundle: replacement cable"). Rejecting these here would
     * discard a correct Bouncer verdict and cost three more paid attempts for
     * nothing — the reason string is used for logging only on this path.
     */
    /** @test */
    public function an_off_list_reason_when_ignored_is_accepted_as_is(): void
    {
        $eval = ProductEvaluation::fromArray($this->validIgnoredPayload('not_a_real_reason'));

        $this->assertTrue($eval->isIgnored());
        $this->assertSame('not_a_real_reason', $eval->reason());
    }

    /**
     * Spec 039 review M2 — matches the old job's `$parsed['reason'] ?? ''`:
     * a missing reason on an ignored payload defaults to an empty string
     * rather than throwing.
     */
    /** @test */
    public function a_missing_reason_when_ignored_defaults_to_an_empty_string(): void
    {
        $eval = ProductEvaluation::fromArray(['status' => 'ignored']);

        $this->assertTrue($eval->isIgnored());
        $this->assertSame('', $eval->reason());
    }

    /** @test */
    public function an_ignored_reason_is_trimmed(): void
    {
        $eval = ProductEvaluation::fromArray(['status' => 'ignored', 'reason' => '  accessory_or_bundle  ']);

        $this->assertSame('accessory_or_bundle', $eval->reason());
    }

    /**
     * Spec 039 review M3 — the value object accepts a 0 score; the legacy
     * `score > 0` guard in
     * {@see \App\Actions\FinalizeProductEvaluation::applyFeatureScores()} is
     * what actually skips writing it (pinned by
     * FinalizeProductEvaluationTest::apply_feature_scores_skips_a_null_entry_and_a_zero_score_identically_and_writes_the_rest).
     */
    /** @test */
    public function a_feature_score_of_zero_is_valid_at_the_value_object_level(): void
    {
        $eval = ProductEvaluation::fromArray($this->validScoredPayload([
            'features' => ['Comfort' => ['score' => 0, 'reason' => 'Flat.']],
        ]));

        $this->assertSame(
            ['Comfort' => ['score' => 0.0, 'reason' => 'Flat.']],
            $eval->features()
        );
    }

    /** @test */
    public function a_feature_score_below_zero_is_invalid(): void
    {
        $this->expectException(InvalidProductEvaluation::class);
        $this->expectExceptionMessageMatches('/features\.Comfort\.score/');

        ProductEvaluation::fromArray($this->validScoredPayload([
            'features' => ['Comfort' => ['score' => -1, 'reason' => 'Broken.']],
        ]));
    }

    /** @test */
    public function a_feature_score_of_101_is_invalid(): void
    {
        $this->expectException(InvalidProductEvaluation::class);
        $this->expectExceptionMessageMatches('/features\.Comfort\.score/');

        ProductEvaluation::fromArray($this->validScoredPayload([
            'features' => ['Comfort' => ['score' => 101, 'reason' => 'Too good.']],
        ]));
    }

    /** @test */
    public function a_non_numeric_feature_score_is_invalid(): void
    {
        $this->expectException(InvalidProductEvaluation::class);
        $this->expectExceptionMessageMatches('/features\.Comfort\.score/');

        ProductEvaluation::fromArray($this->validScoredPayload([
            'features' => ['Comfort' => ['score' => 'high', 'reason' => 'Plush.']],
        ]));
    }

    /** @test */
    public function features_that_is_not_a_map_is_invalid(): void
    {
        $this->expectException(InvalidProductEvaluation::class);
        $this->expectExceptionMessageMatches('/"features"/');

        ProductEvaluation::fromArray($this->validScoredPayload(['features' => 'not-a-map']));
    }

    /** @test */
    public function a_missing_features_key_when_scored_is_invalid(): void
    {
        $payload = $this->validScoredPayload();
        unset($payload['features']);

        $this->expectException(InvalidProductEvaluation::class);
        $this->expectExceptionMessageMatches('/"features"/');

        ProductEvaluation::fromArray($payload);
    }

    // -------------------------------------------------------------------
    // Accessor contract
    // -------------------------------------------------------------------

    /** @test */
    public function a_null_feature_entry_is_dropped_from_features(): void
    {
        $eval = ProductEvaluation::fromArray($this->validScoredPayload([
            'features' => [
                'Comfort'    => ['score' => 80, 'reason' => 'Plush earcups.'],
                'Durability' => null,
            ],
        ]));

        $this->assertSame(['Comfort'], array_keys($eval->features()));
    }

    /** @test */
    public function an_empty_features_map_is_valid_and_yields_no_entries(): void
    {
        $eval = ProductEvaluation::fromArray($this->validScoredPayload(['features' => []]));

        $this->assertSame([], $eval->features());
    }

    /** @test */
    public function optional_amazon_fields_default_to_null_when_absent(): void
    {
        $payload = $this->validScoredPayload();
        unset($payload['price_tier']);

        $eval = ProductEvaluation::fromArray($payload);

        $this->assertNull($eval->priceTier());
        $this->assertNull($eval->amazonRating());
        $this->assertNull($eval->amazonReviewsCount());
    }

    // -------------------------------------------------------------------
    // HIGH-1 — non-array payload (Gemini's `parsed` is `?array`)
    // -------------------------------------------------------------------

    /**
     * @test
     * @dataProvider nonArrayPayloadProvider
     */
    public function a_non_array_payload_throws_invalid_product_evaluation_not_a_type_error(mixed $raw): void
    {
        $this->expectException(InvalidProductEvaluation::class);
        $this->expectExceptionMessageMatches('/"payload"/');

        ProductEvaluation::fromArray($raw);
    }

    public static function nonArrayPayloadProvider(): array
    {
        return [
            'null (prose Gemini reply, json_decode() result)' => [null],
            'a bare string'                                   => ['not json'],
            'a bare number'                                   => [42],
            'a bare bool'                                      => [true],
        ];
    }

    // -------------------------------------------------------------------
    // M3 — free text truncated, not rejected
    // -------------------------------------------------------------------

    /** @test */
    public function an_ai_summary_over_600_chars_is_truncated_not_rejected(): void
    {
        $eval = ProductEvaluation::fromArray($this->validScoredPayload([
            'ai_summary' => str_repeat('a', 700),
        ]));

        $this->assertSame(600, mb_strlen($eval->aiSummary()));
        $this->assertSame(str_repeat('a', 600), $eval->aiSummary());
    }

    /** @test */
    public function a_feature_reason_over_300_chars_is_truncated_not_rejected(): void
    {
        $eval = ProductEvaluation::fromArray($this->validScoredPayload([
            'features' => ['Comfort' => ['score' => 80, 'reason' => str_repeat('b', 400)]],
        ]));

        $reason = $eval->features()['Comfort']['reason'];
        $this->assertSame(300, mb_strlen($reason));
        $this->assertSame(str_repeat('b', 300), $reason);
    }

    /** @test */
    public function a_missing_feature_reason_is_accepted_as_null(): void
    {
        $eval = ProductEvaluation::fromArray($this->validScoredPayload([
            'features' => ['Comfort' => ['score' => 80]],
        ]));

        $this->assertSame(['Comfort' => ['score' => 80.0, 'reason' => null]], $eval->features());
    }

    /**
     * Old loop shape: `is_array($value) ? … : (float) $value` — a bare
     * numeric feature entry (no `{score, reason}` wrapper) is valid with
     * `reason = null`.
     */
    /** @test */
    public function a_bare_numeric_feature_entry_is_valid_with_a_null_reason(): void
    {
        $eval = ProductEvaluation::fromArray($this->validScoredPayload([
            'features' => ['Comfort' => 72],
        ]));

        $this->assertSame(['Comfort' => ['score' => 72.0, 'reason' => null]], $eval->features());
    }

    /** @test */
    public function a_numeric_string_feature_score_is_coerced(): void
    {
        $eval = ProductEvaluation::fromArray($this->validScoredPayload([
            'features' => ['Comfort' => ['score' => '72', 'reason' => 'Fine.']],
        ]));

        $this->assertSame(72.0, $eval->features()['Comfort']['score']);
    }

    // -------------------------------------------------------------------
    // LOW-5 — price_tier coerced, never throws
    // -------------------------------------------------------------------

    /**
     * @test
     * @dataProvider invalidPriceTierProvider
     */
    public function an_out_of_range_price_tier_is_coerced_to_null_not_rejected(mixed $rawPriceTier): void
    {
        $eval = ProductEvaluation::fromArray($this->validScoredPayload(['price_tier' => $rawPriceTier]));

        $this->assertNull($eval->priceTier());
    }

    public static function invalidPriceTierProvider(): array
    {
        return [
            '4 (out of range)'      => [4],
            '0 (out of range)'      => [0],
            'non-numeric string'    => ['premium'],
            'non-integral (2.5)'    => [2.5],
        ];
    }

    /** @test */
    public function a_numeric_string_price_tier_is_coerced_to_int(): void
    {
        $eval = ProductEvaluation::fromArray($this->validScoredPayload(['price_tier' => '2.0']));

        $this->assertSame(2, $eval->priceTier());
    }

    /** @test */
    public function a_float_price_tier_with_an_integral_value_is_coerced_to_int(): void
    {
        $eval = ProductEvaluation::fromArray($this->validScoredPayload(['price_tier' => 2.0]));

        $this->assertSame(2, $eval->priceTier());
    }
}
