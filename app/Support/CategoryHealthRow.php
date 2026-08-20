<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Spec 032 — one category's freshness/inventory snapshot, as computed by
 * {@see \App\Actions\AssessCategoryHealth}.
 *
 * `final readonly` (precedent: {@see \App\Actions\Seo\PullSeoMetricsResult}) and
 * carries its own thresholds as constants so the CLI (`pw2d:categories:health`)
 * and the Filament `CategoryResource` list can never drift apart on what
 * "stale" or "thin" means — both consume this single class.
 */
final readonly class CategoryHealthRow
{
    /** Tier-2 monthly sweep overdue when `oldest_check` is older than this. */
    public const int STALE_DAYS = 30;

    /** Tier-2 sweep due within the week when `oldest_check` is older than this. */
    public const int AGING_DAYS = 21;

    /** Tier-3 discovery threshold (Spec 031 §"Pool headroom"). */
    public const int THIN_BUYABLE = 45;

    /** Losing stock faster than pool size suggests. */
    public const float CHURN_PCT = 0.20;

    public function __construct(
        public int $categoryId,
        public string $categoryName,
        public string $categorySlug,
        /** Every `products` row for this category — what the UI shows today. */
        public int $totalRows,
        /** `is_ignored = 0 AND status IS NULL` — the AI-Bouncer-approved set. */
        public int $pool,
        /** `pool` with >=1 offer passing {@see ListingHealth::applyPurchasableOfferQuery()}. */
        public int $buyable,
        /** `pool` products with zero health-stamped offers — the import debt. */
        public int $neverChecked,
        public ?CarbonInterface $oldestCheck,
        public ?CarbonInterface $newestCheck,
    ) {}

    /**
     * `(pool - buyable) / pool`, guarded against division by zero for an empty
     * (`no_data`) category.
     */
    public function unbuyablePct(): float
    {
        if ($this->pool === 0) {
            return 0.0;
        }

        return ($this->pool - $this->buyable) / $this->pool;
    }

    /**
     * Verdict list, matching Spec 030's `stale_reasons` vocabulary — several can
     * apply at once. `import_debt` sorts FIRST: it is the only reason that makes
     * `SelectLandingPagePicks` actively unsafe to run.
     *
     * `stale` and `aging` are mutually exclusive (>30d wins over >21d) — both
     * describe the same "oldest check is overdue" signal at different urgency,
     * so firing both on one category would just duplicate the same badge.
     *
     * An empty pool short-circuits straight to `no_data`: `thin`/`churn` firing
     * on a category with nothing in it (buyable < 45 and 0/0 unbuyable "churn")
     * would be a second, redundant badge for the same underlying fact.
     *
     * @return list<string>
     */
    public function reasons(): array
    {
        $reasons = [];

        if ($this->neverChecked > 0) {
            $reasons[] = 'import_debt';
        }

        if ($this->pool === 0) {
            $reasons[] = 'no_data';

            return $reasons;
        }

        if ($this->oldestCheck !== null && $this->oldestCheck->diffInDays(now()) > self::STALE_DAYS) {
            $reasons[] = 'stale';
        } elseif ($this->oldestCheck !== null && $this->oldestCheck->diffInDays(now()) > self::AGING_DAYS) {
            $reasons[] = 'aging';
        }

        if ($this->buyable < self::THIN_BUYABLE) {
            $reasons[] = 'thin';
        }

        if ($this->unbuyablePct() >= self::CHURN_PCT) {
            $reasons[] = 'churn';
        }

        return $reasons;
    }

    public function isHealthy(): bool
    {
        return $this->reasons() === [];
    }
}
