<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ProductConditionGuard;
use Tests\TestCase;

/**
 * Fix 1 (2026-08-15 live-QA hole) — ProductConditionGuard::resolveEffectiveCondition()'s
 * precedence matrix. Pure unit tests, no DB needed.
 *
 * Motivating prod data: Whole Latte Love sells a refurbished line with "Refurbished"
 * literally in the product title. The naive `$condition ?? titleCondition($title)`
 * coalesce every ingestion coercion site used to run let an explicit payload
 * `condition: 'new'` skip the title-marker guard entirely.
 */
class ProductConditionGuardTest extends TestCase
{
    /** @test */
    public function a_negative_title_marker_wins_over_an_explicit_payload_new(): void
    {
        $result = ProductConditionGuard::resolveEffectiveCondition(
            'new',
            'Refurbished Delonghi ECAM22110SB Magnifica XS'
        );

        $this->assertSame('refurbished', $result, 'the title marker must win over an explicit "new"');
    }

    /** @test */
    public function payload_new_with_a_clean_title_stays_new(): void
    {
        $result = ProductConditionGuard::resolveEffectiveCondition(
            'new',
            'Delonghi ECAM22110SB Magnifica XS Espresso Machine'
        );

        $this->assertSame('new', $result);
    }

    /** @test */
    public function an_absent_payload_condition_falls_back_to_the_title_marker(): void
    {
        $result = ProductConditionGuard::resolveEffectiveCondition(
            null,
            'Gaggia Magenta Prestige (Renewed)'
        );

        $this->assertSame('renewed', $result);
    }

    /** @test */
    public function an_absent_payload_condition_with_a_clean_title_stays_null(): void
    {
        $result = ProductConditionGuard::resolveEffectiveCondition(
            null,
            'Gaggia Magenta Prestige Espresso Machine'
        );

        $this->assertNull($result);
    }

    /**
     * @test
     * @dataProvider negativeConditionProvider
     */
    public function an_explicit_negative_payload_condition_always_wins_regardless_of_title(string $condition): void
    {
        // Clean title — the explicit negative condition is the ONLY signal.
        $this->assertSame(
            $condition,
            ProductConditionGuard::resolveEffectiveCondition($condition, 'A Perfectly Clean Sounding Title')
        );

        // Title marker present too — still just the explicit condition (never
        // "double negative" mapped through the title's own marker).
        $this->assertSame(
            $condition,
            ProductConditionGuard::resolveEffectiveCondition($condition, 'Open Box Some Product Title')
        );
    }

    public static function negativeConditionProvider(): array
    {
        return [
            ['renewed'],
            ['refurbished'],
            ['open_box'],
            ['used'],
        ];
    }

    /** @test */
    public function unknown_keeps_its_stamp_only_behavior_and_is_never_overridden_by_a_title_marker(): void
    {
        $result = ProductConditionGuard::resolveEffectiveCondition(
            'unknown',
            'Refurbished Lelit Anna Espresso Machine'
        );

        $this->assertSame('unknown', $result, '"unknown" must never be overridden by a title marker');
    }
}
