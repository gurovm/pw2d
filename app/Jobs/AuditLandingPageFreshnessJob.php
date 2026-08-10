<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\AuditLandingPageFreshness;
use App\Models\LandingPage;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Spec 030 §B3 — instant-path freshness re-check for a single landing page,
 * dispatched (never run inline) whenever an event that can invalidate a pick
 * happens: a Product is ignored/detached/deleted ({@see \App\Observers\ProductObserver}),
 * or an offer gets flagged `high_price` ({@see \App\Services\ListingHealthService}).
 *
 * Explicitly (re-)initializes tenancy from the tenant ID captured at dispatch
 * time — mirrors {@see RecalculateCategoryPriceTiers}: this project registers no
 * queue tenancy bootstrapper, so a database-queue worker has no ambient tenant
 * context. Guarded against nested tenancy()->initialize() (e.g. the sync queue
 * driver used in tests, running inline inside an already-tenant-scoped request).
 */
class AuditLandingPageFreshnessJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Perf H2 — debounce window for duplicate audits of the same page. */
    public int $uniqueFor = 600;

    public function __construct(
        private readonly ?string $tenantId,
        private readonly int $landingPageId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->landingPageId;
    }

    /**
     * Dispatch one audit job per same-tenant landing page whose `picks` JSON
     * references this product. Shared by ProductObserver (ignore-flip/detach/
     * delete) and ListingHealthService (high_price flag / listing_flags change)
     * so the "find affected pages" query never drifts between the two trigger
     * sites.
     *
     * Reviewer B1 (2026-08-10): a `picks LIKE '%"product_id":N,%'` SQL containment
     * check was tried here and is DEAD on MySQL — a native `json` column is stored
     * in an internal binary format and re-serialized to a *normalized* string (with
     * spaces after `:`/`,`) when coerced to text for `LIKE`, so the space-free
     * pattern never matches in production. sqlite's TEXT-backed `json()` column
     * happens to preserve Laravel's raw space-free `json_encode` output, which is
     * why every test passed despite the bug — see docs/lessons.md. Filtering in
     * PHP over the tenant's own (bounded — a dozen-ish rows) landing pages sidesteps
     * the whole class of bug and behaves identically on every connection.
     * No category filter: a product's category can itself have just changed, so a
     * STALE landing page (the one for the product's PREVIOUS category) must still
     * be found and audited.
     */
    public static function dispatchForProduct(Product $product): void
    {
        LandingPage::where('tenant_id', $product->tenant_id)
            ->get(['id', 'picks'])
            ->filter(fn (LandingPage $page) => collect($page->picks ?? [])->contains(
                fn ($pick) => (int) ($pick['product_id'] ?? 0) === $product->id
            ))
            ->pluck('id')
            ->each(fn (int $landingPageId) => self::dispatch($product->tenant_id, $landingPageId));
    }

    public function handle(): void
    {
        $alreadyInitialized = tenancy()->initialized;
        $shouldInitialize    = !$alreadyInitialized && $this->tenantId !== null;

        if ($shouldInitialize) {
            $tenant = Tenant::find($this->tenantId);

            if (!$tenant) {
                Log::warning('AuditLandingPageFreshnessJob: tenant not found', ['tenant_id' => $this->tenantId]);
                return;
            }

            tenancy()->initialize($tenant);
        }

        try {
            $page = LandingPage::find($this->landingPageId);

            if (!$page) {
                return;
            }

            $reasons = (new AuditLandingPageFreshness())->execute($page);

            Log::info('AuditLandingPageFreshnessJob: done', [
                'landing_page_id' => $this->landingPageId,
                'stale'           => !empty($reasons),
                'reasons'         => $reasons,
            ]);
        } finally {
            if ($shouldInitialize && tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }
}
