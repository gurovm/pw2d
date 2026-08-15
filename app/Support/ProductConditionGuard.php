<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Single shared definition of "listing condition" markers (renewed / refurbished /
 * open box / pre-owned / used) — Spec 027 Addendum A §2.
 *
 * Two marker sets, because the same substring check over-matches in different fields:
 * - {@see TITLE_MARKERS} runs against raw scraped/incoming listing titles, where "used"
 *   reliably signals a used/renewed listing condition.
 * - {@see SUMMARY_MARKERS} runs against AI-generated prose (`ai_summary`), where a bare
 *   "used" is a common plain-English verb ("designed to be used with...") and would
 *   over-match; only the unambiguous condition phrases are checked there.
 *
 * Every caller that needs to detect a condition-marked product (pick selection, the
 * three ingestion paths, the audit command) MUST go through this class rather than
 * re-implementing the marker list.
 */
class ProductConditionGuard
{
    private const TITLE_MARKERS = [
        'renewed',
        'refurbish',
        'open box',
        'open-box',
        'pre-owned',
        'used',
    ];

    private const SUMMARY_MARKERS = [
        'renewed',
        'refurbish',
        'open box',
        'open-box',
        'pre-owned',
    ];

    /**
     * 029B-B4: maps every raw marker string this guard can emit to the canonical
     * {@see ListingHealth} condition vocabulary. Raw markers ('refurbish', 'open box',
     * 'pre-owned', …) are substring-match tokens, NOT valid `product_offers.condition`
     * values — feeding them to ListingHealthService::apply() verbatim would miss the
     * NEGATIVE_CONDITIONS check and store out-of-vocabulary junk via the clean branch.
     * Canonical spellings are included so the map stays total if the marker lists grow.
     */
    private const MARKER_CONDITIONS = [
        'renewed'     => 'renewed',
        'refurbish'   => 'refurbished',
        'refurbished' => 'refurbished',
        'open box'    => 'open_box',
        'open-box'    => 'open_box',
        'open_box'    => 'open_box',
        'pre-owned'   => 'used',
        'used'        => 'used',
    ];

    /**
     * Returns the first matched marker in a raw listing title, or null if clean.
     */
    public static function titleMarker(?string $title): ?string
    {
        return self::firstMatch($title, self::TITLE_MARKERS);
    }

    /**
     * Returns the first matched marker in AI-generated prose (e.g. `ai_summary`), or
     * null if clean. Deliberately excludes the plain word "used" — see class docblock.
     */
    public static function summaryMarker(?string $summary): ?string
    {
        return self::firstMatch($summary, self::SUMMARY_MARKERS);
    }

    /**
     * 029B-B4: like {@see titleMarker()}, but returns the CANONICAL
     * {@see ListingHealth} condition ('renewed' | 'refurbished' | 'open_box' | 'used')
     * instead of the raw matched marker string, or null if the title is clean.
     *
     * This is the method every ingestion path MUST use when coercing a title marker
     * into a `condition` for {@see \App\Services\ListingHealthService::apply()} —
     * a marker always means "not a new-condition listing", so an unmapped (future)
     * marker deliberately falls back to 'used' rather than ever leaking a raw marker
     * string into the stored condition vocabulary.
     */
    public static function titleCondition(?string $title): ?string
    {
        $marker = self::titleMarker($title);

        if ($marker === null) {
            return null;
        }

        return self::MARKER_CONDITIONS[$marker] ?? 'used';
    }

    /**
     * Fix 1 (2026-08-15 live-QA hole): the single shared precedence rule every
     * ingestion coercion site (OfferIngestionService, BatchImportController,
     * ProductImportController) MUST use instead of re-implementing
     * `$condition ?? ProductConditionGuard::titleCondition($title)` inline.
     *
     * That naive coalesce let an explicit payload `condition: 'new'` skip the
     * title-marker guard entirely — a "Refurbished Lelit Anna…" listing sent with
     * `condition: 'new'` (e.g. Whole Latte Love's refurbished line, which puts
     * "Refurbished" in the title itself) would be stored as `new`, clearing any
     * existing flag and stamping itself verified-clean. Worse than sending nothing.
     *
     * Precedence (strongest signal wins):
     * 1. `$condition` already in {@see ListingHealth::NEGATIVE_CONDITIONS} — an
     *    explicit DOM-verified negative condition always wins; the title marker
     *    is redundant at that point.
     * 2. `$condition` absent (null) — today's behavior: fall back to the title
     *    marker (or null if the title is clean).
     * 3. `$condition === 'new'` AND the title carries a negative marker — the
     *    marker WINS. A weaker "looks new" claim never overrides a stronger
     *    textual signal that the listing is not, in fact, new.
     * 4. Anything else (`'unknown'`, or `'new'` with a clean title) — the payload
     *    value is returned unchanged. `'unknown'` in particular keeps its
     *    current stamp-only behavior in {@see \App\Services\ListingHealthService}
     *    — the title is never consulted for it.
     */
    public static function resolveEffectiveCondition(?string $condition, ?string $title): ?string
    {
        $titleCondition = self::titleCondition($title);

        if ($condition === null) {
            return $titleCondition;
        }

        if ($condition === 'new' && $titleCondition !== null) {
            return $titleCondition;
        }

        return $condition;
    }

    public static function matchesTitle(?string $title): bool
    {
        return self::titleMarker($title) !== null;
    }

    public static function matchesSummary(?string $summary): bool
    {
        return self::summaryMarker($summary) !== null;
    }

    /**
     * @param list<string> $markers
     */
    private static function firstMatch(?string $haystack, array $markers): ?string
    {
        if (blank($haystack)) {
            return null;
        }

        $lower = mb_strtolower($haystack);

        foreach ($markers as $marker) {
            if (str_contains($lower, $marker)) {
                return $marker;
            }
        }

        return null;
    }
}
