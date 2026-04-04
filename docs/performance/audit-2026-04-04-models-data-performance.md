# Performance Audit: Models & Data Layer
**Date:** 2026-04-04
**Scope:** Product (accessors), ProductOffer, Category, Setting, Preset, ProductScoringService, cache helper, FeatureObserver, CategoryObserver, database indexes

## Summary
> **Top 3 things to fix:**
> 1. **visibleProducts N+1 on offers/store** -- The `visibleProducts` computed property loads products with `['brand', 'featureValues.feature']` but NOT `offers.store`. Every call to `$product->image_url`, `$product->affiliate_url`, or `$product->best_offer` in the Blade template triggers lazy-loaded queries per product (up to 3 queries per product x 12 visible = 36 extra queries per page render).
> 2. **SimilarProducts N+1 on offers/store/brand** -- The `SimilarProducts` component fetches products via `Product::where(...)->get()` with zero eager loading, then the Blade template accesses `->image_url`, `->affiliate_url`, and `->brand->name` for each of the 4 products (up to 12 extra queries).
> 3. **Missing composite index on `product_offers(product_id, scraped_price)`** -- The `best_price` accessor, `scoredProducts` cache builder, and the `maxPrice` query all scan offers by `product_id` with price ordering/aggregation. A covering index would eliminate table lookups.

---

## Critical Issues

| # | Issue | Location | Impact | Fix |
|---|-------|----------|--------|-----|
| C1 | **N+1: visibleProducts missing `offers.store` eager load** | `app/Livewire/ProductCompare.php:181-184, 207-209` | Every product card triggers lazy loads for `offers`, `offers.store` when Blade accesses `image_url`, `affiliate_url`, `best_offer`. **~36 extra queries per page render** (12 products x 3 accessors). | Change `with(['brand', 'featureValues.feature'])` to `with(['brand', 'featureValues.feature', 'offers.store'])` in both H2H and normal paths. |
| C2 | **N+1: SimilarProducts -- zero eager loading** | `app/View/Components/SimilarProducts.php:24-45` | Template accesses `->image_url`, `->affiliate_url`, `->brand->name` for 4 products. Each triggers lazy loads for `offers`, `offers.store`, `brand`. **~12 extra queries per product detail view** (mitigated by 7-day cache, but first-hit is painful and cache is per-product). | Add `->with(['brand', 'offers.store'])` to both queries in the component. |
| C3 | **N+1: selectedProduct missing `offers.store` eager load** | `app/Livewire/ProductCompare.php:91` | `selectedProduct` is loaded with `['brand', 'featureValues.feature']` but the product modal Blade accesses `image_url` and `affiliate_url`, which need `offers.store`. **~3 extra queries every time a product modal opens.** | Change to `with(['brand', 'featureValues.feature', 'offers.store'])`. |
| C4 | **N+1: SeoSchema::forSelectedProduct accesses `image_url` without offers** | `app/Support/SeoSchema.php:91` | `$product->image_url` triggers lazy load of `offers` if not already loaded. Called from `render()` on every Livewire re-render when a product is selected. | Ensure `selectedProduct` eager loads `offers.store` (fixes with C3). |
| C5 | **N+1: SeoSchema::buildItemListSchema accesses `offers` per product** | `app/Support/SeoSchema.php:213` | `$product->offers?->first()?->image_url` for every visible product. If `offers` was not eager-loaded on `visibleProducts`, this is another N+1. | Ensure `visibleProducts` eager loads `offers` (fixes with C1). |

## High Priority

