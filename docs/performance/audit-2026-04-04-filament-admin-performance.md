# Performance Audit: Filament Admin Panel
**Date:** 2026-04-04

## Files Audited
- `app/Filament/Resources/ProductResource.php`
- `app/Filament/Resources/ProductResource/Pages/ListProducts.php`
- `app/Filament/Resources/ProductResource/RelationManagers/OffersRelationManager.php`
- `app/Filament/Resources/ProductResource/RelationManagers/FeatureValuesRelationManager.php`
- `app/Filament/Resources/CategoryResource.php`
- `app/Filament/Resources/AiMatchingDecisionResource.php`
- `app/Filament/Pages/ProblemProducts.php`
- `app/Filament/Widgets/ProductStatsWidget.php`
- `app/Providers/Filament/AdminPanelProvider.php`

## Summary

Top 3 things to fix:

1. **ProblemProducts navigation badge fires a complex REGEXP query on every Filament page load** (the `getNavigationBadge()` method calls `problemQuery()->count()` which includes subqueries and REGEXP). This runs on every single admin page render, not just when visiting Problem Products.

2. **ProductStatsWidget fires 6 separate COUNT queries** with no caching. Combined with the badge query, the Filament dashboard hits the database with 7+ queries just to render the sidebar and widget stats.

3. **`withoutGlobalScopes()` in ListProducts bypasses tenant scoping** for the "Retry Failed" button, counting and operating on failed products across ALL tenants. This is both a correctness and performance issue.

---

## Critical Issues

| # | Issue | Location | Impact | Fix |
|---|-------|----------|--------|-----|
| 1 | **Navigation badge runs expensive REGEXP query on every page load** | `ProblemProducts.php:46-49` | `getNavigationBadge()` is called by Filament on every admin page render (it builds the sidebar navigation). The `problemQuery()` includes `REGEXP`, correlated subqueries (`SELECT ... FROM categories WHERE categories.id = products.category_id`), and `whereDoesntHave`/`whereHas` clauses. This complex query fires on every page load, not just when visiting the Problem Products page. | Cache the count: `Cache::remember('problem-products-count:' . tenant('id'), 120, fn () => static::problemQuery()->count())`. Bust the cache when products are ignored/updated. |
| 2 | **`withoutGlobalScopes()` bypasses tenant scoping on Retry Failed** | `ListProducts.php:28, 43` | `Product::withoutGlobalScopes()->where('status', 'failed')->count()` counts failed products across ALL tenants. The retry action on line 43 also queries and updates products across all tenants. An admin operating on tenant A could retry products belonging to tenant B. | Replace `withoutGlobalScopes()` with a normal query. Since Filament tenancy is active via the `TenantSet` event bridge, `Product::where('status', 'failed')` will already be scoped to the current tenant. If the intent is genuinely cross-tenant, add an explicit `->where('tenant_id', tenant('id'))` as a safety net. |

## High Priority

