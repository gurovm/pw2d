<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\AuditLandingPageFreshness;
use App\Models\LandingPage;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Spec 030 §B4 — nightly (or on-demand) audit of every "Best X" landing page's
 * freshness, plus (via --backfill-snapshots) the one-off migration path for
 * pages generated before Spec 030 shipped `est_price_snapshot`.
 *
 * No tenant argument -> every tenant (unlike pw2d:seo:pull, which defaults to
 * seo_enabled tenants only — freshness is an editorial concern, not an
 * SEO-reporting one, so every tenant with landing pages is in scope).
 *
 * Exit code: FAILURE iff at least one PUBLISHED page is stale. A stale DRAFT
 * page is normal mid-iteration state and never fails the run.
 */
class AuditLandingPagesCommand extends Command
{
    protected $signature = 'pw2d:landing-pages:audit
                            {tenant? : Tenant ID — if omitted, runs for every tenant}
                            {--backfill-snapshots : Stamp missing est_price_snapshot values on existing picks from current prices, instead of auditing}';

    protected $description = 'Audit every "Best X" landing page for staleness (Spec 030), or backfill missing price snapshots';

    public function handle(): int
    {
        $tenants = $this->resolveTenants($this->argument('tenant'));

        if ($tenants->isEmpty()) {
            $this->warn('No tenants to process.');
            return self::FAILURE;
        }

        return $this->option('backfill-snapshots')
            ? $this->runBackfill($tenants)
            : $this->runAudit($tenants);
    }

    /**
     * @param Collection<int, Tenant> $tenants
     */
    private function runAudit(Collection $tenants): int
    {
        $action            = new AuditLandingPageFreshness();
        $rows              = [];
        $anyPublishedStale = false;

        foreach ($tenants as $tenant) {
            tenancy()->initialize($tenant);

            try {
                $pages = LandingPage::where('tenant_id', $tenant->id)->get();

                foreach ($pages as $page) {
                    $reasons = $action->execute($page);
                    $isStale = !empty($reasons);

                    if ($isStale && $page->status === 'published') {
                        $anyPublishedStale = true;
                    }

                    $rows[] = [
                        // Sort key first (published+stale first), stripped before display.
                        $page->status === 'published' && $isStale ? 0 : 1,
                        $page->slug,
                        $tenant->getTenantKey(),
                        $page->status,
                        $isStale ? 'STALE' : 'FRESH',
                        $isStale ? implode(', ', $reasons) : '—',
                        $page->freshness_checked_at?->toDateTimeString() ?? '—',
                    ];
                }
            } finally {
                tenancy()->end();
            }
        }

        usort($rows, fn (array $a, array $b) => $a[0] <=> $b[0]);

        if (empty($rows)) {
            $this->line('No landing pages found.');
            return self::SUCCESS;
        }

        $this->table(
            ['Slug', 'Tenant', 'Status', 'Freshness', 'Reasons', 'Checked At'],
            array_map(fn (array $row) => array_slice($row, 1), $rows),
        );

        if ($anyPublishedStale) {
            $this->error('At least one PUBLISHED landing page is stale.');
        } else {
            $this->info('All published landing pages are fresh.');
        }

        return $anyPublishedStale ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Spec 030 §B1 backfill path: stamps `est_price_snapshot` on any pick that's
     * missing it, from the product's CURRENT estimated_price (accepting today as
     * the baseline). Idempotent — a pick that already has a snapshot is left
     * untouched, so re-running only ever fills in genuinely-missing values.
     *
     * @param Collection<int, Tenant> $tenants
     */
    private function runBackfill(Collection $tenants): int
    {
        $stamped = 0;

        foreach ($tenants as $tenant) {
            tenancy()->initialize($tenant);

            try {
                $pages = LandingPage::where('tenant_id', $tenant->id)->get();

                foreach ($pages as $page) {
                    $picks = collect($page->picks ?? []);

                    if ($picks->isEmpty()) {
                        continue;
                    }

                    // Perf H2 (2026-08-16 audit): 'offers.store' — estimated_price
                    // (read a few lines below) now derives from best_offer, which
                    // reads $offer->store for the commission/priority tiebreak.
                    // With no eager load at all here, every offer of every pick
                    // triggered its own Offer AND Store lazy-load (~160 queries for
                    // 77 picks, pre-fix).
                    $products = Product::whereIn('id', $picks->pluck('product_id')->filter())
                        ->with('offers.store')
                        ->get()
                        ->keyBy('id');

                    $changed = false;

                    $newPicks = $picks->map(function (array $pick) use ($products, &$changed, &$stamped) {
                        if (array_key_exists('est_price_snapshot', $pick) && $pick['est_price_snapshot'] !== null) {
                            return $pick;
                        }

                        $product  = $products->get($pick['product_id'] ?? null);
                        $snapshot = $product?->estimated_price;

                        $pick['est_price_snapshot'] = $snapshot;

                        if ($snapshot !== null) {
                            $changed = true;
                            $stamped++;
                        }

                        return $pick;
                    })->all();

                    if ($changed) {
                        $page->update(['picks' => $newPicks]);
                    }
                }
            } finally {
                tenancy()->end();
            }
        }

        $this->info("Backfilled est_price_snapshot on {$stamped} pick(s).");
        Log::info('pw2d:landing-pages:audit --backfill-snapshots complete', ['stamped' => $stamped]);

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Tenant>
     */
    private function resolveTenants(?string $tenantId): Collection
    {
        if ($tenantId !== null) {
            $tenant = Tenant::find($tenantId);

            if ($tenant === null) {
                $this->error("Tenant not found: {$tenantId}");
                return collect();
            }

            return collect([$tenant]);
        }

        return Tenant::all()->values();
    }
}