| # | Issue | Location | Impact | Fix |
|---|-------|----------|--------|-----|
| H1 | **`inRandomOrder()` on product table** | `app/View/Components/SimilarProducts.php:29,43` | `ORDER BY RAND()` forces a full table scan. With growing product tables, this degrades linearly. Currently mitigated by 7-day cache TTL, but first-hit and cache-miss are expensive. | Accept the `inRandomOrder()` cost since results are cached 7 days. Long-term: switch to `->offset(rand(0, $count - 4))->limit(4)` with a pre-counted total. Low urgency since the cache absorbs it. |
| H2 | **Product::bestOffer accessor accesses `$offer->store` without guarantee of eager load** | `app/Models/Product.php:113-115` | The `bestOffer` accessor sorts by `store->commission_rate` and `store->priority`. If `offers.store` is not eager-loaded, this triggers a lazy load per offer. Combined with `affiliateUrl` (which calls `best_offer->store->affiliate_params`), this cascades. | Always eager-load `offers.store` when products will be rendered. Consider documenting this requirement in the accessor's PHPDoc. |
| H3 | **SeoSchema preset lookup fetches ALL presets for category, then filters in PHP** | `app/Support/SeoSchema.php:161-162` | `Preset::where('category_id', ...)->get()->first(fn => Str::slug(...))` loads all presets into memory and scans them. Should use a `where` clause or add a `slug` column to presets. | Add `slug` column to presets table (stored on save), then query directly: `Preset::where('category_id', ...)->where('slug', $activePresetSlug)->first()`. |
| H4 | **RecalculatePriceTiers accesses `best_price` which iterates offers in memory** | `app/Console/Commands/RecalculatePriceTiers.php:40-49` | `$category->products->filter(fn ($p) => $p->best_price !== null)` loads all products, then for each product `best_price` iterates the offers collection. With `->with(['products.offers'])`, this is correct but loads everything into memory. For 1000+ products per category, memory could spike. | For a CLI command, this is acceptable. If categories grow past 500+ products, switch to chunked processing with `$category->products()->with('offers')->chunk(100, ...)`. |
| H5 | **Category::getAllDescendants is recursive with N+1** | `app/Models/Category.php:101-110` | Each recursive call accesses `$this->children` (lazy load if not eager-loaded), then recurses. For a 3-level tree this fires 1 + N_children + N_grandchildren queries. | Eager-load children upfront or use an iterative CTE approach. Currently only used in `FeatureObserver` (admin-time, not user-facing), so impact is low. |
| H6 | **FeatureObserver cascading Feature::create triggers itself recursively** | `app/Observers/FeatureObserver.php:38-44` | Each `Feature::create()` inside `propagateToDescendants` fires the `created` observer again, which calls `getAllDescendants` again. For a 3-level tree, this is O(N^2) observer calls. | Add a static flag or use `Feature::withoutEvents()` for propagated creates. |

## Medium Priority

| # | Issue | Location | Impact | Fix |
|---|-------|----------|--------|-----|
| M1 | **Setting::get() caches `null` for missing keys (default swallowed)** | `app/Models/Setting.php:17-22` | When a key does not exist, `Cache::rememberForever` stores `null`. On subsequent calls with a *different* default, the cached `null` is returned, ignoring the new default. | Cache the raw value (or a sentinel), then apply the default outside the cache: `$cached = Cache::rememberForever(..., fn() => static::where('key', $key)->value('value')); return $cached ?? $default;` |
| M2 | **`estimatedPrice` re-computes `best_price` (which re-iterates offers)** | `app/Models/Product.php:141` | `$this->best_price` inside `estimatedPrice` iterates `$this->offers` again. Two full scans of the offers collection for one product display. | Laravel caches accessor results within a request, so this is a non-issue for a single access. However, if accessed in a loop without `offers` eager-loaded, each call triggers separate queries. The real fix is C1 (eager-load offers). |
| M3 | **`imageUrl` accessor calls `best_offer` which re-scans offers** | `app/Models/Product.php:169-176` | `$this->best_offer` inside `imageUrl` does a full sort of the offers collection. Then `$this->offers->first(...)` scans again as a fallback. Three potential collection scans. | Minor in-memory cost. The real fix is ensuring `offers.store` is eager-loaded (C1, C3). |
| M4 | **`availableBrands` computed property uses `#[Computed(persist: true)]` but is invalidated on any component update** | `app/Livewire/ProductCompare.php:70-85` | `persist: true` survives across renders but is cleared when properties change. Since sliders fire `weights-updated` events frequently, this may not persist well. The query itself does 2 subqueries (`whereHas` + `withCount`). | Consider caching with `Cache::remember()` using `tenant_cache_key("brands:cat{$this->category->id}")` with a 300s TTL. Brand lists rarely change. |
| M5 | **GlobalSearch loads `category` relation on products but also calls `with('offers:...')`** | `app/Livewire/GlobalSearch.php:258-271` | Two `with()` calls are chained correctly. However, the `with('category:id,name,slug')` on line 258 is potentially overwritten by the second `with('offers:...')` on line 271 depending on call order. | Merge into a single `with()` call: `->with(['category:id,name,slug', 'offers:id,product_id,image_url'])`. This is a clarity fix, not a performance one -- Laravel merges them correctly. |

