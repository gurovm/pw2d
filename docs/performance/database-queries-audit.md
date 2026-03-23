# Performance Audit: Database & Queries
**Date:** 2026-03-22

## Summary
> 1. **Setting::get() cache key missing tenant scope** -- the `rememberForever` cache key `setting:{key}` is not tenant-aware, causing cross-tenant data leakage and incorrect behavior.
> 2. **scoredProducts cache key missing tenant scope** -- same issue; tenant products can bleed into another tenant's cached results.
> 3. **SitemapController loads ALL products/categories into memory** -- no pagination, no chunking; will OOM with 10k+ products across tenants.

## Critical Issues

| Issue | Location | Impact | Fix |
|-------|----------|--------|-----|
| **Setting cache key not tenant-scoped** | `app/Models/Setting.php:19` | Cross-tenant data leakage. Tenant A sees Tenant B's `image_source` setting. | Change cache key to `"setting:{tenantId}:{$key}"` where `tenantId = tenancy()->initialized ? tenant('id') : 'central'`. |
| **scoredProducts cache key not tenant-scoped** | `app/Livewire/ProductCompare.php:112` | Tenant A can receive cached product arrays from Tenant B if category IDs overlap. | Prepend tenant ID: `"products:t{tenantId}:cat{id}:b{brand}:p{price}"`. |
| **SimilarProducts cache key not tenant-scoped** | `app/View/Components/SimilarProducts.php:20` | Same product ID across tenants returns wrong similar products. | Prepend tenant ID to cache key. |
| **Product::imageUrl accessor calls Setting::get() on EVERY product render** | `app/Models/Product.php:146` | For 12 visible products, this accessor fires 12 times. The `rememberForever` cache helps, but the cache key is wrong (see above). | After fixing the cache key, this is fine. But consider passing `$imageSource` once from the component instead of calling it per-product. |

## High Priority

| Issue | Location | Impact | Fix |
|-------|----------|--------|-----|
| **SitemapController loads entire products table** | `app/Http/Controllers/SitemapController.php:15-16` | `Product::get()` + `Category::get()` loads all records. At 10k products this is ~50MB RAM. | Use `cursor()` or `chunk()` and stream the XML response. Or use `LazyCollection`. |
| **Home::render() queries on every render (not cached)** | `app/Livewire/Home.php:24-47` | Two `Category` queries + one `Category::inRandomOrder()` on every page load. Popular categories rarely change. | Cache `popularCategories` for 300s with tenant-scoped key. Cache `samplePrompts` similarly. |
| **GlobalSearch::performAiSearch loads ALL categories** | `app/Livewire/GlobalSearch.php:126-127` | `Category::with('presets')->get()` loads every category and preset every time AI search fires. | Cache this with tenant-scoped key, TTL 300s. Categories/presets change infrequently. |
| **ComparisonHeader::mount queries Category separately** | `app/Livewire/ComparisonHeader.php:68` | `Category::with('children')->find($this->categoryId)` duplicates the query already done in ProductCompare. | Pass the category object from the parent component instead of querying by ID again. |
| **ProductCompare::render() queries Preset inside render** | `app/Livewire/ProductCompare.php:559-561` | On every render when `activePresetSlug` is set, `Preset::where(...)->get()` runs and then filters in PHP. | Move this to `mount()` or cache the presets collection. Use a DB `whereRaw` with `slug` generation or add a `slug` column to presets. |
| **Filament ProductStatsWidget: 5 COUNT queries, isLazy=false** | `app/Filament/Widgets/ProductStatsWidget.php:13-21` | 5 separate COUNT queries on `products` + 1 on `jobs`, all running eagerly on every admin page load. | Combine into a single query with conditional aggregation: `SELECT COUNT(*) as total, SUM(CASE WHEN ...) ...`. Set `$isLazy = true`. |
| **inRandomOrder() on products table** | `app/View/Components/SimilarProducts.php:29,43` | `ORDER BY RAND()` requires a full table scan. Acceptable only because results are cached (7 days). | Already mitigated by caching. No immediate fix needed unless cache is invalidated frequently. |
| **inRandomOrder() on categories** | `app/Livewire/Home.php:42` | Runs on every uncached Home render. Small table, low risk. | Cache the result (see above). |
| **BatchImportController: individual UPDATE per refreshed product** | `app/Http/Controllers/Api/BatchImportController.php:101-110` | N updates in a loop. For 100 refreshed products, this is 100 queries. | Use `DB::table('products')->upsert(...)` with a single batch query, or use `CASE WHEN` bulk update. |

