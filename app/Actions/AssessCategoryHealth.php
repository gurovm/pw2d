<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\CategoryHealthRow;
use App\Support\ListingHealth;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Spec 032 — computes, per category, the freshness/inventory numbers the owner
 * needs to answer "which categories are topped up, which is next" without an
 * SSH session and hand-written SQL.
 *
 * Two entry points sharing ONE definition of the SQL:
 * - {@see decorate()} adds counts/selects to a `categories` query Filament can
 *   still paginate and sort natively — it must stay a Builder, never a Collection.
 * - {@see execute()} calls decorate() and maps the result to DTOs, for the CLI
 *   and any other read-all caller.
 *
 * `buyable_count` reuses {@see ListingHealth::applyPurchasableOfferQuery()}
 * verbatim (2026-08-16 audit S2) — this must never become a fifth definition
 * of "is this offer purchasable".
 *
 * Tenancy: like {@see AuditLandingPageFreshness}, this assumes tenancy is
 * already initialized by the caller (the CLI command's try/finally, or
 * Filament's `TenantSet` bridge) — `execute()`'s explicit
 * `where('tenant_id', ...)` is defense-in-depth (CLAUDE.md multi-tenant data
 * access rule), not a substitute for it: the correlated subqueries in
 * `decorate()` rely on `Product`'s own `BelongsToTenant` global scope.
 */
final class AssessCategoryHealth
{
    /**
     * @return Collection<int, CategoryHealthRow>
     */
    public function execute(Tenant $tenant): Collection
    {
        return self::decorate(Category::query()->where('tenant_id', $tenant->id))
            ->orderBy('oldest_check')
            ->get()
            ->map(fn (Category $category) => self::fromRecord($category))
            ->values();
    }

    /**
     * Adds three `withCount` closures (`pool_count`, `buyable_count`,
     * `never_checked_count`) plus the plain unconstrained `products_count`
     * CategoryResource's "Rows" column already relies on, and two `addSelect`
     * correlated subqueries (`oldest_check`, `newest_check`). One query — these
     * are correlated subqueries inside the caller's existing query, not extra
     * round trips.
     */
    public static function decorate(Builder $categories): Builder
    {
        return $categories
            ->withCount([
                'products',
                'products as pool_count' => fn (Builder $q) => self::applyPoolQuery($q),
                'products as buyable_count' => fn (Builder $q) => self::applyPoolQuery($q)
                    ->whereHas('offers', fn (Builder $oq) => ListingHealth::applyPurchasableOfferQuery($oq)),
                'products as never_checked_count' => fn (Builder $q) => self::applyPoolQuery($q)
                    ->whereDoesntHave('offers', fn (Builder $oq) => $oq->whereNotNull('health_checked_at')),
            ])
            ->addSelect([
                'oldest_check' => self::checkedAtSubquery('MIN'),
                'newest_check' => self::checkedAtSubquery('MAX'),
            ]);
    }

    /** `pool` = `is_ignored = 0 AND status IS NULL` — the AI-Bouncer-approved set. */
    private static function applyPoolQuery(Builder $products): Builder
    {
        return $products->where('is_ignored', false)->whereNull('status');
    }

    /**
     * `MIN`/`MAX(product_offers.health_checked_at)` across the category's pool
     * — a correlated subquery against `categories.id`, scoped through
     * `Product`'s own tenant global scope (never an explicit `tenant_id` join
     * condition here — the same trust boundary `AuditLandingPageFreshness`
     * already relies on: a product's `category_id` FK pins one specific,
     * already tenant-scoped category row).
     *
     * `$aggregate` is caller-controlled ('MIN'/'MAX' literals only, never
     * request input) — safe to interpolate into the raw SQL fragment.
     */
    private static function checkedAtSubquery(string $aggregate): Builder
    {
        return self::applyPoolQuery(Product::query())
            ->join('product_offers', 'product_offers.product_id', '=', 'products.id')
            ->whereColumn('products.category_id', 'categories.id')
            ->selectRaw("{$aggregate}(product_offers.health_checked_at)");
    }

    /**
     * Builds the DTO from a `Category` record already decorated by
     * {@see decorate()} — public so `CategoryResource`'s column closures can
     * build the SAME DTO (and therefore reuse {@see CategoryHealthRow::reasons()}
     * verbatim) from Filament's own already-decorated row, instead of a second
     * copy of the threshold/reason logic living in the resource.
     */
    public static function fromRecord(Category $category): CategoryHealthRow
    {
        $oldest = $category->getAttribute('oldest_check');
        $newest = $category->getAttribute('newest_check');

        return new CategoryHealthRow(
            categoryId: $category->id,
            categoryName: $category->name,
            categorySlug: $category->slug,
            totalRows: (int) $category->products_count,
            pool: (int) $category->pool_count,
            buyable: (int) $category->buyable_count,
            neverChecked: (int) $category->never_checked_count,
            oldestCheck: $oldest !== null ? CarbonImmutable::parse($oldest) : null,
            newestCheck: $newest !== null ? CarbonImmutable::parse($newest) : null,
        );
    }
}