## Caching Recommendations

| Data | Current | Recommended TTL | Expected Gain |
|------|---------|-----------------|---------------|
| `scoredProducts` raw data | 90s via `Cache::remember()` | 90s is good for slider changes. No change needed. | Already optimized. |
| `SimilarProducts` | 7 days | 7 days is appropriate (static random). No change. | Already optimized. |
| `Setting::get()` | `rememberForever` | Keep forever, but fix the default-value bug (M1). | Correctness fix, not perf. |
| `availableBrands` | `#[Computed(persist: true)]` (volatile) | Add `Cache::remember()` with 300s TTL | Eliminates 2 subqueries per slider change. |
| Category features (used in mount) | None | `Cache::remember()` 600s, key: `features:cat{id}` | Small gain -- only fires once per mount. |
| SeoSchema preset lookup | None | Add `slug` column to presets; if not, `Cache::remember()` 600s | Eliminates loading all presets per render. |
| `maxPrice` query in mount | None | Could cache 300s, key: `maxprice:cat{id}` | Small gain -- only fires once per mount. |

## Index Recommendations

### Missing indexes (new migration needed)

```sql
-- 1. product_offers: covering index for best_price / offer aggregation queries.
-- Used by: Product::bestPrice, Product::bestOffer, ProductCompare::scoredProducts, maxPrice query.
-- The (product_id) FK index exists but doesn't cover scraped_price.
ALTER TABLE product_offers
    ADD INDEX idx_product_offers_product_price (product_id, scraped_price);

-- 2. product_offers: tenant + product covering index for tenant-scoped offer lookups.
-- The BelongsToTenant scope adds WHERE tenant_id = ? to every query.
-- Currently no composite index leads with tenant_id + product_id.
ALTER TABLE product_offers
    ADD INDEX idx_product_offers_tenant_product (tenant_id, product_id);

-- 3. products: composite for the main scoredProducts query.
-- Query: WHERE category_id = ? AND is_ignored = false AND status IS NULL
-- The idx_products_tenant_category_ignored index exists (tenant_id, category_id, is_ignored)
-- but does NOT include status. Extend it:
ALTER TABLE products
    ADD INDEX idx_products_scoring_query (tenant_id, category_id, is_ignored, status);
-- Then drop the now-redundant shorter index:
ALTER TABLE products
    DROP INDEX idx_products_tenant_category_ignored;

-- 4. settings: composite for tenant-scoped key lookup.
-- Currently has UNIQUE(tenant_id, key) which serves as an index. This is sufficient.
-- No change needed.

-- 5. presets: add index for category_id lookup (currently no index).
-- Used by SeoSchema, ComparisonHeader, and preset loading.
ALTER TABLE presets
    ADD INDEX idx_presets_tenant_category (tenant_id, category_id);

-- 6. features: the existing (tenant_id, category_id) index is good.
-- Add sort_order to support ORDER BY in the features query:
ALTER TABLE features
    ADD INDEX idx_features_category_sort (tenant_id, category_id, sort_order);
-- Then drop the now-redundant shorter index:
ALTER TABLE features
    DROP INDEX features_tenant_id_category_id_index;

-- 7. product_feature_values: index for the scoring query.
-- Query joins on product_id and selects feature_id + raw_value.
-- The existing UNIQUE(product_id, feature_id) already serves as a covering index
-- for the scoring service. No change needed.

-- 8. brands: no index on (tenant_id, id) -- the products table references brand_id,
-- and the availableBrands whereHas subquery filters by category_id on products,
-- not on brands directly. The existing (tenant_id, name) index covers brand lookups.
-- No change needed.
```

### Laravel migration equivalent

