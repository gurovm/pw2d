<?php

declare(strict_types=1);

namespace App\Actions\Seo;

use Carbon\CarbonImmutable;

/**
 * Aggregated result from a full PullSeoMetrics run for one tenant + date window.
 *
 * Errors from child actions (GSC / GA4) are merged here. A non-empty $errors
 * array means at least one source failed; the overall run may still be partially
 * successful (gscRowsUpserted > 0 && ga4 failed, or vice versa).
 *
 * Since Spec 016, a single execute() call may cover multiple dates (a rolling
 * window for GSC, a single day for GA4). The $date property now holds the
 * latest date in the combined window for backward-compat callers that read it.
 * Per-date breakdown is available in $gscDailyCounts and $ga4DailyCounts.
 */
final readonly class PullSeoMetricsResult
{
    /**
     * @param string                $tenantId         The tenant's string ID.
     * @param CarbonImmutable       $date             Latest date in the pull window (max of gsc/ga4 dates).
     * @param int                   $gscRowsUpserted  Total rows from GSC — 0 if GSC failed or had no data.
     * @param int                   $ga4RowsUpserted  Total rows from GA4 — 0 if GA4 failed or had no data.
     * @param string[]              $errors           Aggregated errors from both child actions.
     * @param array<string, int>    $gscDailyCounts   metric_date string → rows upserted for that GSC date.
     * @param array<string, int>    $ga4DailyCounts   metric_date string → rows upserted for that GA4 date.
     */
    public function __construct(
        public string $tenantId,
        public CarbonImmutable $date,
        public int $gscRowsUpserted,
        public int $ga4RowsUpserted,
        public array $errors,
        public array $gscDailyCounts = [],
        public array $ga4DailyCounts = [],
    ) {}

    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    public function totalUpserted(): int
    {
        return $this->gscRowsUpserted + $this->ga4RowsUpserted;
    }
}
