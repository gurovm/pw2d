<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\AuditLandingPageFreshnessJob;
use App\Jobs\RescanProductFeatures;
use App\Models\Product;

/**
 * Spec 030 §B3 — instant freshness-audit trigger.
 * Spec 035 — re-home integrity: clears feature values left behind by a
 * category change.
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
 *
 * Only reachable via a model-level save/delete — a mass `Product::where(...)
 * ->update(...)` fires no Eloquent events and silently bypasses both this
 * audit trigger and the feature-value cleanup below (the 2026-08-21 incident,
 * Spec 035). Both AI commands (`pw2d:ai-sweep-category`, `pw2d:ai-assign-
 * categories`) were fixed to use model-level saves for exactly this reason —
 * any FUTURE re-home path must do the same to be covered here.
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

        if ($categoryChanged) {
            $this->clearForeignCategoryFeatureValues($product);
        }
    }

    public function deleted(Product $product): void
    {
        AuditLandingPageFreshnessJob::dispatchForProduct($product);
    }

    /**
     * A `Feature` belongs to exactly one category, so once a product leaves
     * that category any existing `product_feature_value` for a feature outside
     * the new (or null) category is unreachable data — it can never be read
     * for this product again, yet still surfaces via `featureValues()`
     * iteration, which is how stale scores reached a landing page in the
     * 2026-08-21 incident (309 rows across 55 products). Deleting is correct,
     * not lossy.
     *
     * Detaching to no category (`category_id === null`) clears everything —
     * a detached product's scores belong to no category. Re-homing to a new
     * category clears only the OLD category's values (an EXISTS subquery via
     * `whereHas`, not a per-product load of every feature row) and queues a
     * {@see RescanProductFeatures} so the new category's scores replace them.
     */
    private function clearForeignCategoryFeatureValues(Product $product): void
    {
        if ($product->category_id === null) {
            $product->featureValues()->delete();

            return;
        }

        $product->featureValues()
            ->whereHas('feature', fn ($query) => $query->where('category_id', '!=', $product->category_id))
            ->delete();

        RescanProductFeatures::dispatch($product->id, $product->category_id);
    }
}
