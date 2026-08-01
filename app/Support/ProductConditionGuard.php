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
