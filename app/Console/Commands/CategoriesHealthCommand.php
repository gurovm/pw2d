<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\AssessCategoryHealth;
use App\Models\LandingPage;
use App\Models\Tenant;
use App\Support\CategoryHealthRow;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Spec 032 — `pw2d:categories:health {tenant?} {--due} {--json}`.
 *
 * Mirrors {@see AuditLandingPagesCommand}: no `tenant` argument runs every
 * tenant, `tenancy()->initialize()` in a `try/finally`. Table sorted by
 * `oldest_check` ASC (via {@see AssessCategoryHealth::execute()}), so row one
 * is literally the next category to sweep (Spec 031 drives Tier-2 rotation by
 * oldest health check, not a fixed calendar).
 *
 * Exit code: FAILURE iff a category backing a PUBLISHED landing page carries
 * `import_debt` or `stale` — cron-safe alongside the nightly
 * `pw2d:landing-pages:audit`. A draft page's category never fails the run.
 */
class CategoriesHealthCommand extends Command
{
    protected $signature = 'pw2d:categories:health
                            {tenant? : Tenant ID — if omitted, runs for every tenant}
                            {--due : Only show categories that are not HEALTHY}
                            {--json : Emit JSON instead of a table}';

    protected $description = 'Spec 032 — report per-category pool/buyable/freshness health';

    public function handle(): int
    {
        $tenants = $this->resolveTenants($this->argument('tenant'));

        if ($tenants->isEmpty()) {
            $this->warn('No tenants to process.');
            return self::FAILURE;
        }

        $action        = new AssessCategoryHealth();
        $rows          = [];
        $anyBlocking   = false;
        $onlyDue       = (bool) $this->option('due');

        foreach ($tenants as $tenant) {
            tenancy()->initialize($tenant);

            try {
                $publishedCategoryIds = LandingPage::where('tenant_id', $tenant->id)
                    ->where('status', 'published')
                    ->pluck('category_id')
                    ->all();

                /** @var Collection<int, CategoryHealthRow> $healthRows */
                $healthRows = $action->execute($tenant);

                foreach ($healthRows as $row) {
                    $reasons = $row->reasons();

                    if (in_array($row->categoryId, $publishedCategoryIds, true)
                        && (in_array('import_debt', $reasons, true) || in_array('stale', $reasons, true))
                    ) {
                        $anyBlocking = true;
                    }

                    if ($onlyDue && $row->isHealthy()) {
                        continue;
                    }

                    $rows[] = [
                        'tenant_id'     => $tenant->getTenantKey(),
                        'category_id'   => $row->categoryId,
                        'category_slug' => $row->categorySlug,
                        'category_name' => $row->categoryName,
                        'total_rows'    => $row->totalRows,
                        'pool'          => $row->pool,
                        'buyable'       => $row->buyable,
                        'never_checked' => $row->neverChecked,
                        'oldest_check'  => $row->oldestCheck?->toDateTimeString(),
                        'newest_check'  => $row->newestCheck?->toDateTimeString(),
                        'unbuyable_pct' => round($row->unbuyablePct() * 100, 1),
                        'reasons'       => $reasons,
                        'verdict'       => $reasons === [] ? 'HEALTHY' : implode(', ', $reasons),
                    ];
                }
            } finally {
                tenancy()->end();
            }
        }

        // --json must stay a clean, single JSON payload (Spec 032: "so the
        // extension or a future dashboard can consume it without scraping
        // table output") — the human-readable summary line is skipped, not
        // appended after it.
        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $anyBlocking ? self::FAILURE : self::SUCCESS;
        }

        $this->renderTable($rows);

        if ($anyBlocking) {
            $this->error('At least one PUBLISHED landing page category carries import_debt or stale.');
        } else {
            $this->info('No published landing page category is blocked.');
        }

        return $anyBlocking ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function renderTable(array $rows): void
    {
        if (empty($rows)) {
            $this->line('No categories found.');
            return;
        }

        $this->table(
            ['Tenant', 'Category', 'Rows', 'Pool', 'Buyable', 'Unchecked', 'Unbuyable %', 'Oldest Check', 'Verdict'],
            array_map(fn (array $row) => [
                $row['tenant_id'],
                $row['category_slug'],
                $row['total_rows'],
                $row['pool'],
                $row['buyable'],
                $row['never_checked'],
                $row['unbuyable_pct'] . '%',
                $row['oldest_check'] ?? '—',
                $row['verdict'],
            ], $rows),
        );
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