## Medium Priority

| Issue | Location | Impact | Fix |
|-------|----------|--------|-----|
| **Filament ListProducts: importViaAI loads all categories** | `app/Filament/Resources/ProductResource/Pages/ListProducts.php:34` | `Category::pluck('name', 'id')` without tenant scoping in form options. | Already scoped by `BelongsToTenant` when tenancy is initialized. Low risk, but verify. |
| **GlobalSearch::runDbSearch: LIKE queries without index** | `app/Livewire/GlobalSearch.php:252-277` | `name LIKE '%term%'` cannot use indexes (leading wildcard). | Acceptable for small datasets (<5k). For scale, add MySQL FULLTEXT index on `products.name` and `categories.name`. |
| **Category::getAllDescendants() is recursive with N+1** | `app/Models/Category.php:101-111` | Recursively calls `$this->children` without eager loading. Each level triggers a query. | Eager load children recursively or use adjacency list WITH RECURSIVE CTE. Low impact because tree depth is 2. |

## Caching Recommendations

| Data | Current | Recommended TTL | Expected Gain |
|------|---------|-----------------|---------------|
| Setting::get() | `rememberForever` (wrong key) | `rememberForever` with tenant-scoped key | Correctness fix, no perf change |
| scoredProducts raw data | 90s (wrong key) | 90s with tenant-scoped key | Correctness fix |
| SimilarProducts | 7 days (wrong key) | 7 days with tenant-scoped key | Correctness fix |
| Home popular categories | None | 300s, tenant-scoped | Eliminates 2 queries/page load |
| Home sample prompts | None | 300s, tenant-scoped | Eliminates 1-2 queries/page load |
| GlobalSearch category context | None | 300s, tenant-scoped | Eliminates `Category::with('presets')->get()` per AI search |
| Tenant resolution | 3600s via DomainTenantResolver | 3600s (fine) | Already good |
| Features per category | None (queried in mount) | 600s, tenant+category scoped | Saves 1 query/page load |

## Index Recommendations

```sql
-- Migration: add_fulltext_and_composite_indexes

-- FULLTEXT for search (GlobalSearch LIKE '%term%' upgrade path)
ALTER TABLE products ADD FULLTEXT INDEX ft_products_name (name);
ALTER TABLE categories ADD FULLTEXT INDEX ft_categories_name (name);

-- Composite covering index for the hot scoredProducts query
-- Query: WHERE category_id = ? AND is_ignored = 0 AND status IS NULL
-- The tenant_id leading index already helps, but this covers the exact filter combo
ALTER TABLE products ADD INDEX idx_products_tenant_cat_status_ignored
    (tenant_id, category_id, is_ignored, status);

-- product_feature_values: covering index for the eager load in scoredProducts
-- Query: WHERE product_id IN (...) selecting id, product_id, feature_id, raw_value
ALTER TABLE product_feature_values ADD INDEX idx_pfv_product_feature_value
    (product_id, feature_id, raw_value);

-- presets: add slug column to avoid runtime Str::slug() matching
ALTER TABLE presets ADD COLUMN slug VARCHAR(255) GENERATED ALWAYS AS
    (LOWER(REPLACE(REPLACE(name, ' ', '-'), '--', '-'))) STORED AFTER name;
ALTER TABLE presets ADD INDEX idx_presets_tenant_category_slug
    (tenant_id, category_id, slug);
```