| # | Issue | Location | Impact | Fix |
|---|-------|----------|--------|-----|
| 3 | **ProductStatsWidget fires 6 uncached queries** | `ProductStatsWidget.php:15-21` | Five `Product::count()` / `Product::where(...)->count()` queries plus one `DB::table('jobs')->count()`. These fire on the dashboard and on the ListProducts page (via `getHeaderWidgets()`). With `$isLazy = true` they load asynchronously but still hit the DB every time the component renders. | Combine into a single query using conditional aggregation, then cache for 60s: `Cache::remember('product-stats:' . tenant('id'), 60, fn () => DB::table('products')->where('tenant_id', tenant('id'))->selectRaw("COUNT(*) as total, SUM(CASE WHEN is_ignored = 0 AND status IS NULL THEN 1 ELSE 0 END) as live, ...")->first())`. The `jobs` count can remain separate since it is a central table. |
| 4 | **AiMatchingDecisionResource: N+1 on `product.name` column** | `AiMatchingDecisionResource.php:41-51` | The table displays `product.name` via a relationship column, but no `modifyQueryUsing` with eager loading is defined. Filament will lazy-load the `product` relationship for each row, causing N+1 queries (up to 25 queries for a page of 25 rows). | Add `->modifyQueryUsing(fn (Builder $query) => $query->with('product:id,name'))` to the table definition. |
| 5 | **CategoryResource: missing eager loading for `parent.name`** | `CategoryResource.php:103-107` | The table displays `parent.name` but has no `modifyQueryUsing` with eager loading. Each row triggers a lazy load of the `parent` relationship. | Add `->modifyQueryUsing(fn (Builder $query) => $query->with('parent:id,name,tenant_id'))`. |
| 6 | **FeatureValuesRelationManager: N+1 on `feature.category.name`** | `FeatureValuesRelationManager.php:58-59` | The table displays `feature.category.name` and accesses `$record->feature->unit` and `$record->feature->is_higher_better` in formatters/tooltips, but there is no `modifyQueryUsing` to eager-load `feature.category`. Each row loads `feature` and then `feature.category` lazily. | Add `->modifyQueryUsing(fn (Builder $query) => $query->with('feature.category:id,name,tenant_id'))` to the table definition. |
| 7 | **OffersRelationManager: `Store::pluck('name', 'id')` loads all stores globally** | `OffersRelationManager.php:23` | The form's store dropdown calls `Store::pluck('name', 'id')` which loads all stores from all tenants (the `BelongsToTenant` scope should filter this, but only when tenancy is initialized). More importantly, it loads all stores every time the form opens. | Use `->relationship('store', 'name')` with `->searchable()->preload()` instead of a raw `pluck()`, which lets Filament handle the query with relationship scoping. |
| 8 | **Bulk "Mark as Ignored" in ProductResource uses individual updates** | `ProductResource.php:248-252` | The `markIgnored` bulk action calls `$record->update()` in a `foreach` loop, executing one UPDATE per selected product. Selecting 50 products means 50 individual queries. | Use a single bulk update like ProblemProducts already does: `Product::whereIn('id', $records->pluck('id'))->update(['is_ignored' => true, 'status' => null])`. ProblemProducts.php line 241 shows the correct pattern. |
| 9 | **`getHeaderActions()` query runs on every ListProducts render** | `ListProducts.php:28` | `Product::withoutGlobalScopes()->where('status', 'failed')->count()` runs every time the ListProducts page renders (including after pagination, search, filter changes). This is not cached. | Cache or move the count to a widget/badge so it does not re-query on every table interaction. A simple `Cache::remember('failed-products-count:' . tenant('id'), 60, ...)` would eliminate repeated queries. |

## Medium Priority

| # | Issue | Location | Impact | Fix |
|---|-------|----------|--------|-----|
| 10 | **ProblemProducts: `detectProblems()` re-accesses relationships per row** | `ProblemProducts.php:79-111` | The `detectProblems()` static method accesses `$record->offers`, `$record->category`, etc. for each row. Since `modifyQueryUsing` already eager-loads these, the data is in memory and no extra queries fire. However, `$record->category->budget_max` will be null if the product has no category, and the method does not guard `budget_max` on the eager-loaded select (it does include `budget_max` -- good). This is correctly handled. No query issue, but the REGEXP-based `problemQuery()` is still expensive per se. | Consider pre-computing problem flags in a scheduled job or storing a `has_problems` boolean on the product for faster counting. |
| 11 | **ProblemProducts: correlated subquery in `problemQuery()` for low-price check** | `ProblemProducts.php:68-70` | `whereRaw('scraped_price < (SELECT COALESCE(budget_max, 50) * 0.5 FROM categories WHERE categories.id = products.category_id)')` is a correlated subquery that executes once per row scanned. For large product tables this adds measurable overhead. | Consider joining the categories table instead: `->join('categories', 'categories.id', '=', 'products.category_id')->whereRaw('product_offers.scraped_price < COALESCE(categories.budget_max, 50) * 0.5')`. Or pre-compute and store low-price flags. |
| 12 | **`importViaAI` action loads Category::pluck() without tenant scoping check** | `ListProducts.php:67` | `\App\Models\Category::pluck('name', 'id')` loads category options for the select. With Filament tenancy active this should be scoped, but it lacks `->select()` optimization and loads all columns before plucking. This is a minor overhead; `pluck()` generates a `SELECT name, id` query at the DB level so it is actually efficient. | No change needed -- `pluck()` is already SQL-level efficient. |
| 13 | **Missing index on `product_offers.product_id` + `scraped_price` for price sorting** | `ProductResource.php:154-156` | The price sorting uses `->withMin('offers', 'scraped_price')->orderBy('offers_min_scraped_price', ...)`, which generates a subquery `SELECT MIN(scraped_price) FROM product_offers WHERE product_offers.product_id = products.id`. The `product_offers` table has no composite index on `(product_id, scraped_price)`. The FK index on `product_id` alone means MySQL must scan all offers for each product. | Add a covering index: `$table->index(['product_id', 'scraped_price'], 'idx_offers_product_price')`. |
| 14 | **No `$recordTitleAttribute` on AiMatchingDecisionResource** | `AiMatchingDecisionResource.php` | Missing `$recordTitleAttribute` means Filament may run extra queries when building record labels for delete confirmations. Minor. | Add `protected static ?string $recordTitleAttribute = 'scraped_raw_name';` to the resource class. |

