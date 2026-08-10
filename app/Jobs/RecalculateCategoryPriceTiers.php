<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Category;
use App\Models\Tenant;
use App\Services\PriceTierRecalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Spec 029 §A4 — dispatched (at most once per ingestion request; see call sites in
 * OfferIngestionService/BatchImportController/ProductImportController) after a price
 * changes on an already-existing offer, so stale `$$$` price-tier badges self-correct
 * without waiting for a manual `products:recalculate-tiers` run. Delegates to
 * {@see PriceTierRecalculator}, which chunks (Q13) rather than loading a whole
 * category's products+offers into memory.
 *
 * Explicitly (re-)initializes tenancy from the tenant ID captured at dispatch time —
 * this project registers no queue tenancy bootstrapper (config/tenancy.php), so a
 * database-queue worker process has no ambient tenant context. Guarded against the
 * "nested tenancy()->initialize()" gotcha: if tenancy is already active (e.g. the sync
 * queue driver used in tests, running inline inside an already-tenant-scoped request),
 * this job reuses it instead of re-initializing/ending it out from under the caller.
 *
 * Perf H1 (2026-08-10): `ShouldBeUnique` keyed on tenant+category — a rescan walk
 * that refreshes many offers in the same category would otherwise queue one job per
 * offer (~1,070 during Phase C), each re-chunking the whole category. Callers also
 * now gate the dispatch on the refreshed offer's `scraped_price` actually changing
 * (see OfferIngestionService/ProductImportController), so an unchanged price never
 * even reaches here.
 */
class RecalculateCategoryPriceTiers implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Perf H1 — debounce window for duplicate recalcs of the same tenant+category. */
    public int $uniqueFor = 600;

    public function __construct(
        private readonly ?string $tenantId,
        private readonly int $categoryId,
    ) {}

    public function uniqueId(): string
    {
        return ($this->tenantId ?? 'none') . ':' . $this->categoryId;
    }

    public function handle(PriceTierRecalculator $recalculator): void
    {
        $alreadyInitialized = tenancy()->initialized;
        $shouldInitialize   = !$alreadyInitialized && $this->tenantId !== null;

        if ($shouldInitialize) {
            $tenant = Tenant::find($this->tenantId);

            if (!$tenant) {
                Log::warning('RecalculateCategoryPriceTiers: tenant not found', ['tenant_id' => $this->tenantId]);
                return;
            }

            tenancy()->initialize($tenant);
        }

        try {
            $category = Category::find($this->categoryId);

            if (!$category) {
                return;
            }

            $result = $recalculator->recalculateForCategory($category);

            Log::info('RecalculateCategoryPriceTiers: done', [
                'category_id' => $this->categoryId,
                'fixed'       => $result['fixed'],
                'skipped'     => $result['skipped'],
            ]);
        } finally {
            if ($shouldInitialize && tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }
}
