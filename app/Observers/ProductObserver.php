<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\AuditLandingPageFreshnessJob;
use App\Models\Product;

/**
 * Spec 030 §B3 — instant freshness-audit trigger.
 *
 * A rescan/AI sweep can flip a product to `is_ignored` or detach it from its
 * category at any moment after a "Best X" landing page has published it as a
 * pick. This observer never runs the audit itself (that would block the
 * write that triggered it) — it only finds affected pages and dispatches a
 * queued {@see AuditLandingPageFreshnessJob} per page.
 *
 * The `high_price` listing-flag trigger lives at the service level instead
 * ({@see \App\Services\ListingHealthService}) — that's an OFFER-level change
 * that never touches `is_ignored`/`category_id`, so this observer would never
 * see it.
 */
class ProductObserver
{
    public function saved(Product $product): void
    {
        $ignoredFlipped  = $product->wasChanged('is_ignored') && $product->is_ignored === true;
        $categoryChanged = $product->wasChanged('category_id');

        if ($ignoredFlipped || $categoryChanged) {
            AuditLandingPageFreshnessJob::dispatchForProduct($product);
        }
    }

    public function deleted(Product $product): void
    {
        AuditLandingPageFreshnessJob::dispatchForProduct($product);
    }
}