```php
// database/migrations/2026_04_05_000001_add_performance_indexes.php

Schema::table('product_offers', function (Blueprint $table) {
    $table->index(['product_id', 'scraped_price'], 'idx_product_offers_product_price');
    $table->index(['tenant_id', 'product_id'], 'idx_product_offers_tenant_product');
});

Schema::table('products', function (Blueprint $table) {
    $table->index(
        ['tenant_id', 'category_id', 'is_ignored', 'status'],
        'idx_products_scoring_query'
    );
    $table->dropIndex('idx_products_tenant_category_ignored');
});

Schema::table('presets', function (Blueprint $table) {
    $table->index(['tenant_id', 'category_id'], 'idx_presets_tenant_category');
});

Schema::table('features', function (Blueprint $table) {
    $table->index(
        ['tenant_id', 'category_id', 'sort_order'],
        'idx_features_category_sort'
    );
    $table->dropIndex('features_tenant_id_category_id_index');
});
```

## Observer Cascade Issue Detail

The `FeatureObserver::created` calls `propagateToDescendants`, which calls `Feature::create()` for each descendant category. Each of those `Feature::create()` calls fires the `created` observer again, recursing into the same descendant tree. With a 3-level category tree (parent -> child -> grandchild):

1. Feature created on parent: observer fires, iterates child + grandchild
2. Feature created on child (by step 1): observer fires, iterates grandchild
3. Feature created on grandchild (by step 2): observer fires, finds no descendants, stops
4. Feature created on grandchild (by step 1): observer fires, finds no descendants, stops

The `exists()` check on line 34 prevents duplicate creation, but the recursive observer still fires unnecessary queries. The fix is wrapping propagated creates in `Feature::withoutEvents()`:

```php
private function propagateToDescendants(Feature $feature): void
{
    $category = $feature->category;
    if (!$category) return;

    $descendants = $category->getAllDescendants();

    Feature::withoutEvents(function () use ($descendants, $feature) {
        foreach ($descendants as $descendant) {
            Feature::firstOrCreate(
                ['category_id' => $descendant->id, 'name' => $feature->name],
                [
                    'slug'      => $feature->slug . '-cat-' . $descendant->id,
                    'data_type' => $feature->data_type,
                    'unit'      => $feature->unit,
                    'weight'    => $feature->weight,
                ]
            );
        }
    });
}
```

## Setting::get() Default Value Bug Detail

Current code:
```php
public static function get(string $key, $default = null)
{
    return Cache::rememberForever(tenant_cache_key("setting:{$key}"), function () use ($key, $default) {
        $setting = static::where('key', $key)->first();
        return $setting ? $setting->value : $default;
    });
}
```

Problem: If `Setting::get('foo', 'bar')` is called first and the key does not exist, the cache stores `'bar'`. If later `Setting::get('foo', 'baz')` is called, it returns `'bar'` (the first default), not `'baz'`. Worse, if the first call uses the implicit `null` default, then all subsequent calls with explicit defaults still get `null`.

Fix:
```php
public static function get(string $key, $default = null)
{
    $value = Cache::rememberForever(
        tenant_cache_key("setting:{$key}"),
        fn () => static::where('key', $key)->value('value')
    );

    return $value ?? $default;
}
```

This caches only the raw DB value (or `null` if missing), then applies the caller's default outside the cache boundary.

## Accessor Architecture Note

The Product model's `bestPrice`, `bestOffer`, `affiliateUrl`, `estimatedPrice`, and `imageUrl` accessors all depend on the `offers` (and `offers.store`) relationship being loaded. Laravel's accessor caching means that within a single request, once `$product->best_price` is accessed, the result is memoized on the model instance. However, the underlying `$this->offers` collection must still be loaded -- either eagerly or via lazy load.

**The fundamental rule for this codebase:** Any query that fetches `Product` models for rendering MUST include `->with(['offers.store'])` in the eager-load list. The five accessors are safe and efficient when this invariant holds; they become N+1 bombs when it does not.

Currently, only `ProblemProducts.php` and `RecalculatePriceTiers.php` correctly eager-load offers. The three critical N+1 issues (C1, C2, C3) are all cases where this invariant is violated.