## Low Priority

| # | Issue | Location | Impact | Fix |
|---|-------|----------|--------|-----|
| 15 | **CategoryResource: `products_count` with `->counts('products')` generates a subquery** | `CategoryResource.php:112-113` | Filament's `->counts()` generates `SELECT *, (SELECT COUNT(*) ...) as products_count`. This is standard and efficient for small tables (categories are typically <50 rows). No issue at current scale. | No change needed unless categories grow significantly. |
| 16 | **OffersRelationManager: missing `->with('store')` for `store.name` column** | `OffersRelationManager.php:52-55` | The table displays `store.name` but does not explicitly eager-load the store relationship. Filament relation managers usually handle this automatically since the context is a single product's offers (small N), but it could cause N+1 for products with many store offers. | Add `->modifyQueryUsing(fn (Builder $query) => $query->with('store:id,name'))` for correctness, though impact is minimal since most products have 1-3 offers. |
| 17 | **AdminPanelProvider registers default widgets that are not used** | `AdminPanelProvider.php:44-46` | `Widgets\AccountWidget::class` and `Widgets\FilamentInfoWidget::class` are registered but add minimal overhead. They render simple static content. | Optional: remove `FilamentInfoWidget` if not needed. Trivial impact. |

---

## Caching Recommendations

| Data | Current TTL | Recommended TTL | Expected Gain |
|------|-------------|-----------------|---------------|
| Problem Products count (navigation badge) | None (live query every page load) | 120s | Eliminates a complex REGEXP + subquery COUNT on every admin page render |
| Product stats (widget) | None (5-6 live COUNT queries) | 60s | Eliminates 5 queries per dashboard/ListProducts render |
| Failed product count (Retry Failed button) | None (live query every ListProducts render) | 60s | Eliminates 1 cross-scope COUNT query per ListProducts render |
| Leaf categories (Import via AI dropdown) | None (`pluck()` on every form open) | 600s | Minor -- pluck is fast but categories rarely change |

---

## Index Recommendations

```sql
-- Migration: add_performance_indexes_for_filament_admin

-- 1. Covering index for product offers price lookups (used by ProductResource
--    price sorting and ProblemProducts price checks)
ALTER TABLE product_offers
  ADD INDEX idx_offers_product_price (product_id, scraped_price);

-- 2. Index for AiMatchingDecision listing (default sort by created_at desc,
--    filtered by tenant_id via global scope)
ALTER TABLE ai_matching_decisions
  ADD INDEX idx_ai_decisions_tenant_created (tenant_id, created_at);

-- 3. Index for the "failed products" count used by ListProducts header action
--    Already partially covered by (tenant_id, status) but confirming it exists:
-- EXISTS: index (tenant_id, status) -- from 2026_03_21_120000 migration. OK.

-- 4. Index for ProblemProducts query: is_ignored + status filter
--    Already partially covered by idx_products_tenant_category_ignored
--    but the problemQuery also filters on (is_ignored = false, status IS NULL)
--    without category_id. A dedicated index would help:
ALTER TABLE products
  ADD INDEX idx_products_tenant_ignored_status (tenant_id, is_ignored, status);
```

