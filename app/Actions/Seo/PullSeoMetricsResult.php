<?php

declare(strict_types=1);

namespace App\Actions\Seo;

use Carbon\CarbonImmutable;

/**
 * Aggregated result from a full PullSeoMetrics run for one tenant + date.
 *
 * Errors from child actions (GSC / GA4) are merged here. A non-empty $errors
 * array means at least one source failed; the overall run may still be partially
 * successful (gscRowsUpserted > 0 && ga4 failed, or vice versa).
 */
final readonly class PullSeoMetricsResult
{
    /**
     * @param string          $tenantId         The tenant's string ID.
     * @param CarbonImmutable $date             The calendar day that was pulled.
     * @param int             $gscRowsUpserted  Rows from GSC — 0 if GSC failed or had no data.
     * @param int             $ga4RowsUpserted  Rows from GA4 — 0 if GA4 failed or had no data.
     * @param string[]        $errors           Aggregated errors from both child actions.
     */
    public function __construct(
        public string $tenantId,
        public CarbonImmutable $date,
        public int $gscRowsUpserted,
        public int $ga4RowsUpserted,
        public array $errors,
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