Equivalent Laravel migration:

```php
// database/migrations/2026_04_05_000001_add_filament_performance_indexes.php

Schema::table('product_offers', function (Blueprint $table) {
    $table->index(['product_id', 'scraped_price'], 'idx_offers_product_price');
});

Schema::table('ai_matching_decisions', function (Blueprint $table) {
    $table->index(['tenant_id', 'created_at'], 'idx_ai_decisions_tenant_created');
});

Schema::table('products', function (Blueprint $table) {
    $table->index(
        ['tenant_id', 'is_ignored', 'status'],
        'idx_products_tenant_ignored_status'
    );
});
```

---

## `withoutGlobalScopes()` Analysis

Two usages found in `ListProducts.php`:

**Line 28:** `Product::withoutGlobalScopes()->where('status', 'failed')->count()`
- **Intent:** Count failed products to show in the "Retry Failed" button label.
- **Problem:** Bypasses the `TenantScope` added by `BelongsToTenant`. Since the admin panel initializes stancl tenancy via the `TenantSet` event (AdminPanelProvider.php:70-72), the normal `Product::where('status', 'failed')` query would already be correctly scoped to the active tenant. Using `withoutGlobalScopes()` here means the count includes failed products from ALL tenants, and the retry action processes them all.
- **Verdict:** This is a bug. Remove `withoutGlobalScopes()`.

**Line 43:** `Product::withoutGlobalScopes()->where('status', 'failed')->whereNotNull('category_id')->each(...)`
- **Same problem as above.** The retry action processes failed products across all tenants.
- **Verdict:** Remove `withoutGlobalScopes()`. If the developer intended to catch products that somehow lost their tenant scope, add `->where('tenant_id', tenant('id'))` explicitly as a safety net instead.

---

## Bulk Action Efficiency

### ProductResource `markIgnored` (line 248-252) -- NEEDS FIX
```php
// Current: N individual UPDATE queries
foreach ($records as $record) {
    $record->update(['is_ignored' => true, 'status' => null]);
}

// Fix: Single UPDATE query
Product::whereIn('id', $records->pluck('id'))
    ->update(['is_ignored' => true, 'status' => null]);
```

### ProductResource `aiRescanBulk` (line 231-239) -- ACCEPTABLE
Each product dispatches a separate `RescanProductFeatures` job. This is intentional since each job makes an individual AI API call. The loop is over the selected records (typically <50) and only dispatches jobs, not running heavy logic. Acceptable.

### ProblemProducts `markIgnored` (line 238-241) -- ALREADY OPTIMAL
Already uses `Product::whereIn('id', $records->pluck('id'))->update(...)`. Good.

---

## Summary of Fixes by Priority

### Do Now (Critical)
1. Remove `withoutGlobalScopes()` from `ListProducts.php` lines 28 and 43
2. Cache the `ProblemProducts::getNavigationBadge()` count (120s TTL)

### Do Soon (High)
3. Consolidate `ProductStatsWidget` into a single query with caching (60s TTL)
4. Add `->modifyQueryUsing()` with eager loading to `AiMatchingDecisionResource`
5. Add `->modifyQueryUsing()` with eager loading to `CategoryResource`
6. Add eager loading to `FeatureValuesRelationManager`
7. Convert `markIgnored` bulk action to single UPDATE query in `ProductResource`
8. Cache the failed product count in `ListProducts::getHeaderActions()`

### Do Later (Medium)
9. Add `idx_offers_product_price` index on `product_offers`
10. Add `idx_ai_decisions_tenant_created` index on `ai_matching_decisions`
11. Add `idx_products_tenant_ignored_status` index on `products`
12. Refactor ProblemProducts correlated subquery to a JOIN
